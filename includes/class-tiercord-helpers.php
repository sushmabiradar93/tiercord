<?php
/**
 * Shared helpers: settings, secrets, logging, user meta.
 *
 * @package TierCord
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared helpers.
 */
class TierCord_Helpers {

	const OPTION   = 'tiercord_settings';
	const LOG      = 'tiercord_log';
	const LOG_SIZE = 200;

	/* ------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	/**
	 * Default settings structure.
	 *
	 * servers:   [ guild_id => [ 'enabled' => bool, 'name' => string, 'roles' => [ role_id => role name ] ] ]
	 *            'roles' are the roles TierCord hands out and takes back in that server ("managed roles").
	 *            Roles not listed here (moderators, boosters…) are never touched.
	 * level_map: [ level_id => [ 'guild_id:role_id', ... ] ]  roles each membership level gets.
	 */
	public static function defaults() {
		return array(
			'client_id'            => '',
			'client_secret'        => '',
			'bot_token'            => '',
			'servers'              => array(),
			'level_map'            => array(),
			'on_expire'            => 'remove_role', // remove_role | kick.
			'disconnect_on_delete' => 1,
			'account_page'         => 1, // Show the Discord box on the PMPro Account page automatically.
			'emails_enabled'       => 1, // Email notifications module.
		);
	}

	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		$s     = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );

		// Constants in wp-config.php override stored secrets (recommended for production).
		if ( defined( 'TIERCORD_CLIENT_ID' ) ) {
			$s['client_id'] = TIERCORD_CLIENT_ID;
		}
		if ( defined( 'TIERCORD_CLIENT_SECRET' ) ) {
			$s['client_secret'] = TIERCORD_CLIENT_SECRET;
		}
		if ( defined( 'TIERCORD_BOT_TOKEN' ) ) {
			$s['bot_token'] = TIERCORD_BOT_TOKEN;
		}
		return $s;
	}

	public static function get( $key ) {
		$s = self::settings();
		return isset( $s[ $key ] ) ? $s[ $key ] : null;
	}

	public static function is_configured() {
		$s = self::settings();
		return ! empty( $s['client_id'] ) && ! empty( $s['client_secret'] ) && ! empty( $s['bot_token'] );
	}

	/**
	 * Servers that are enabled and have at least one managed role.
	 *
	 * @return array [ guild_id => [ 'name' => string, 'roles' => [ role_id => name ] ] ]
	 */
	public static function active_servers() {
		$out = array();
		foreach ( (array) self::get( 'servers' ) as $guild_id => $srv ) {
			$roles = isset( $srv['roles'] ) && is_array( $srv['roles'] ) ? $srv['roles'] : array();
			if ( ! empty( $srv['enabled'] ) && $roles ) {
				$out[ (string) $guild_id ] = array(
					'name'  => isset( $srv['name'] ) ? (string) $srv['name'] : (string) $guild_id,
					'roles' => $roles,
				);
			}
		}
		return $out;
	}

	/**
	 * Key used in the level map for one role in one server.
	 *
	 * @param string $guild_id Server ID.
	 * @param string $role_id  Role ID.
	 * @return string
	 */
	public static function role_key( $guild_id, $role_id ) {
		return $guild_id . ':' . $role_id;
	}

	/**
	 * Split a "guild_id:role_id" key.
	 *
	 * @param string $key Key.
	 * @return array|false [ guild_id, role_id ]
	 */
	public static function parse_role_key( $key ) {
		return preg_match( '/^(\d+):(\d+)$/', (string) $key, $m ) ? array( $m[1], $m[2] ) : false;
	}

	/**
	 * Readable name for a managed role, e.g. "Premium" or "Premium (Trading Club)" when several servers are managed.
	 *
	 * @param string $guild_id Server ID.
	 * @param string $role_id  Role ID.
	 * @return string
	 */
	public static function role_label( $guild_id, $role_id ) {
		$servers = self::active_servers();
		$name    = isset( $servers[ $guild_id ]['roles'][ $role_id ] ) ? $servers[ $guild_id ]['roles'][ $role_id ] : $role_id;
		if ( count( $servers ) > 1 && isset( $servers[ $guild_id ] ) ) {
			$name .= ' (' . $servers[ $guild_id ]['name'] . ')';
		}
		return $name;
	}

		public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=tiercord_callback' );
	}

	/* ------------------------------------------------------------------
	 * Membership → servers
	 * ------------------------------------------------------------------ */

	/**
	 * IDs of the user's currently active PMPro levels (supports multiple memberships).
	 */
	public static function user_level_ids( $user_id ) {
		$levels = pmpro_getMembershipLevelsForUser( $user_id );
		$ids    = array();
		if ( is_array( $levels ) ) {
			foreach ( $levels as $level ) {
				if ( is_object( $level ) && ! empty( $level->id ) ) {
					$ids[] = (int) $level->id;
				}
			}
		}
		return $ids;
	}

	/**
	 * Names of the user's currently active PMPro levels.
	 *
	 * @param int $user_id User ID.
	 * @return string[]
	 */
	public static function user_level_names( $user_id ) {
		$names  = array();
		$levels = pmpro_getMembershipLevelsForUser( $user_id );
		if ( is_array( $levels ) ) {
			foreach ( $levels as $level ) {
				if ( is_object( $level ) && ! empty( $level->name ) ) {
					$names[] = $level->name;
				}
			}
		}
		return $names;
	}

	/**
	 * Roles the user should currently have, per server.
	 *
	 * @param int $user_id User ID.
	 * @return array [ guild_id => [ role_id, ... ] ]
	 */
	public static function allowed_roles_for_user( $user_id ) {
		$map     = (array) self::get( 'level_map' );
		$active  = self::active_servers();
		$allowed = array();

		foreach ( self::user_level_ids( $user_id ) as $level_id ) {
			foreach ( (array) ( $map[ $level_id ] ?? array() ) as $key ) {
				$parsed = self::parse_role_key( $key );
				if ( $parsed && isset( $active[ $parsed[0] ]['roles'][ $parsed[1] ] ) ) {
					$allowed[ $parsed[0] ][ $parsed[1] ] = true;
				}
			}
		}

		$out = array();
		foreach ( $allowed as $guild_id => $roles ) {
			$out[ (string) $guild_id ] = array_map( 'strval', array_keys( $roles ) );
		}

		/**
		 * Filter the Discord roles a user should have.
		 *
		 * @param array $roles   [ guild_id => [ role_id, ... ] ].
		 * @param int   $user_id User ID.
		 */
		return apply_filters( 'tiercord_allowed_roles', $out, $user_id );
	}

	/**
	 * Membership levels that include a given role (used for upgrade hints).
	 *
	 * @param string $guild_id Server ID.
	 * @param string $role_id  Role ID.
	 * @return int[]
	 */
	public static function levels_with_role( $guild_id, $role_id ) {
		$key    = self::role_key( $guild_id, $role_id );
		$levels = array();
		foreach ( (array) self::get( 'level_map' ) as $level_id => $keys ) {
			if ( in_array( $key, (array) $keys, true ) ) {
				$levels[] = (int) $level_id;
			}
		}
		return $levels;
	}

	/* ------------------------------------------------------------------
	 * Upgrade from the one-role-per-server format (TierCord 1.0 development builds)
	 * ------------------------------------------------------------------ */

	/**
	 * Convert old settings in place. Safe to call on every load; does nothing once migrated.
	 */
	public static function maybe_migrate() {
		if ( (int) get_option( 'tiercord_schema' ) >= 2 ) {
			return;
		}
		$saved = get_option( self::OPTION );
		if ( is_array( $saved ) ) {
			$servers = isset( $saved['servers'] ) && is_array( $saved['servers'] ) ? $saved['servers'] : array();
			foreach ( $servers as $guild_id => $srv ) {
				if ( ! isset( $srv['roles'] ) ) {
					$servers[ $guild_id ]['roles'] = array();
					if ( ! empty( $srv['role_id'] ) ) {
						$cached = get_transient( 'tiercord_roles_' . $guild_id );
						$name   = isset( $cached['roles'][ $srv['role_id'] ]['name'] ) ? $cached['roles'][ $srv['role_id'] ]['name'] : 'Member';
						$servers[ $guild_id ]['roles'] = array( (string) $srv['role_id'] => $name );
					}
				}
				unset( $servers[ $guild_id ]['role_id'] );
			}

			$map = array();
			foreach ( (array) ( $saved['level_map'] ?? array() ) as $level_id => $entries ) {
				foreach ( (array) $entries as $entry ) {
					if ( self::parse_role_key( $entry ) ) {
						$map[ $level_id ][] = (string) $entry;
					} elseif ( ! empty( $servers[ $entry ]['roles'] ) ) {
						foreach ( array_keys( $servers[ $entry ]['roles'] ) as $role_id ) {
							$map[ $level_id ][] = self::role_key( $entry, $role_id );
						}
					}
				}
			}

			$saved['servers']   = $servers;
			$saved['level_map'] = $map;
			update_option( self::OPTION, $saved );
		}
		update_option( 'tiercord_schema', 2 );
	}

		/* ------------------------------------------------------------------
	 * Linked Discord account (user meta)
	 * ------------------------------------------------------------------ */

	public static function discord_id( $user_id ) {
		return (string) get_user_meta( $user_id, 'tiercord_discord_id', true );
	}

	public static function is_linked( $user_id ) {
		return '' !== self::discord_id( $user_id );
	}

	public static function save_tokens( $user_id, $token_response ) {
		if ( ! empty( $token_response['access_token'] ) ) {
			update_user_meta( $user_id, 'tiercord_access_token', self::encrypt( $token_response['access_token'] ) );
		}
		if ( ! empty( $token_response['refresh_token'] ) ) {
			update_user_meta( $user_id, 'tiercord_refresh_token', self::encrypt( $token_response['refresh_token'] ) );
		}
		$expires = isset( $token_response['expires_in'] ) ? (int) $token_response['expires_in'] : 604800;
		update_user_meta( $user_id, 'tiercord_token_expires', time() + $expires - 60 );
	}

	public static function access_token( $user_id ) {
		return self::decrypt( (string) get_user_meta( $user_id, 'tiercord_access_token', true ) );
	}

	public static function refresh_token( $user_id ) {
		return self::decrypt( (string) get_user_meta( $user_id, 'tiercord_refresh_token', true ) );
	}

	public static function clear_link( $user_id ) {
		foreach ( array( 'tiercord_discord_id', 'tiercord_discord_username', 'tiercord_discord_avatar', 'tiercord_access_token', 'tiercord_refresh_token', 'tiercord_token_expires', 'tiercord_status', 'tiercord_needs_reconnect' ) as $key ) {
			delete_user_meta( $user_id, $key );
		}
	}

	/* ------------------------------------------------------------------
	 * Light encryption for stored OAuth tokens
	 * ------------------------------------------------------------------ */

	private static function key() {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' ) . 'tiercord';
		return hash( 'sha256', $material, true );
	}

	public static function encrypt( $plain ) {
		if ( '' === $plain || ! function_exists( 'openssl_encrypt' ) ) {
			return $plain;
		}
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return $plain;
		}
		return 'enc:' . bin2hex( $iv . $cipher );
	}

	public static function decrypt( $stored ) {
		if ( 0 !== strpos( (string) $stored, 'enc:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return (string) $stored;
		}
		$hex = substr( $stored, 4 );
		$raw = ctype_xdigit( $hex ) ? hex2bin( $hex ) : false;
		if ( false === $raw || strlen( $raw ) < 17 ) {
			return '';
		}
		$plain = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
		return false === $plain ? '' : $plain;
	}

	/* ------------------------------------------------------------------
	 * Logging (last N entries, shown on the settings page)
	 * ------------------------------------------------------------------ */

	public static function log( $message, $level = 'info', $user_id = 0 ) {
		$log = get_option( self::LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift(
			$log,
			array(
				'time'    => time(),
				'level'   => $level,
				'user_id' => (int) $user_id,
				'message' => wp_strip_all_tags( $message ),
			)
		);
		update_option( self::LOG, array_slice( $log, 0, self::LOG_SIZE ), false );

		/**
		 * Fires after TierCord logs a message (hook here to forward to your own logger).
		 *
		 * @param string $message Message.
		 * @param string $level   info | warning | error.
		 * @param int    $user_id Related user, or 0.
		 */
		do_action( 'tiercord_logged', $message, $level, $user_id );
	}

	/**
	 * Whether the page being shown already places a shortcode itself.
	 *
	 * Checks the page's saved content as well as the content passed to the_content: by the time
	 * a late the_content filter runs, shortcodes have already been turned into HTML, so the
	 * filtered content alone never contains the tag.
	 *
	 * @param string $content Content passed to the_content.
	 * @param string $tag     Shortcode tag.
	 * @return bool
	 */
	public static function page_has_shortcode( $content, $tag ) {
		if ( has_shortcode( $content, $tag ) ) {
			return true;
		}
		$post = get_post();
		return $post instanceof WP_Post && has_shortcode( $post->post_content, $tag );
	}
}
