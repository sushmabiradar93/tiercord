<?php
/**
 * Admin screens: Memberships → TierCord.
 *
 * Tabs:
 *  - Connection:        Discord application credentials and bot invite link.
 *  - Roles & Levels:    pick servers and the roles TierCord manages, which roles each level gets, and preview channel visibility.
 *  - Members & Log:     linked members, per-member sync, "sync all now", activity log.
 *
 * @package TierCord
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings screens for the Discord access part of TierCord.
 */
class TierCord_Settings {

	const SLUG = 'tiercord';
	const CAP  = 'manage_options';

	// Manage Roles (1<<28) + Kick Members (1<<1) + Create Instant Invite (1<<0), plus View Channels (1<<10),
	// Read Message History (1<<16) and Add Reactions (1<<6) for the optional announcement bot.
	const BOT_PERMISSIONS = '268502083';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_tiercord_save_settings', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_tiercord_refresh_servers', array( __CLASS__, 'refresh_servers' ) );
		add_action( 'admin_post_tiercord_sync_all', array( __CLASS__, 'sync_all' ) );
		add_action( 'admin_post_tiercord_admin_sync_user', array( __CLASS__, 'admin_sync_user' ) );
		add_action( 'admin_post_tiercord_admin_unlink_user', array( __CLASS__, 'admin_unlink_user' ) );
		add_action( 'admin_post_tiercord_clear_log', array( __CLASS__, 'clear_log' ) );

		add_action( 'show_user_profile', array( __CLASS__, 'user_profile_panel' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'user_profile_panel' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( TIERCORD_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function page_url( $tab = 'connection', $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Settings', 'tiercord' ) . '</a>' );
		return $links;
	}

	/**
	 * Load admin CSS/JS on TierCord screens and the user profile screen.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, 'tiercord' ) && ! in_array( $hook_suffix, array( 'profile.php', 'user-edit.php', 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'tiercord-admin', TIERCORD_URL . 'assets/admin.css', array(), TIERCORD_VERSION );
		wp_enqueue_script( 'tiercord-admin', TIERCORD_URL . 'assets/admin.js', array(), TIERCORD_VERSION, true );
	}

	public static function menu() {
		add_submenu_page(
			'pmpro-dashboard',
			__( 'TierCord – Discord Access', 'tiercord' ),
			__( 'TierCord', 'tiercord' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/* ------------------------------------------------------------------
	 * Discord discovery (cached)
	 * ------------------------------------------------------------------ */

	/**
	 * Servers the bot has joined: [ guild_id => name ].
	 *
	 * @return array|WP_Error
	 */
	public static function bot_guilds( $force = false ) {
		$cached = get_transient( 'tiercord_bot_guilds' );
		if ( ! $force && is_array( $cached ) ) {
			return $cached;
		}
		$res = TierCord_API::bot_guilds();
		if ( ! TierCord_API::ok( $res ) ) {
			return new WP_Error( 'tiercord', $res['error'] ? $res['error'] : __( 'Could not reach Discord.', 'tiercord' ) );
		}
		$out = array();
		foreach ( (array) $res['body'] as $g ) {
			$out[ (string) $g['id'] ] = $g['name'];
		}
		set_transient( 'tiercord_bot_guilds', $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * Roles in a server: assignable roles, every role's permissions and the bot's highest role position.
	 *
	 * @param string $guild_id Server ID.
	 * @param bool   $force    Skip the cache.
	 * @return array|WP_Error [ 'roles' => [ id => [ name, position ] ], 'perms' => [ id => permissions ], 'bot_top' => int ]
	 */
	public static function guild_roles( $guild_id, $force = false ) {
		$key    = 'tiercord_roles_' . $guild_id;
		$cached = get_transient( $key );
		if ( ! $force && is_array( $cached ) && isset( $cached['perms'] ) ) {
			return $cached;
		}

		$res = TierCord_API::guild_roles( $guild_id );
		if ( ! TierCord_API::ok( $res ) ) {
			return new WP_Error( 'tiercord', $res['error'] );
		}

		$positions = array();
		$perms     = array();
		$roles     = array();
		foreach ( (array) $res['body'] as $r ) {
			$positions[ (string) $r['id'] ] = (int) $r['position'];
			$perms[ (string) $r['id'] ]     = isset( $r['permissions'] ) ? (string) $r['permissions'] : '0';
			// Skip @everyone (id == guild id) and integration-managed roles (bots, boosts).
			if ( (string) $r['id'] === (string) $guild_id || ! empty( $r['managed'] ) ) {
				continue;
			}
			$roles[ (string) $r['id'] ] = array(
				'name'     => $r['name'],
				'position' => (int) $r['position'],
			);
		}
		uasort(
			$roles,
			function ( $a, $b ) {
				return $b['position'] - $a['position'];
			}
		);

		// Work out the bot's highest role so we can warn about hierarchy problems.
		$bot_top = 0;
		$bot     = get_transient( 'tiercord_bot_user_id' );
		if ( ! $bot ) {
			$me  = TierCord_API::bot_user();
			$bot = TierCord_API::ok( $me ) && ! empty( $me['body']['id'] ) ? (string) $me['body']['id'] : '';
			if ( $bot ) {
				set_transient( 'tiercord_bot_user_id', $bot, DAY_IN_SECONDS );
			}
		}
		if ( $bot ) {
			$member = TierCord_API::get_member( $guild_id, $bot );
			if ( TierCord_API::ok( $member ) && ! empty( $member['body']['roles'] ) ) {
				foreach ( $member['body']['roles'] as $rid ) {
					$bot_top = max( $bot_top, isset( $positions[ $rid ] ) ? $positions[ $rid ] : 0 );
				}
			}
		}

		$out = array(
			'roles'   => $roles,
			'perms'   => $perms,
			'bot_top' => $bot_top,
		);
		set_transient( $key, $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	public static function refresh_servers() {
		self::require_cap();
		check_admin_referer( 'tiercord_refresh_servers' );
		$guilds = self::bot_guilds( true );
		if ( ! is_wp_error( $guilds ) ) {
			foreach ( array_keys( $guilds ) as $gid ) {
				delete_transient( 'tiercord_roles_' . $gid );
				delete_transient( 'tiercord_channels_' . $gid );
			}
		}
		wp_safe_redirect( self::page_url( 'servers', array( 'tiercord_notice' => 'refreshed' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Saving
	 * ------------------------------------------------------------------ */

	/**
	 * Stop unless the current user may manage TierCord.
	 */
	private static function require_cap() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'tiercord' ) );
		}
	}

	private static function digits( $value ) {
		return preg_replace( '/\D/', '', (string) $value );
	}

	public static function save() {
		self::require_cap();
		check_admin_referer( 'tiercord_save_settings' );

		// Every field is sanitised individually below (IDs to digits, text with sanitize_text_field()).
		$post     = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$tab      = isset( $post['tab'] ) ? sanitize_key( $post['tab'] ) : 'connection';
		$settings = get_option( TierCord_Helpers::OPTION, array() );
		$settings = wp_parse_args( is_array( $settings ) ? $settings : array(), TierCord_Helpers::defaults() );
		$resync   = false;

		if ( 'connection' === $tab ) {
			$settings['client_id'] = self::digits( $post['client_id'] ?? '' );
			// Secrets: blank means "keep the saved value".
			if ( ! empty( $post['client_secret'] ) ) {
				$settings['client_secret'] = trim( sanitize_text_field( $post['client_secret'] ) );
			}
			if ( ! empty( $post['bot_token'] ) ) {
				$settings['bot_token'] = trim( sanitize_text_field( $post['bot_token'] ) );
				delete_transient( 'tiercord_bot_guilds' );
				delete_transient( 'tiercord_bot_user_id' );
			}
			$settings['on_expire']            = ( isset( $post['on_expire'] ) && 'kick' === $post['on_expire'] ) ? 'kick' : 'remove_role';
			$settings['disconnect_on_delete'] = empty( $post['disconnect_on_delete'] ) ? 0 : 1;
			$settings['account_page']         = empty( $post['account_page'] ) ? 0 : 1;
			$settings['emails_enabled']       = empty( $post['emails_enabled'] ) ? 0 : 1;
		}

		if ( 'servers' === $tab ) {
			$old_servers = $settings['servers'];
			$old_map     = $settings['level_map'];

			$servers = array();
			foreach ( (array) ( $post['servers'] ?? array() ) as $gid => $row ) {
				$gid = self::digits( $gid );
				if ( '' === $gid || ! is_array( $row ) ) {
					continue;
				}
				$names = isset( $row['role_names'] ) && is_array( $row['role_names'] ) ? $row['role_names'] : array();
				$roles = array();
				foreach ( (array) ( $row['roles'] ?? array() ) as $rid ) {
					$rid = self::digits( $rid );
					if ( '' !== $rid ) {
						$roles[ $rid ] = isset( $names[ $rid ] ) ? sanitize_text_field( $names[ $rid ] ) : $rid;
					}
				}
				$servers[ $gid ] = array(
					'enabled' => empty( $row['enabled'] ) ? 0 : 1,
					'name'    => sanitize_text_field( $row['name'] ?? $gid ),
					'roles'   => $roles,
				);
			}

			// Only keep level → role ticks for roles that are still managed in an enabled server.
			$map = array();
			foreach ( (array) ( $post['level_map'] ?? array() ) as $level_id => $keys ) {
				$level_id = absint( $level_id );
				if ( ! $level_id ) {
					continue;
				}
				foreach ( (array) $keys as $key ) {
					$parsed = TierCord_Helpers::parse_role_key( sanitize_text_field( $key ) );
					if ( $parsed && ! empty( $servers[ $parsed[0] ]['enabled'] ) && isset( $servers[ $parsed[0] ]['roles'][ $parsed[1] ] ) ) {
						$map[ $level_id ][] = TierCord_Helpers::role_key( $parsed[0], $parsed[1] );
					}
				}
			}

			$settings['servers']   = $servers;
			$settings['level_map'] = $map;

			$resync = ( wp_json_encode( $old_servers ) !== wp_json_encode( $servers ) ) || ( wp_json_encode( $old_map ) !== wp_json_encode( $map ) );
		}

		update_option( TierCord_Helpers::OPTION, $settings );

		$notice = 'saved';
		if ( $resync && TierCord_Helpers::is_configured() ) {
			TierCord_Sync::start_full_resync();
			$notice = 'saved_resync';
		}

		wp_safe_redirect( self::page_url( $tab, array( 'tiercord_notice' => $notice ) ) );
		exit;
	}

	public static function sync_all() {
		self::require_cap();
		check_admin_referer( 'tiercord_sync_all' );
		TierCord_Sync::start_full_resync();
		spawn_cron();
		wp_safe_redirect( self::page_url( 'members', array( 'tiercord_notice' => 'sync_started' ) ) );
		exit;
	}

	public static function admin_sync_user() {
		self::require_cap();
		check_admin_referer( 'tiercord_admin_sync_user' );
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		TierCord_Sync::sync_user( $user_id );
		$back = wp_get_referer() ? wp_get_referer() : self::page_url( 'members' );
		wp_safe_redirect( add_query_arg( 'tiercord_notice', 'user_synced', $back ) );
		exit;
	}

	public static function admin_unlink_user() {
		self::require_cap();
		check_admin_referer( 'tiercord_admin_unlink_user' );
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		TierCord_OAuth::unlink_user( $user_id );
		$back = wp_get_referer() ? wp_get_referer() : self::page_url( 'members' );
		wp_safe_redirect( add_query_arg( 'tiercord_notice', 'user_unlinked', $back ) );
		exit;
	}

	public static function clear_log() {
		self::require_cap();
		check_admin_referer( 'tiercord_clear_log' );
		delete_option( TierCord_Helpers::LOG );
		wp_safe_redirect( self::page_url( 'members', array( 'tiercord_notice' => 'log_cleared' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connection'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$tabs = array(
			'connection' => __( 'Connection', 'tiercord' ),
			'servers'    => __( 'Roles & Levels', 'tiercord' ),
			'members'    => __( 'Members & Log', 'tiercord' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'connection';
		}
		?>
		<div class="wrap tiercord-wrap">
			<h1><?php esc_html_e( 'TierCord – Discord Access', 'tiercord' ); ?></h1>
			<?php self::notice(); ?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::page_url( $key ) ); ?>" class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="tiercord-tab-body">
				<?php
				switch ( $tab ) {
					case 'servers':
						self::render_servers();
						break;
					case 'members':
						self::render_members();
						break;
					default:
						self::render_connection();
				}
				?>
			</div>
		</div>
		<?php
	}

	private static function notice() {
		$n = isset( $_GET['tiercord_notice'] ) ? sanitize_key( wp_unslash( $_GET['tiercord_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice.
		$messages = array(
			'saved'         => __( 'Settings saved.', 'tiercord' ),
			'saved_resync'  => __( 'Settings saved. All linked members are being re-synced in the background.', 'tiercord' ),
			'refreshed'     => __( 'Server and role lists refreshed from Discord.', 'tiercord' ),
			'sync_started'  => __( 'Full resync started. It runs in the background in batches of 25 members per minute.', 'tiercord' ),
			'user_synced'   => __( 'Member synced.', 'tiercord' ),
			'user_unlinked' => __( 'Discord account unlinked and access removed.', 'tiercord' ),
			'log_cleared'   => __( 'Log cleared.', 'tiercord' ),
		);
		if ( isset( $messages[ $n ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $n ] ) . '</p></div>';
		}
	}

	/* ---------------- Connection tab ---------------- */

	private static function render_connection() {
		$s          = TierCord_Helpers::settings();
		$has_secret = '' !== $s['client_secret'];
		$has_token  = '' !== $s['bot_token'];
		?>
		<div class="tiercord-card">
			<h2><?php esc_html_e( 'Set up your Discord application', 'tiercord' ); ?></h2>
			<ol class="tiercord-steps">
				<li><?php echo wp_kses_post( __( 'Go to the <a href="https://discord.com/developers/applications" target="_blank" rel="noopener">Discord Developer Portal</a> and create a New Application.', 'tiercord' ) ); ?></li>
				<li><?php esc_html_e( 'OAuth2 page: copy the Client ID and Client Secret into the fields below, and add this Redirect URL:', 'tiercord' ); ?><br><code class="tiercord-copy"><?php echo esc_html( TierCord_Helpers::redirect_uri() ); ?></code></li>
				<li><?php esc_html_e( 'Bot page: click Reset Token, copy the bot token into the field below. Turn OFF "Public Bot".', 'tiercord' ); ?></li>
				<li><?php esc_html_e( 'Save, then use the "Invite bot" button to add the bot to EACH of your servers.', 'tiercord' ); ?></li>
				<li><?php esc_html_e( 'In each server: Server Settings → Roles, drag the bot\'s role ABOVE every role it will hand out (e.g. Basic, Pro, Premium).', 'tiercord' ); ?></li>
				<li><?php esc_html_e( 'For each private channel or category: Edit → Permissions, turn off "View Channel" for @everyone and turn it on for the roles that should see it.', 'tiercord' ); ?></li>
			</ol>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tiercord-card">
			<input type="hidden" name="action" value="tiercord_save_settings">
			<input type="hidden" name="tab" value="connection">
			<?php wp_nonce_field( 'tiercord_save_settings' ); ?>
			<h2><?php esc_html_e( 'Credentials', 'tiercord' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="tiercord_client_id"><?php esc_html_e( 'Client ID', 'tiercord' ); ?></label></th>
					<td><input type="text" id="tiercord_client_id" name="client_id" class="regular-text" value="<?php echo esc_attr( $s['client_id'] ); ?>" <?php disabled( defined( 'TIERCORD_CLIENT_ID' ) ); ?>></td>
				</tr>
				<tr>
					<th scope="row"><label for="tiercord_client_secret"><?php esc_html_e( 'Client Secret', 'tiercord' ); ?></label></th>
					<td><input type="password" id="tiercord_client_secret" name="client_secret" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_secret ? esc_attr__( 'Saved — leave blank to keep', 'tiercord' ) : ''; ?>" <?php disabled( defined( 'TIERCORD_CLIENT_SECRET' ) ); ?>></td>
				</tr>
				<tr>
					<th scope="row"><label for="tiercord_bot_token"><?php esc_html_e( 'Bot Token', 'tiercord' ); ?></label></th>
					<td>
						<input type="password" id="tiercord_bot_token" name="bot_token" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_token ? esc_attr__( 'Saved — leave blank to keep', 'tiercord' ) : ''; ?>" <?php disabled( defined( 'TIERCORD_BOT_TOKEN' ) ); ?>>
						<p class="description"><?php esc_html_e( 'Tip: for extra safety you can define TIERCORD_CLIENT_ID, TIERCORD_CLIENT_SECRET and TIERCORD_BOT_TOKEN in wp-config.php instead.', 'tiercord' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'When access ends', 'tiercord' ); ?></th>
					<td>
						<label><input type="radio" name="on_expire" value="remove_role" <?php checked( 'kick' !== $s['on_expire'] ); ?>> <?php esc_html_e( 'Remove the member\'s roles (recommended — they stay in the server but lose the gated channels; renewal restores them instantly)', 'tiercord' ); ?></label><br>
						<label><input type="radio" name="on_expire" value="kick" <?php checked( 'kick', $s['on_expire'] ); ?>> <?php esc_html_e( 'Remove the user from the server (they are added back automatically on renewal)', 'tiercord' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Deleted users', 'tiercord' ); ?></th>
					<td><label><input type="checkbox" name="disconnect_on_delete" value="1" <?php checked( $s['disconnect_on_delete'] ); ?>> <?php esc_html_e( 'Remove Discord access when a WordPress user is deleted', 'tiercord' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Member area', 'tiercord' ); ?></th>
					<td>
						<label><input type="checkbox" name="account_page" value="1" <?php checked( $s['account_page'] ); ?>> <?php esc_html_e( 'Show the "Connect Discord" box at the bottom of the PMPro Membership Account page', 'tiercord' ); ?></label>
						<p class="description"><?php esc_html_e( 'You can also place it anywhere with the shortcode', 'tiercord' ); ?> <code>[tiercord]</code></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Email notifications', 'tiercord' ); ?></th>
					<td>
						<label><input type="checkbox" name="emails_enabled" value="1" <?php checked( $s['emails_enabled'] ); ?>> <?php esc_html_e( 'Enable the email module (new-post emails, announcements, Discord access emails)', 'tiercord' ); ?></label>
						<p class="description"><?php esc_html_e( 'Configure it under Memberships → TierCord Emails.', 'tiercord' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save connection settings', 'tiercord' ) ); ?>
		</form>

		<?php if ( $s['client_id'] ) : ?>
			<div class="tiercord-card">
				<h2><?php esc_html_e( 'Add the bot to your servers', 'tiercord' ); ?></h2>
				<p><?php esc_html_e( 'Open this link once for each server you want to manage. It asks for Manage Roles, Kick Members and Create Invite, plus View Channels, Read Message History and Add Reactions for the optional announcement bot.', 'tiercord' ); ?></p>
				<p>
					<a class="button button-primary" target="_blank" rel="noopener" href="<?php echo esc_url( 'https://discord.com/oauth2/authorize?client_id=' . rawurlencode( $s['client_id'] ) . '&scope=bot&permissions=' . self::BOT_PERMISSIONS ); ?>"><?php esc_html_e( 'Invite bot to a server', 'tiercord' ); ?></a>
					<a class="button" href="<?php echo esc_url( self::page_url( 'servers' ) ); ?>"><?php esc_html_e( 'Next: choose roles & levels →', 'tiercord' ); ?></a>
				</p>
			</div>
		<?php endif; ?>
		<?php
	}

	/* ---------------- Roles & Levels tab ---------------- */

	private static function render_servers() {
		if ( ! TierCord_Helpers::is_configured() ) {
			echo '<div class="tiercord-card"><p>' . esc_html__( 'Save your Client ID, Client Secret and Bot Token on the Connection tab first.', 'tiercord' ) . '</p></div>';
			return;
		}

		$saved  = (array) TierCord_Helpers::get( 'servers' );
		$map    = (array) TierCord_Helpers::get( 'level_map' );
		$guilds = self::bot_guilds();
		$levels = pmpro_getAllLevels( true, true );

		if ( is_wp_error( $guilds ) ) {
			/* translators: %s: error message returned by Discord. */
			$message = sprintf( __( 'Could not load servers from Discord: %s. Check the bot token.', 'tiercord' ), $guilds->get_error_message() );
			echo '<div class="notice notice-error inline"><p>' . esc_html( $message ) . '</p></div>';
			$guilds = array();
		}

		// Keep saved servers visible even if the bot was removed from them, so they can be disabled cleanly.
		$missing = array();
		foreach ( $saved as $gid => $srv ) {
			if ( ! isset( $guilds[ $gid ] ) ) {
				$guilds[ $gid ]            = $srv['name'];
				$missing[ (string) $gid ] = true;
			}
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="tiercord_save_settings">
			<input type="hidden" name="tab" value="servers">
			<?php wp_nonce_field( 'tiercord_save_settings' ); ?>

			<div class="tiercord-card">
				<h2><?php esc_html_e( '1. Servers and the roles TierCord manages', 'tiercord' ); ?></h2>
				<p><?php esc_html_e( 'Tick each server to gate, then the roles TierCord should hand out and take back there. Use one server with a role per tier (e.g. Basic, Pro, Premium) to open different channels, several servers, or both.', 'tiercord' ); ?></p>
				<p class="description"><?php esc_html_e( 'Only the roles ticked here are ever added or removed. Roles you give by hand (moderators, boosters…) are never touched.', 'tiercord' ); ?></p>

				<?php if ( empty( $guilds ) ) : ?>
					<p><strong><?php esc_html_e( 'The bot is not in any server yet. Use the "Invite bot" button on the Connection tab, then refresh.', 'tiercord' ); ?></strong></p>
				<?php else : ?>
					<table class="widefat striped tiercord-servers-table">
						<thead>
							<tr>
								<th class="column-cb"><?php esc_html_e( 'Manage', 'tiercord' ); ?></th>
								<th><?php esc_html_e( 'Server', 'tiercord' ); ?></th>
								<th><?php esc_html_e( 'Roles TierCord hands out', 'tiercord' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php
						foreach ( $guilds as $gid => $gname ) :
							$gid      = (string) $gid;
							$row      = isset( $saved[ $gid ] ) ? $saved[ $gid ] : array();
							$enabled  = ! empty( $row['enabled'] );
							$chosen   = isset( $row['roles'] ) && is_array( $row['roles'] ) ? $row['roles'] : array();
							$roles    = isset( $missing[ $gid ] ) ? new WP_Error( 'tiercord', __( 'The bot is no longer in this server.', 'tiercord' ) ) : self::guild_roles( $gid );
							$field    = 'servers[' . $gid . ']';
							?>
							<tr>
								<td><input type="checkbox" name="<?php echo esc_attr( $field ); ?>[enabled]" value="1" <?php checked( $enabled ); ?> aria-label="<?php echo esc_attr( $gname ); ?>"></td>
								<td>
									<strong><?php echo esc_html( $gname ); ?></strong><br>
									<code><?php echo esc_html( $gid ); ?></code>
									<input type="hidden" name="<?php echo esc_attr( $field ); ?>[name]" value="<?php echo esc_attr( $gname ); ?>">
								</td>
								<td>
									<?php if ( is_wp_error( $roles ) ) : ?>
										<span class="tiercord-warn"><?php echo esc_html( $roles->get_error_message() ); ?></span>
										<?php foreach ( $chosen as $rid => $rname ) : ?>
											<input type="hidden" name="<?php echo esc_attr( $field ); ?>[roles][]" value="<?php echo esc_attr( $rid ); ?>">
											<input type="hidden" name="<?php echo esc_attr( $field . '[role_names][' . $rid . ']' ); ?>" value="<?php echo esc_attr( $rname ); ?>">
										<?php endforeach; ?>
									<?php elseif ( empty( $roles['roles'] ) ) : ?>
										<em><?php esc_html_e( 'No roles yet. Create roles in Discord (Server Settings → Roles), then refresh.', 'tiercord' ); ?></em>
									<?php else : ?>
										<div class="tiercord-role-list">
										<?php
										foreach ( $roles['roles'] as $rid => $r ) :
											$rid       = (string) $rid;
											$too_high  = $roles['bot_top'] > 0 && $r['position'] >= $roles['bot_top'];
											$is_chosen = isset( $chosen[ $rid ] );
											?>
											<label class="<?php echo $too_high ? 'tiercord-role-disabled' : ''; ?>">
												<input type="checkbox" name="<?php echo esc_attr( $field ); ?>[roles][]" value="<?php echo esc_attr( $rid ); ?>" <?php checked( $is_chosen ); ?> <?php disabled( $too_high && ! $is_chosen ); ?>>
												<?php echo esc_html( $r['name'] ); ?>
												<?php if ( $too_high ) : ?>
													<span class="tiercord-warn"><?php esc_html_e( '(above the bot\'s role — drag the bot\'s role higher in Discord)', 'tiercord' ); ?></span>
												<?php endif; ?>
											</label>
											<input type="hidden" name="<?php echo esc_attr( $field . '[role_names][' . $rid . ']' ); ?>" value="<?php echo esc_attr( $r['name'] ); ?>">
										<?php endforeach; ?>
										</div>
										<?php if ( $enabled && ! $chosen ) : ?>
											<p class="tiercord-warn"><?php esc_html_e( 'Tick at least one role or this server will be ignored.', 'tiercord' ); ?></p>
										<?php endif; ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tiercord_refresh_servers' ), 'tiercord_refresh_servers' ) ); ?>"><?php esc_html_e( 'Refresh servers, roles & channels from Discord', 'tiercord' ); ?></a></p>
				<?php endif; ?>
			</div>

			<div class="tiercord-card">
				<h2><?php esc_html_e( '2. Which roles each membership level gets', 'tiercord' ); ?></h2>
				<p><?php esc_html_e( 'Tick the roles for each level. For stacked tiers in one server, either give higher levels the lower tiers\' roles too (Premium: Basic + Pro + Premium), or give one role per level and allow the higher roles on the lower channels in Discord.', 'tiercord' ); ?></p>
				<?php
				$active = TierCord_Helpers::active_servers();
				if ( empty( $levels ) ) {
					echo '<p>' . esc_html__( 'No membership levels found. Create levels in Memberships → Settings → Levels.', 'tiercord' ) . '</p>';
				} elseif ( empty( $active ) ) {
					echo '<p><em>' . esc_html__( 'Tick a server and at least one of its roles above, then save to see the grid.', 'tiercord' ) . '</em></p>';
				} else {
					?>
					<table class="widefat striped tiercord-matrix">
						<thead>
							<?php if ( count( $active ) > 1 ) : ?>
								<tr>
									<th></th>
									<?php foreach ( $active as $gid => $srv ) : ?>
										<th colspan="<?php echo (int) count( $srv['roles'] ); ?>" class="tiercord-matrix-server"><?php echo esc_html( $srv['name'] ); ?></th>
									<?php endforeach; ?>
								</tr>
							<?php endif; ?>
							<tr>
								<th><?php esc_html_e( 'Membership level', 'tiercord' ); ?></th>
								<?php foreach ( $active as $gid => $srv ) : ?>
									<?php foreach ( $srv['roles'] as $rid => $rname ) : ?>
										<th><?php echo esc_html( $rname ); ?></th>
									<?php endforeach; ?>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $levels as $level ) : ?>
							<?php $ticked = isset( $map[ $level->id ] ) ? array_map( 'strval', (array) $map[ $level->id ] ) : array(); ?>
							<tr>
								<td><strong><?php echo esc_html( $level->name ); ?></strong> <span class="description">(#<?php echo (int) $level->id; ?>)</span></td>
								<?php foreach ( $active as $gid => $srv ) : ?>
									<?php foreach ( $srv['roles'] as $rid => $rname ) : ?>
										<?php $key = TierCord_Helpers::role_key( $gid, $rid ); ?>
										<td><input type="checkbox" aria-label="<?php echo esc_attr( $level->name . ' → ' . $rname ); ?>" name="level_map[<?php echo (int) $level->id; ?>][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $ticked, true ) ); ?>></td>
									<?php endforeach; ?>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php
				}
				?>
			</div>

			<?php submit_button( __( 'Save roles & levels', 'tiercord' ) ); ?>
			<p class="description"><?php esc_html_e( 'Saving changes here re-syncs every linked member in the background.', 'tiercord' ); ?></p>
		</form>

		<?php self::render_channel_preview(); ?>
		<?php
	}

	/**
	 * "Who can see which channel" — read-only check of the Discord channel permissions.
	 */
	private static function render_channel_preview() {
		$active = TierCord_Helpers::active_servers();
		if ( ! $active ) {
			return;
		}
		?>
		<div class="tiercord-card">
			<h2><?php esc_html_e( '3. Who can see which channel', 'tiercord' ); ?></h2>
			<p><?php esc_html_e( 'Read from your Discord channel permissions, so you can check the setup without opening Discord. To change it, edit the channel (or its category) in Discord → Permissions.', 'tiercord' ); ?></p>
			<?php foreach ( $active as $gid => $srv ) : ?>
				<?php $rows = TierCord_Permissions::overview( $gid ); ?>
				<?php if ( count( $active ) > 1 ) : ?>
					<h3><?php echo esc_html( $srv['name'] ); ?></h3>
				<?php endif; ?>
				<?php if ( is_wp_error( $rows ) ) : ?>
					<p class="tiercord-warn"><?php echo esc_html( $rows->get_error_message() ); ?></p>
				<?php elseif ( ! $rows ) : ?>
					<p><em><?php esc_html_e( 'No channels found.', 'tiercord' ); ?></em></p>
				<?php else : ?>
					<table class="widefat striped tiercord-channels">
						<thead><tr><th><?php esc_html_e( 'Channel', 'tiercord' ); ?></th><th><?php esc_html_e( 'Visible to', 'tiercord' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr class="<?php echo $row['is_category'] ? 'tiercord-category' : ''; ?>">
								<td>
									<?php
									$prefix = $row['is_category'] ? '' : ( in_array( (int) ( $row['channel']['type'] ?? 0 ), array( TierCord_Permissions::TYPE_VOICE, TierCord_Permissions::TYPE_STAGE ), true ) ? '🔊 ' : '# ' );
									echo esc_html( $prefix . $row['name'] );
									?>
								</td>
								<td>
									<?php if ( $row['public'] ) : ?>
										<span class="tiercord-pill"><?php esc_html_e( 'Everyone in the server (not gated)', 'tiercord' ); ?></span>
									<?php elseif ( $row['roles'] ) : ?>
										<?php foreach ( $row['roles'] as $rid ) : ?>
											<span class="tiercord-pill active"><?php echo esc_html( $srv['roles'][ $rid ] ?? $rid ); ?></span>
										<?php endforeach; ?>
									<?php else : ?>
										<span class="description"><?php esc_html_e( 'No TierCord role (staff only)', 'tiercord' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endforeach; ?>
			<p class="description"><?php esc_html_e( 'Updated every 10 minutes, or straight away with the Refresh button above. Roles are checked one at a time, as a member holding only that role.', 'tiercord' ); ?></p>
		</div>
		<?php
	}

	/* ---------------- Members & Log tab ---------------- */

	public static function status_pills( $user_id ) {
		$status  = get_user_meta( $user_id, 'tiercord_status', true );
		$servers = TierCord_Helpers::active_servers();
		if ( ! is_array( $status ) || empty( $status ) ) {
			return '<span class="tiercord-pill">' . esc_html__( 'not synced yet', 'tiercord' ) . '</span>';
		}
		$labels = array(
			'active'          => __( 'access', 'tiercord' ),
			'paused'          => __( 'paused', 'tiercord' ),
			'none'            => __( 'no access', 'tiercord' ),
			'error'           => __( 'error', 'tiercord' ),
			'needs_reconnect' => __( 'needs reconnect', 'tiercord' ),
		);
		$html = '';
		foreach ( $status as $gid => $st ) {
			$name  = isset( $servers[ $gid ] ) ? $servers[ $gid ]['name'] : $gid;
			$state = isset( $st['state'] ) ? $st['state'] : 'none';
			$title = ! empty( $st['message'] ) ? ' title="' . esc_attr( $st['message'] ) . '"' : '';
			$text  = $labels[ $state ] ?? $state;
			if ( 'active' === $state && ! empty( $st['roles'] ) ) {
				$names = array();
				foreach ( $st['roles'] as $rid ) {
					$names[] = isset( $servers[ $gid ]['roles'][ $rid ] ) ? $servers[ $gid ]['roles'][ $rid ] : $rid;
				}
				$text = implode( ', ', $names );
			}
			$html .= '<span class="tiercord-pill ' . esc_attr( $state ) . '"' . $title . '>' . esc_html( $name . ': ' . $text ) . '</span>';
		}
		return $html;
	}

	private static function render_members() {
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
		$per_page = 30;
		$query    = new WP_User_Query(
			array(
				'meta_key'     => 'tiercord_discord_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small, indexed lookup.
				'meta_compare' => 'EXISTS',
				'number'       => $per_page,
				'paged'        => $paged,
				'orderby'      => 'registered',
				'order'        => 'DESC',
			)
		);
		$total    = (int) $query->get_total();
		$started  = (int) get_option( 'tiercord_resync_started' );
		$finished = (int) get_option( 'tiercord_resync_finished' );
		?>
		<div class="tiercord-card">
			<?php
			/* translators: %d: number of members who connected Discord. */
			$heading = sprintf( _n( '%d linked member', '%d linked members', $total, 'tiercord' ), $total );
			?>
			<h2><?php echo esc_html( $heading ); ?></h2>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tiercord_sync_all' ), 'tiercord_sync_all' ) ); ?>"><?php esc_html_e( 'Sync all members now', 'tiercord' ); ?></a>
				<span class="description">
					<?php
					if ( $started ) {
						echo esc_html(
							sprintf(
								/* translators: 1: date, 2: date or "running" */
								__( 'Last full resync started %1$s, finished %2$s. A full resync also runs automatically once a day.', 'tiercord' ),
								wp_date( 'j M Y H:i', $started ),
								$finished >= $started ? wp_date( 'j M Y H:i', $finished ) : __( '(still running)', 'tiercord' )
							)
						);
					}
					?>
				</span>
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'User', 'tiercord' ); ?></th>
						<th><?php esc_html_e( 'Discord', 'tiercord' ); ?></th>
						<th><?php esc_html_e( 'Levels', 'tiercord' ); ?></th>
						<th><?php esc_html_e( 'Server access', 'tiercord' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'tiercord' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $query->get_results() ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No members have connected Discord yet.', 'tiercord' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $query->get_results() as $user ) : ?>
					<?php
					$names = TierCord_Helpers::user_level_names( $user->ID );
					$names = $names ? $names : array( '—' );
					?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><?php echo esc_html( $user->display_name ); ?></a><br><span class="description"><?php echo esc_html( $user->user_email ); ?></span></td>
						<td><?php echo esc_html( get_user_meta( $user->ID, 'tiercord_discord_username', true ) ); ?><?php echo get_user_meta( $user->ID, 'tiercord_needs_reconnect', true ) ? '<br><span class="tiercord-warn">' . esc_html__( 'Authorisation expired', 'tiercord' ) . '</span>' : ''; ?></td>
						<td><?php echo esc_html( implode( ', ', $names ) ); ?></td>
						<td><?php echo wp_kses_post( self::status_pills( $user->ID ) ); ?></td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tiercord_admin_sync_user&user_id=' . $user->ID ), 'tiercord_admin_sync_user' ) ); ?>"><?php esc_html_e( 'Sync', 'tiercord' ); ?></a>
							<a class="button button-small" data-tiercord-confirm="<?php echo esc_attr( __( 'Unlink this Discord account and remove its TierCord roles?', 'tiercord' ) ); ?>\" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tiercord_admin_unlink_user&user_id=' . $user->ID ), 'tiercord_admin_unlink_user' ) ); ?>"><?php esc_html_e( 'Unlink', 'tiercord' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$pages = (int) ceil( $total / $per_page );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%', self::page_url( 'members' ) ),
							'format'  => '',
							'current' => $paged,
							'total'   => $pages,
						)
					)
				);
				echo '</div></div>';
			}
			?>
		</div>

		<div class="tiercord-card">
			<h2><?php esc_html_e( 'Activity log', 'tiercord' ); ?></h2>
			<?php $log = get_option( TierCord_Helpers::LOG, array() ); ?>
			<?php if ( empty( $log ) ) : ?>
				<p><?php esc_html_e( 'Nothing logged yet.', 'tiercord' ); ?></p>
			<?php else : ?>
				<table class="widefat striped tiercord-log">
					<thead><tr><th style="width:140px"><?php esc_html_e( 'Time', 'tiercord' ); ?></th><th style="width:160px"><?php esc_html_e( 'User', 'tiercord' ); ?></th><th><?php esc_html_e( 'Message', 'tiercord' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $log as $entry ) : ?>
						<?php $u = $entry['user_id'] ? get_userdata( $entry['user_id'] ) : null; ?>
						<tr class="<?php echo esc_attr( $entry['level'] ); ?>">
							<td><?php echo esc_html( wp_date( 'j M Y H:i:s', $entry['time'] ) ); ?></td>
							<td><?php echo $u ? esc_html( $u->display_name ) : ( $entry['user_id'] ? '#' . (int) $entry['user_id'] : '—' ); ?></td>
							<td class="<?php echo esc_attr( $entry['level'] ); ?>"><?php echo esc_html( $entry['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tiercord_clear_log' ), 'tiercord_clear_log' ) ); ?>"><?php esc_html_e( 'Clear log', 'tiercord' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---------------- User profile panel (admins) ---------------- */

	public static function user_profile_panel( $user ) {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'TierCord – Discord Access', 'tiercord' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Discord account', 'tiercord' ); ?></th>
				<td>
					<?php if ( TierCord_Helpers::is_linked( $user->ID ) ) : ?>
						<?php echo esc_html( get_user_meta( $user->ID, 'tiercord_discord_username', true ) ); ?>
						<code><?php echo esc_html( TierCord_Helpers::discord_id( $user->ID ) ); ?></code>
						<p><?php echo wp_kses_post( self::status_pills( $user->ID ) ); ?></p>
						<p>
							<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tiercord_admin_sync_user&user_id=' . $user->ID ), 'tiercord_admin_sync_user' ) ); ?>"><?php esc_html_e( 'Sync now', 'tiercord' ); ?></a>
							<a class="button" data-tiercord-confirm="<?php echo esc_attr( __( 'Unlink this Discord account and remove its TierCord roles?', 'tiercord' ) ); ?>\" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tiercord_admin_unlink_user&user_id=' . $user->ID ), 'tiercord_admin_unlink_user' ) ); ?>"><?php esc_html_e( 'Unlink', 'tiercord' ); ?></a>
						</p>
					<?php else : ?>
						<em><?php esc_html_e( 'Not connected', 'tiercord' ); ?></em>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}
}
