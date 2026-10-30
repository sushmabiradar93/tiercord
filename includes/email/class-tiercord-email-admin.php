<?php
/**
 * Admin: Memberships → TierCord Emails (Send announcement · Settings · Templates · History).
 *
 * @package TierCord
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screens for the email module.
 */
class TierCord_Email_Admin {

	const SLUG = 'tiercord-emails';
	const CAP  = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 21 );
		add_action( 'admin_post_tiercord_em_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_tiercord_em_announce', array( __CLASS__, 'announce' ) );
		add_action( 'admin_post_tiercord_em_cancel', array( __CLASS__, 'cancel' ) );
		add_action( 'admin_post_tiercord_em_run_queue', array( __CLASS__, 'run_queue' ) );
		add_action( 'admin_post_tiercord_em_regen_secret', array( __CLASS__, 'regen_secret' ) );
		
	}

	public static function url( $tab = 'compose', $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}


	public static function menu() {
		add_submenu_page( 'pmpro-dashboard', __( 'TierCord Emails', 'tiercord' ), __( 'TierCord Emails', 'tiercord' ), self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
	}

	private static function require_cap() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'tiercord' ) );
		}
	}

	private static function go( $tab, $notice, $extra = array() ) {
		wp_safe_redirect( self::url( $tab, array_merge( array( 'tiercord_em_notice' => $notice ), $extra ) ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------ */

	public static function save() {
		self::require_cap();
		check_admin_referer( 'tiercord_em_save' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is sanitised individually below.
		$p   = wp_unslash( $_POST );
		$tab = isset( $p['tab'] ) ? sanitize_key( $p['tab'] ) : 'settings';
		$s   = TierCord_Email::settings();

		if ( 'settings' === $tab ) {
			$s['from_name']         = sanitize_text_field( $p['from_name'] ?? '' );
			$s['from_email']        = sanitize_email( $p['from_email'] ?? '' );
			$s['brand_color']       = sanitize_hex_color( $p['brand_color'] ?? '' ) ? sanitize_hex_color( $p['brand_color'] ) : '#5865F2';
			$s['footer_text']       = wp_kses_post( $p['footer_text'] ?? '' );
			$s['batch_size']        = min( 500, max( 1, absint( $p['batch_size'] ?? 40 ) ) );
			$s['posts_enabled']     = empty( $p['posts_enabled'] ) ? 0 : 1;
			$s['post_types']        = array_values( array_filter( array_map( 'sanitize_key', (array) ( $p['post_types'] ?? array() ) ) ) );
			$s['notify_default']    = empty( $p['notify_default'] ) ? 0 : 1;
			$s['unrestricted_to']   = ( $p['unrestricted_to'] ?? '' ) === 'none' ? 'none' : 'all_members';
			$s['post_delay']        = min( 1440, max( 1, absint( $p['post_delay'] ?? 2 ) ) );
			$s['welcome_enabled']   = empty( $p['welcome_enabled'] ) ? 0 : 1;
			$s['paused_enabled']    = empty( $p['paused_enabled'] ) ? 0 : 1;
			$s['reconnect_enabled'] = empty( $p['reconnect_enabled'] ) ? 0 : 1;
			$s['bridge_enabled']    = empty( $p['bridge_enabled'] ) ? 0 : 1;
			$s['bridge_channels']   = implode( ', ', array_filter( array_map( function ( $v ) {
				return preg_replace( '/\D/', '', $v );
			}, preg_split( '/[\s,]+/', (string) ( $p['bridge_channels'] ?? '' ) ) ) ) );
		}

		if ( 'templates' === $tab ) {
			foreach ( array( 'post', 'announce', 'discord', 'welcome', 'paused', 'reconnect' ) as $t ) {
				if ( isset( $p[ $t . '_subject' ] ) ) {
					$s[ $t . '_subject' ] = sanitize_text_field( $p[ $t . '_subject' ] );
				}
				if ( isset( $p[ $t . '_body' ] ) ) {
					$s[ $t . '_body' ] = wp_kses_post( $p[ $t . '_body' ] );
				}
			}
			if ( ! empty( $p['reset'] ) ) {
				$d = TierCord_Email::defaults();
				foreach ( array( 'post', 'announce', 'discord', 'welcome', 'paused', 'reconnect' ) as $t ) {
					$s[ $t . '_subject' ] = $d[ $t . '_subject' ];
					$s[ $t . '_body' ]    = $d[ $t . '_body' ];
				}
			}
		}

		update_option( TierCord_Email::OPTION, $s );
		self::go( $tab, 'saved' );
	}

	public static function regen_secret() {
		self::require_cap();
		check_admin_referer( 'tiercord_em_regen_secret' );
		$s                  = TierCord_Email::settings();
		$s['bridge_secret'] = wp_generate_password( 40, false, false );
		update_option( TierCord_Email::OPTION, $s );
		self::go( 'settings', 'secret' );
	}

	public static function announce() {
		self::require_cap();
		check_admin_referer( 'tiercord_em_announce' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- subject/message/IDs are sanitised individually below.
		$p       = wp_unslash( $_POST );
		$subject = sanitize_text_field( $p['subject'] ?? '' );
		$message = wp_kses_post( $p['message'] ?? '' );

		set_transient( 'tiercord_em_draft_' . get_current_user_id(), array( 'subject' => $subject, 'message' => $message, 'p' => $p ), HOUR_IN_SECONDS );

		if ( '' === $subject || '' === trim( wp_strip_all_tags( $message ) ) ) {
			self::go( 'compose', 'empty' );
		}

		$type = $p['audience'] ?? 'levels';
		if ( 'guild' === $type ) {
			$audience = array( 'type' => 'guild', 'guild' => preg_replace( '/\D/', '', $p['guild'] ?? '' ) );
		} elseif ( 'roles' === $type ) {
			$audience = array( 'type' => 'roles', 'roles' => array() );
			foreach ( (array) ( $p['role_keys'] ?? array() ) as $key ) {
				$parsed = TierCord_Helpers::parse_role_key( sanitize_text_field( $key ) );
				if ( $parsed ) {
					$audience['guild']   = $parsed[0];
					$audience['roles'][] = $parsed[1];
				}
			}
		} elseif ( 'all_members' === $type ) {
			$audience = array( 'type' => 'all_members' );
		} else {
			$audience = array( 'type' => 'levels', 'levels' => array_map( 'absint', (array) ( $p['levels'] ?? array() ) ) );
		}

		$s    = TierCord_Email::settings();
		$vars = array(
			'subject' => $subject,
			'message' => wpautop( $message ),
		);

		if ( ! empty( $p['test'] ) ) {
			$ok = TierCord_Mailer::send( wp_get_current_user(), $s['announce_subject'], $s['announce_body'], $vars, 'announcements' );
			self::go( 'compose', $ok ? 'test_sent' : 'test_failed' );
		}

		if ( 0 === TierCord_Queue::count_audience( $audience ) ) {
			self::go( 'compose', 'no_recipients' );
		}

		TierCord_Queue::add(
			array(
				'title'    => $subject,
				'category' => 'announcements',
				'subject'  => $s['announce_subject'],
				'body'     => $s['announce_body'],
				'vars'     => $vars,
				'audience' => $audience,
				'source'   => 'manual',
			)
		);
		delete_transient( 'tiercord_em_draft_' . get_current_user_id() );
		spawn_cron();
		self::go( 'history', 'queued' );
	}

	public static function cancel() {
		self::require_cap();
		check_admin_referer( 'tiercord_em_cancel' );
		TierCord_Queue::cancel( sanitize_key( $_GET['job'] ?? '' ) );
		self::go( 'history', 'cancelled' );
	}

	public static function run_queue() {
		self::require_cap();
		check_admin_referer( 'tiercord_em_run_queue' );
		TierCord_Queue::process();
		self::go( 'history', 'ran' );
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tabs = array(
			'compose'   => __( 'Send announcement', 'tiercord' ),
			'settings'  => __( 'Settings', 'tiercord' ),
			'templates' => __( 'Templates', 'tiercord' ),
			'history'   => __( 'History & log', 'tiercord' ),
		);
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'compose'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'compose';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'TierCord Emails', 'tiercord' ); ?></h1>
			<?php self::notice(); ?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $k => $label ) : ?>
					<a class="nav-tab <?php echo $k === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url( $k ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="tiercord-tab-body">
				<?php
				switch ( $tab ) {
					case 'settings':
						self::render_settings();
						break;
					case 'templates':
						self::render_templates();
						break;
					case 'history':
						self::render_history();
						break;
					default:
						self::render_compose();
				}
				?>
			</div>
		</div>
		<?php
	}

	private static function notice() {
		$n   = isset( $_GET['tiercord_em_notice'] ) ? sanitize_key( wp_unslash( $_GET['tiercord_em_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice.
		$map = array(
			'saved'         => array( 'success', __( 'Saved.', 'tiercord' ) ),
			'secret'        => array( 'warning', __( 'New bridge secret generated. Update the secret in your bot\'s settings and restart the bot.', 'tiercord' ) ),
			'empty'         => array( 'error', __( 'Please enter a subject and a message.', 'tiercord' ) ),
			'test_sent'     => array( 'success', __( 'Test email sent to you.', 'tiercord' ) ),
			'test_failed'   => array( 'error', __( 'The test email could not be sent. Check your site\'s mail/SMTP setup.', 'tiercord' ) ),
			'no_recipients' => array( 'error', __( 'Nobody matches that audience.', 'tiercord' ) ),
			'queued'        => array( 'success', __( 'Announcement queued. It is being sent in the background.', 'tiercord' ) ),
			'cancelled'     => array( 'success', __( 'Mailing cancelled.', 'tiercord' ) ),
			'ran'           => array( 'success', __( 'Processed one batch.', 'tiercord' ) ),
		);
		if ( isset( $map[ $n ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $map[ $n ][0] ) . ' is-dismissible"><p>' . esc_html( $map[ $n ][1] ) . '</p></div>';
		}
	}

	/* ---------------- Compose ---------------- */

	private static function render_compose() {
		$draft   = get_transient( 'tiercord_em_draft_' . get_current_user_id() );
		$p       = is_array( $draft ) ? $draft['p'] : array();
		$aud     = $p['audience'] ?? 'levels';
		$chosen  = array_map( 'intval', (array) ( $p['levels'] ?? array() ) );
		$levels  = pmpro_getAllLevels( true, true );
		$servers = TierCord_Helpers::active_servers();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tiercord-em-card">
			<input type="hidden" name="action" value="tiercord_em_announce">
			<?php wp_nonce_field( 'tiercord_em_announce' ); ?>
			<h2><?php esc_html_e( 'Email an announcement', 'tiercord' ); ?></h2>

			<p><label for="tiercord_em_subject"><strong><?php esc_html_e( 'Subject', 'tiercord' ); ?></strong></label><br>
				<input type="text" id="tiercord_em_subject" name="subject" class="large-text" value="<?php echo esc_attr( $draft['subject'] ?? '' ); ?>"></p>

			<p><strong><?php esc_html_e( 'Message', 'tiercord' ); ?></strong></p>
			<?php
			wp_editor(
				$draft['message'] ?? '',
				'tiercord_em_message',
				array(
					'textarea_name' => 'message',
					'textarea_rows' => 12,
					'media_buttons' => true,
				)
			);
			?>
			<p class="description"><?php esc_html_e( 'Wrapped in the "Announcement" template. You can use {first_name} in the template.', 'tiercord' ); ?></p>

			<h3><?php esc_html_e( 'Send to', 'tiercord' ); ?></h3>
			<p><label><input type="radio" name="audience" value="levels" <?php checked( $aud, 'levels' ); ?>> <?php esc_html_e( 'Members of these levels', 'tiercord' ); ?></label></p>
			<div class="tiercord-em-aud">
				<?php foreach ( $levels as $level ) : ?>
					<?php $n = TierCord_Queue::count_audience( array( 'type' => 'levels', 'levels' => array( $level->id ) ) ); ?>
					<label class="tiercord-em-block"><input type="checkbox" name="levels[]" value="<?php echo (int) $level->id; ?>" <?php checked( in_array( (int) $level->id, $chosen, true ) ); ?>> <?php echo esc_html( $level->name ); ?> <span class="description">(<?php echo (int) $n; ?>)</span></label>
				<?php endforeach; ?>
			</div>
			<p><label><input type="radio" name="audience" value="all_members" <?php checked( $aud, 'all_members' ); ?>> <?php esc_html_e( 'All active members', 'tiercord' ); ?> <span class="description">(<?php echo (int) TierCord_Queue::count_audience( array( 'type' => 'all_members' ) ); ?>)</span></label></p>
			<?php if ( $servers ) : ?>
				<p><label><input type="radio" name="audience" value="guild" <?php checked( $aud, 'guild' ); ?>> <?php esc_html_e( 'Everyone with access to Discord server:', 'tiercord' ); ?></label>
					<select name="guild">
						<?php foreach ( $servers as $gid => $srv ) : ?>
							<option value="<?php echo esc_attr( $gid ); ?>" <?php selected( $p['guild'] ?? '', $gid ); ?>><?php echo esc_html( $srv['name'] . ' (' . TierCord_Queue::count_audience( array( 'type' => 'guild', 'guild' => $gid ) ) . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p><label><input type="radio" name="audience" value="roles" <?php checked( $aud, 'roles' ); ?>> <?php esc_html_e( 'Members with these Discord roles (e.g. everyone who can see a channel):', 'tiercord' ); ?></label></p>
				<div class="tiercord-em-aud">
					<?php $chosen_roles = array_map( 'strval', (array) ( $p['role_keys'] ?? array() ) ); ?>
					<?php foreach ( $servers as $gid => $srv ) : ?>
						<?php foreach ( $srv['roles'] as $rid => $rname ) : ?>
							<?php $key = TierCord_Helpers::role_key( $gid, $rid ); ?>
							<label class="tiercord-em-block"><input type="checkbox" name="role_keys[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $chosen_roles, true ) ); ?>> <?php echo esc_html( TierCord_Helpers::role_label( $gid, $rid ) ); ?> <span class="description">(<?php echo (int) TierCord_Queue::count_audience( array( 'type' => 'roles', 'roles' => array( $rid ) ) ); ?>)</span></label>
						<?php endforeach; ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Counts are active members; anyone who unsubscribed from announcements is skipped automatically.', 'tiercord' ); ?></p>

			<p>
				<button type="submit" name="test" value="1" class="button"><?php esc_html_e( 'Send a test to me', 'tiercord' ); ?></button>
				<button type="submit" class="button button-primary" data-tiercord-confirm="<?php echo esc_attr( __( 'Send this announcement now?', 'tiercord' ) ); ?>\"><?php esc_html_e( 'Send announcement', 'tiercord' ); ?></button>
			</p>
		</form>
		<?php
	}

	/* ---------------- Settings ---------------- */

	private static function render_settings() {
		$s     = TierCord_Email::settings();
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="tiercord_em_save">
			<input type="hidden" name="tab" value="settings">
			<?php wp_nonce_field( 'tiercord_em_save' ); ?>

			<div class="tiercord-em-card">
				<h2><?php esc_html_e( 'Sender', 'tiercord' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'From name', 'tiercord' ); ?></th><td><input type="text" name="from_name" class="regular-text" value="<?php echo esc_attr( $s['from_name'] ); ?>"></td></tr>
					<tr><th><?php esc_html_e( 'From email', 'tiercord' ); ?></th><td><input type="email" name="from_email" class="regular-text" value="<?php echo esc_attr( $s['from_email'] ); ?>"><p class="description"><?php esc_html_e( 'Use an address on your own domain. For reliable delivery install an SMTP plugin (e.g. WP Mail SMTP with Brevo or Amazon SES).', 'tiercord' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Brand colour', 'tiercord' ); ?></th><td><input type="text" name="brand_color" value="<?php echo esc_attr( $s['brand_color'] ); ?>" placeholder="#5865F2" style="width:110px"></td></tr>
					<tr><th><?php esc_html_e( 'Footer text', 'tiercord' ); ?></th><td><textarea name="footer_text" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'e.g. your business name and postal address', 'tiercord' ); ?>"><?php echo esc_textarea( $s['footer_text'] ); ?></textarea></td></tr>
					<tr><th><?php esc_html_e( 'Sending speed', 'tiercord' ); ?></th><td><input type="number" min="1" max="500" name="batch_size" value="<?php echo (int) $s['batch_size']; ?>" style="width:80px"> <?php esc_html_e( 'emails per minute', 'tiercord' ); ?><p class="description"><?php esc_html_e( 'Keep this under your host / SMTP provider\'s limit.', 'tiercord' ); ?></p></td></tr>
				</table>
			</div>

			<div class="tiercord-em-card">
				<h2><?php esc_html_e( 'New posts', 'tiercord' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Enable', 'tiercord' ); ?></th><td><label><input type="checkbox" name="posts_enabled" value="1" <?php checked( $s['posts_enabled'] ); ?>> <?php esc_html_e( 'Email members when new content is published', 'tiercord' ); ?></label></td></tr>
					<tr><th><?php esc_html_e( 'Content types', 'tiercord' ); ?></th><td>
						<?php foreach ( $types as $type ) : ?>
							<label style="margin-right:14px"><input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, (array) $s['post_types'], true ) ); ?>> <?php echo esc_html( $type->labels->name ); ?></label>
						<?php endforeach; ?>
					</td></tr>
					<tr><th><?php esc_html_e( 'Default', 'tiercord' ); ?></th><td><label><input type="checkbox" name="notify_default" value="1" <?php checked( $s['notify_default'] ); ?>> <?php esc_html_e( '"Email members" is ticked by default on new posts', 'tiercord' ); ?></label></td></tr>
					<tr><th><?php esc_html_e( 'Unrestricted posts', 'tiercord' ); ?></th><td>
						<label><input type="radio" name="unrestricted_to" value="all_members" <?php checked( $s['unrestricted_to'], 'all_members' ); ?>> <?php esc_html_e( 'Email all active members', 'tiercord' ); ?></label><br>
						<label><input type="radio" name="unrestricted_to" value="none" <?php checked( $s['unrestricted_to'], 'none' ); ?>> <?php esc_html_e( 'Don\'t email (only posts restricted to levels are emailed)', 'tiercord' ); ?></label>
						<p class="description"><?php esc_html_e( 'Restricted posts go to the levels set in the post\'s "Require Membership" box (or its category).', 'tiercord' ); ?></p>
					</td></tr>
					<tr><th><?php esc_html_e( 'Delay', 'tiercord' ); ?></th><td><input type="number" min="1" max="1440" name="post_delay" value="<?php echo (int) $s['post_delay']; ?>" style="width:80px"> <?php esc_html_e( 'minutes after publishing', 'tiercord' ); ?></td></tr>
				</table>
			</div>

			<div class="tiercord-em-card">
				<h2><?php esc_html_e( 'Discord access emails', 'tiercord' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Sent automatically when TierCord changes a member\'s Discord roles.', 'tiercord' ); ?></p>
				<p><label><input type="checkbox" name="welcome_enabled" value="1" <?php checked( $s['welcome_enabled'] ); ?>> <?php esc_html_e( 'Welcome — member gained one or more roles (channels)', 'tiercord' ); ?></label></p>
				<p><label><input type="checkbox" name="paused_enabled" value="1" <?php checked( $s['paused_enabled'] ); ?>> <?php esc_html_e( 'Paused — roles removed (expired, cancelled, downgraded)', 'tiercord' ); ?></label></p>
				<p><label><input type="checkbox" name="reconnect_enabled" value="1" <?php checked( $s['reconnect_enabled'] ); ?>> <?php esc_html_e( 'Reconnect — Discord authorisation expired (max. once a week)', 'tiercord' ); ?></label></p>
			</div>

			<div class="tiercord-em-card">
				<h2><?php esc_html_e( 'Discord → email bridge', 'tiercord' ); ?></h2>
				<p>
					<?php esc_html_e( 'Optional: email messages posted in your Discord announcement channels to the members whose roles can see that channel. This needs a Discord bot that you run yourself: it sends each new message as JSON to the endpoint below, with the secret in the X-TierCord-Secret header. The fields are listed in the plugin\'s readme.', 'tiercord' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Enable', 'tiercord' ); ?></th><td><label><input type="checkbox" name="bridge_enabled" value="1" <?php checked( $s['bridge_enabled'] ); ?>> <?php esc_html_e( 'Accept announcements from the bot', 'tiercord' ); ?></label></td></tr>
					<tr><th><?php esc_html_e( 'Endpoint', 'tiercord' ); ?></th><td><code style="user-select:all"><?php echo esc_html( TierCord_Bridge::endpoint() ); ?></code></td></tr>
					<tr><th><?php esc_html_e( 'Secret', 'tiercord' ); ?></th><td>
						<code style="user-select:all"><?php echo esc_html( $s['bridge_secret'] ? $s['bridge_secret'] : __( '(none — generate one)', 'tiercord' ) ); ?></code>
						<a class="button button-small" data-tiercord-confirm="<?php echo esc_attr( __( 'Generate a new secret? Your bot will stop working until you update its secret.', 'tiercord' ) ); ?>" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tiercord_em_regen_secret' ), 'tiercord_em_regen_secret' ) ); ?>"><?php esc_html_e( 'Generate new', 'tiercord' ); ?></a>
					</td></tr>
					<tr><th><?php esc_html_e( 'Allowed channel IDs', 'tiercord' ); ?></th><td><textarea name="bridge_channels" rows="2" class="large-text" placeholder="123456789012345678, 234567890123456789"><?php echo esc_textarea( $s['bridge_channels'] ); ?></textarea><p class="description"><?php esc_html_e( 'Only messages from these channels are emailed (right-click a channel in Discord with Developer Mode on → Copy Channel ID). Empty = any channel the bot sends.', 'tiercord' ); ?></p></td></tr>
				</table>
			</div>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/* ---------------- Templates ---------------- */

	private static function render_templates() {
		$s    = TierCord_Email::settings();
		$tpls = array(
			'post'      => array( __( 'New post', 'tiercord' ), '{post_title} {post_url} {post_excerpt} {author}' ),
			'announce'  => array( __( 'Announcement (written here)', 'tiercord' ), '{subject} {message}' ),
			'discord'   => array( __( 'Announcement relayed from Discord', 'tiercord' ), '{server_name} {channel_name} {author} {message} {first_line} {discord_url}' ),
			'welcome'   => array( __( 'Access granted', 'tiercord' ), '{access_names} {access_list}' ),
			'paused'    => array( __( 'Access paused', 'tiercord' ), '{access_names} {access_list}' ),
			'reconnect' => array( __( 'Reconnect needed', 'tiercord' ), '{server_names} {server_list}' ),
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tiercord-em-tpl">
			<input type="hidden" name="action" value="tiercord_em_save">
			<input type="hidden" name="tab" value="templates">
			<?php wp_nonce_field( 'tiercord_em_save' ); ?>
			<div class="tiercord-em-card">
				<p><strong><?php esc_html_e( 'Available everywhere:', 'tiercord' ); ?></strong> <code>{first_name} {display_name} {site_name} {level_name} {account_url} {levels_url} {brand_color}</code></p>
				<p><strong><?php esc_html_e( 'Button:', 'tiercord' ); ?></strong> <code>{button:Label|https://…}</code> — <?php esc_html_e( 'e.g.', 'tiercord' ); ?> <code>{button:Read the post|{post_url}}</code></p>
				<p class="description"><?php esc_html_e( 'Bodies are HTML. The header, footer and unsubscribe link are added automatically.', 'tiercord' ); ?></p>
			</div>
			<?php foreach ( $tpls as $key => $t ) : ?>
				<div class="tiercord-em-card">
					<h2><?php echo esc_html( $t[0] ); ?></h2>
					<p class="description"><?php esc_html_e( 'Extra placeholders:', 'tiercord' ); ?> <code><?php echo esc_html( $t[1] ); ?></code></p>
					<p><label><?php esc_html_e( 'Subject', 'tiercord' ); ?><br><input type="text" class="large-text" name="<?php echo esc_attr( $key ); ?>_subject" value="<?php echo esc_attr( $s[ $key . '_subject' ] ); ?>"></label></p>
					<p><label><?php esc_html_e( 'Body', 'tiercord' ); ?><br><textarea rows="7" name="<?php echo esc_attr( $key ); ?>_body"><?php echo esc_textarea( $s[ $key . '_body' ] ); ?></textarea></label></p>
				</div>
			<?php endforeach; ?>
			<p>
				<?php submit_button( __( 'Save templates', 'tiercord' ), 'primary', 'submit', false ); ?>
				<button type="submit" name="reset" value="1" class="button" data-tiercord-confirm="<?php echo esc_attr( __( 'Reset all templates to the defaults?', 'tiercord' ) ); ?>\"><?php esc_html_e( 'Reset to defaults', 'tiercord' ); ?></button>
			</p>
		</form>
		<?php
	}

	/* ---------------- History ---------------- */

	private static function render_history() {
		$jobs = TierCord_Queue::jobs();
		uasort(
			$jobs,
			function ( $a, $b ) {
				return $b['created'] - $a['created'];
			}
		);
		$labels = array(
			'queued'    => __( 'Queued', 'tiercord' ),
			'sending'   => __( 'Sending', 'tiercord' ),
			'done'      => __( 'Done', 'tiercord' ),
			'cancelled' => __( 'Cancelled', 'tiercord' ),
		);
		?>
		<div class="tiercord-em-card">
			<h2><?php esc_html_e( 'Mailings', 'tiercord' ); ?></h2>
			<?php if ( TierCord_Queue::has_pending() ) : ?>
				<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tiercord_em_run_queue' ), 'tiercord_em_run_queue' ) ); ?>"><?php esc_html_e( 'Send next batch now', 'tiercord' ); ?></a>
				<span class="description"><?php esc_html_e( 'Batches normally go out every minute via WP-Cron.', 'tiercord' ); ?></span></p>
			<?php endif; ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Created', 'tiercord' ); ?></th>
					<th><?php esc_html_e( 'Mailing', 'tiercord' ); ?></th>
					<th><?php esc_html_e( 'Audience', 'tiercord' ); ?></th>
					<th><?php esc_html_e( 'Progress', 'tiercord' ); ?></th>
					<th><?php esc_html_e( 'Status', 'tiercord' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $jobs ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'Nothing sent yet.', 'tiercord' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $jobs as $job ) : ?>
					<?php
					$done = $job['sent'] + $job['skipped'] + $job['failed'];
					$pct  = $job['total'] ? min( 100, round( 100 * $done / $job['total'] ) ) : ( 'done' === $job['status'] ? 100 : 0 );
					?>
					<tr>
						<td><?php echo esc_html( wp_date( 'j M Y H:i', $job['created'] ) ); ?></td>
						<td><strong><?php echo esc_html( $job['title'] ); ?></strong><br><span class="description"><?php echo esc_html( $job['category'] ); ?></span></td>
						<td><?php echo esc_html( TierCord_Queue::describe_audience( $job['audience'] ) ); ?></td>
						<td><span class="tiercord-em-bar"><span style="width:<?php echo (int) $pct; ?>%"></span></span><br>
							<?php
							/* translators: 1: emails sent, 2: members skipped because they unsubscribed, 3: emails that failed. */
							$progress = sprintf( __( '%1$d sent · %2$d opted out · %3$d failed', 'tiercord' ), $job['sent'], $job['skipped'], $job['failed'] );
							?>
							<span class="description"><?php echo esc_html( $progress ); ?></span></td>
						<td><?php echo esc_html( $labels[ $job['status'] ] ?? $job['status'] ); ?>
							<?php if ( in_array( $job['status'], array( 'queued', 'sending' ), true ) ) : ?>
								<br><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tiercord_em_cancel&job=' . rawurlencode( $job['id'] ) ), 'tiercord_em_cancel' ) ); ?>" data-tiercord-confirm="<?php echo esc_attr( __( 'Stop this mailing?', 'tiercord' ) ); ?>\"><?php esc_html_e( 'Cancel', 'tiercord' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div class="tiercord-em-card">
			<h2><?php esc_html_e( 'Log', 'tiercord' ); ?></h2>
			<?php $log = get_option( TierCord_Email::LOG, array() ); ?>
			<?php if ( ! $log ) : ?>
				<p><?php esc_html_e( 'Nothing logged yet.', 'tiercord' ); ?></p>
			<?php else : ?>
				<table class="widefat striped tiercord-em-log"><tbody>
				<?php foreach ( $log as $e ) : ?>
					<tr class="<?php echo esc_attr( $e['level'] ); ?>"><td style="width:140px"><?php echo esc_html( wp_date( 'j M Y H:i:s', $e['time'] ) ); ?></td><td class="<?php echo esc_attr( $e['level'] ); ?>"><?php echo esc_html( $e['message'] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		</div>
		<?php
	}
}
