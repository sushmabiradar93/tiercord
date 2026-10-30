<?php
/**
 * Keeps each linked member's Discord roles in line with their PMPro levels.
 *
 * Access model: each membership level gets a set of Discord roles ("managed roles"), in one server or
 * several. Discord's own channel permissions decide which channels each role can see.
 *  - Level grants roles          -> user is added to the server (if needed) and given exactly those roles.
 *  - Level no longer grants one  -> that role is removed; the others stay (downgrades).
 *  - No roles left in a server   -> access is "paused" (or the user is removed, if configured).
 *  - Renewal / upgrade           -> roles are given back, access returns instantly.
 * Roles that are not managed by TierCord (moderators, boosters…) are never touched.
 *
 * @package TierCord
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Role synchronisation between Paid Memberships Pro and Discord.
 */
class TierCord_Sync {

	const BATCH_SIZE = 25;

	/** @var array<int,bool> Users whose membership changed during this request. */
	private static $queue = array();

	public static function init() {
		// Fires on checkout, admin change, cancellation, expiry and renewal.
		add_action( 'pmpro_after_change_membership_level', array( __CLASS__, 'on_level_change' ), 20, 2 );
		// Extra safety net specifically for the expiry cron.
		add_action( 'pmpro_membership_post_membership_expiry', array( __CLASS__, 'on_expiry' ), 20, 2 );

		add_action( 'tiercord_sync_user', array( __CLASS__, 'sync_user' ) );
		add_action( 'tiercord_daily_resync', array( __CLASS__, 'start_full_resync' ) );
		add_action( 'tiercord_resync_batch', array( __CLASS__, 'run_batch' ) );

		add_action( 'delete_user', array( __CLASS__, 'on_delete_user' ) );
	}

	/* ------------------------------------------------------------------
	 * Triggers
	 * ------------------------------------------------------------------ */

	public static function on_level_change( $level_id, $user_id ) {
		self::queue( $user_id );
	}

	public static function on_expiry( $user_id, $level_id ) {
		self::queue( $user_id );
	}

	/**
	 * Collect users and sync once at the end of the request, after PMPro has
	 * finished writing all membership changes.
	 */
	public static function queue( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! TierCord_Helpers::is_linked( $user_id ) ) {
			return;
		}
		if ( empty( self::$queue ) ) {
			add_action( 'shutdown', array( __CLASS__, 'flush_queue' ) );
		}
		self::$queue[ $user_id ] = true;
	}

	public static function flush_queue() {
		$users        = array_keys( self::$queue );
		self::$queue  = array();

		// A handful of users (normal checkout / admin edit): sync right away.
		if ( count( $users ) <= 5 ) {
			foreach ( $users as $user_id ) {
				self::sync_user( $user_id );
			}
			return;
		}

		// Bulk changes (e.g. the expiry cron): spread them out to respect rate limits.
		$delay = 0;
		foreach ( $users as $user_id ) {
			wp_schedule_single_event( time() + $delay, 'tiercord_sync_user', array( $user_id ) );
			$delay += 3;
		}
	}

	public static function on_delete_user( $user_id ) {
		if ( ! TierCord_Helpers::get( 'disconnect_on_delete' ) || ! TierCord_Helpers::is_linked( $user_id ) ) {
			return;
		}
		self::revoke_everything( $user_id );
	}

	/* ------------------------------------------------------------------
	 * Core sync
	 * ------------------------------------------------------------------ */

	/**
	 * Bring one user's Discord roles in line with their memberships.
	 *
	 * @param int $user_id User ID.
	 * @return array Per-server status: [ guild_id => [ state, roles, message, time ] ].
	 */
	public static function sync_user( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! TierCord_Helpers::is_configured() || ! TierCord_Helpers::is_linked( $user_id ) ) {
			return array();
		}

		$discord_id = TierCord_Helpers::discord_id( $user_id );
		$servers    = TierCord_Helpers::active_servers();
		$wanted     = TierCord_Helpers::allowed_roles_for_user( $user_id );
		$previous   = self::previous_status( $user_id );
		$status     = array();

		foreach ( $servers as $guild_id => $server ) {
			$status[ $guild_id ] = self::sync_server(
				$user_id,
				$discord_id,
				$guild_id,
				$server,
				isset( $wanted[ $guild_id ] ) ? $wanted[ $guild_id ] : array(),
				isset( $previous[ $guild_id ] ) ? $previous[ $guild_id ] : null
			);
		}

		// Servers that were disabled or removed in settings since the last sync: take back the roles we gave.
		foreach ( $previous as $guild_id => $prev ) {
			if ( isset( $status[ $guild_id ] ) || empty( $prev['roles'] ) ) {
				continue;
			}
			foreach ( $prev['roles'] as $role_id ) {
				$res = TierCord_API::remove_role( $guild_id, $discord_id, $role_id );
				if ( ! TierCord_API::ok( $res ) && 404 !== $res['code'] ) {
					TierCord_Helpers::log( sprintf( 'Could not remove a role in no-longer-managed server %1$s: %2$s', $guild_id, $res['error'] ), 'error', $user_id );
				}
			}
		}

		update_user_meta( $user_id, 'tiercord_status', $status );
		update_user_meta( $user_id, 'tiercord_last_sync', time() );

		/**
		 * Fires after a member's Discord access has been synced.
		 *
		 * @param int   $user_id User ID.
		 * @param array $status  [ guild_id => [ 'state' => active|paused|none|error|needs_reconnect, 'roles' => role IDs held via TierCord, ... ] ].
		 */
		do_action( 'tiercord_user_synced', $user_id, $status );

		return $status;
	}

	/**
	 * Last saved status, upgraded from the old one-role format if needed.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public static function previous_status( $user_id ) {
		$previous = get_user_meta( $user_id, 'tiercord_status', true );
		$previous = is_array( $previous ) ? $previous : array();
		foreach ( $previous as $guild_id => $prev ) {
			if ( ! isset( $prev['roles'] ) ) {
				$previous[ $guild_id ]['roles'] = ( 'active' === ( $prev['state'] ?? '' ) && ! empty( $prev['role_id'] ) ) ? array( (string) $prev['role_id'] ) : array();
			}
		}
		return $previous;
	}

	/**
	 * Make the member's managed roles in one server match $want exactly.
	 *
	 * @param int        $user_id    WordPress user ID.
	 * @param string     $discord_id Discord user ID.
	 * @param string     $guild_id   Server ID.
	 * @param array      $server     [ 'name', 'roles' => managed roles ].
	 * @param string[]   $want       Role IDs the member should have in this server.
	 * @param array|null $prev       Previous status for this server.
	 * @return array Status.
	 */
	private static function sync_server( $user_id, $discord_id, $guild_id, $server, $want, $prev = null ) {
		$name    = $server['name'];
		$managed = array_map( 'strval', array_keys( $server['roles'] ) );
		$want    = array_values( array_intersect( array_map( 'strval', $want ), $managed ) );

		// Roles we handed out earlier that are no longer managed must be taken back too.
		$prev_roles = $prev && ! empty( $prev['roles'] ) ? array_map( 'strval', $prev['roles'] ) : array();
		$removable  = array_values( array_unique( array_merge( $managed, $prev_roles ) ) );
		$was_active = $prev && 'active' === ( $prev['state'] ?? '' );
		$was_paused = $prev && 'paused' === ( $prev['state'] ?? '' );

		$member = TierCord_API::get_member( $guild_id, $discord_id );

		/* -------- Not in the server yet -------- */
		if ( 404 === $member['code'] ) {
			if ( ! $want ) {
				return self::state( ( $was_active || $was_paused ) ? 'paused' : 'none', array() );
			}

			$token = self::valid_access_token( $user_id );
			if ( '' === $token ) {
				TierCord_Helpers::log( sprintf( 'Cannot add to "%s": Discord authorisation expired, member must reconnect.', $name ), 'warning', $user_id );
				return self::state( 'needs_reconnect', array() );
			}

			$res = TierCord_API::add_member( $guild_id, $discord_id, $token, $want );
			if ( 201 === $res['code'] ) {
				TierCord_Helpers::log( sprintf( 'Added to "%s" with %d role(s).', $name, count( $want ) ), 'info', $user_id );
				return self::state( 'active', $want );
			}
			if ( 204 !== $res['code'] ) {
				TierCord_Helpers::log( sprintf( 'Could not add to "%s": %s (the bot needs Create Invite permission; the member may have reached Discord\'s server limit).', $name, $res['error'] ), 'error', $user_id );
				return self::state( 'error', array(), $res['error'] );
			}
			// 204 = joined in the meantime; roles are not applied in that case, so continue below.
			$current = array();
		} elseif ( ! TierCord_API::ok( $member ) ) {
			TierCord_Helpers::log( sprintf( 'Could not read membership in "%s": %s', $name, $member['error'] ), 'error', $user_id );
			return self::state( 'error', $prev_roles, $member['error'] );
		} else {
			$current = isset( $member['body']['roles'] ) ? array_map( 'strval', (array) $member['body']['roles'] ) : array();
		}

		$held_managed = array_values( array_intersect( $current, $removable ) );

		/* -------- Nothing allowed here any more: pause -------- */
		if ( ! $want && 'kick' === TierCord_Helpers::get( 'on_expire' ) && ( $held_managed || $was_active ) ) {
			$res = TierCord_API::kick( $guild_id, $discord_id );
			if ( TierCord_API::ok( $res ) || 404 === $res['code'] ) {
				TierCord_Helpers::log( sprintf( 'Access to "%s" paused (removed from server).', $name ), 'info', $user_id );
				return self::state( 'paused', array() );
			}
			TierCord_Helpers::log( sprintf( 'Could not remove from "%s": %s', $name, $res['error'] ), 'error', $user_id );
			return self::state( 'error', $held_managed, $res['error'] );
		}

		/* -------- Add missing roles, remove managed roles that are no longer allowed -------- */
		$errors = array();
		$have   = $held_managed;

		foreach ( array_diff( $want, $current ) as $role_id ) {
			$res = TierCord_API::add_role( $guild_id, $discord_id, $role_id );
			if ( TierCord_API::ok( $res ) ) {
				$have[] = $role_id;
			} else {
				$errors[] = $res['error'];
				TierCord_Helpers::log( sprintf( 'Could not give role "%s" in "%s": %s (the bot\'s role must be above it and have Manage Roles).', $server['roles'][ $role_id ] ?? $role_id, $name, $res['error'] ), 'error', $user_id );
			}
		}

		foreach ( array_diff( $held_managed, $want ) as $role_id ) {
			$res = TierCord_API::remove_role( $guild_id, $discord_id, $role_id );
			if ( TierCord_API::ok( $res ) || 404 === $res['code'] ) {
				$have = array_diff( $have, array( $role_id ) );
			} else {
				$errors[] = $res['error'];
				TierCord_Helpers::log( sprintf( 'Could not remove role "%s" in "%s": %s', $server['roles'][ $role_id ] ?? $role_id, $name, $res['error'] ), 'error', $user_id );
			}
		}

		$have = array_values( array_unique( array_intersect( $have, $removable ) ) );

		if ( $errors ) {
			return self::state( 'error', $have, implode( '; ', array_unique( $errors ) ) );
		}
		if ( $want ) {
			return self::state( 'active', $want );
		}
		if ( $held_managed || $was_active ) {
			TierCord_Helpers::log( sprintf( 'Access to "%s" paused (roles removed).', $name ), 'info', $user_id );
			return self::state( 'paused', array() );
		}
		return self::state( $was_paused ? 'paused' : 'none', array() );
	}

	/**
	 * Take every managed role away (used on disconnect and user deletion).
	 *
	 * @param int $user_id User ID.
	 */
	public static function revoke_everything( $user_id ) {
		$discord_id = TierCord_Helpers::discord_id( $user_id );
		if ( '' === $discord_id || ! TierCord_Helpers::is_configured() ) {
			return;
		}
		$previous = self::previous_status( $user_id );
		foreach ( TierCord_Helpers::active_servers() as $guild_id => $server ) {
			self::sync_server( $user_id, $discord_id, $guild_id, $server, array(), isset( $previous[ $guild_id ] ) ? $previous[ $guild_id ] : null );
		}
	}

	/**
	 * Build a status entry.
	 *
	 * @param string   $state   active | paused | none | error | needs_reconnect.
	 * @param string[] $roles   Managed role IDs the member holds in this server.
	 * @param string   $message Error message, if any.
	 * @return array
	 */
	private static function state( $state, $roles, $message = '' ) {
		return array(
			'state'   => $state,
			'roles'   => array_values( array_map( 'strval', $roles ) ),
			'message' => $message,
			'time'    => time(),
		);
	}

	/**
	 * Return a non-expired access token, refreshing it if needed. Empty string if the user must reconnect.
	 */
	public static function valid_access_token( $user_id ) {
		$expires = (int) get_user_meta( $user_id, 'tiercord_token_expires', true );
		$token   = TierCord_Helpers::access_token( $user_id );

		if ( '' !== $token && $expires > time() ) {
			return $token;
		}

		$refresh = TierCord_Helpers::refresh_token( $user_id );
		if ( '' === $refresh ) {
			update_user_meta( $user_id, 'tiercord_needs_reconnect', 1 );
			return '';
		}

		$res = TierCord_API::refresh( $refresh );
		if ( ! TierCord_API::ok( $res ) || empty( $res['body']['access_token'] ) ) {
			update_user_meta( $user_id, 'tiercord_needs_reconnect', 1 );
			return '';
		}

		TierCord_Helpers::save_tokens( $user_id, $res['body'] );
		delete_user_meta( $user_id, 'tiercord_needs_reconnect' );
		return $res['body']['access_token'];
	}

	/* ------------------------------------------------------------------
	 * Full resync (daily safety net + "Sync all now" button)
	 * ------------------------------------------------------------------ */

	public static function start_full_resync() {
		wp_unschedule_hook( 'tiercord_resync_batch' );
		wp_schedule_single_event( time(), 'tiercord_resync_batch', array( 0 ) );
		update_option( 'tiercord_resync_started', time(), false );
	}

	public static function run_batch( $offset ) {
		$offset = (int) $offset;

		$users = get_users(
			array(
				'meta_key'     => 'tiercord_discord_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- batched background job.
				'meta_compare' => 'EXISTS',
				'fields'       => 'ID',
				'number'       => self::BATCH_SIZE,
				'offset'       => $offset,
				'orderby'      => 'ID',
				'order'        => 'ASC',
			)
		);

		foreach ( $users as $user_id ) {
			self::sync_user( (int) $user_id );
		}

		if ( count( $users ) === self::BATCH_SIZE ) {
			wp_schedule_single_event( time() + 60, 'tiercord_resync_batch', array( $offset + self::BATCH_SIZE ) );
		} else {
			update_option( 'tiercord_resync_finished', time(), false );
			TierCord_Helpers::log( sprintf( 'Full resync finished (%d linked members).', $offset + count( $users ) ), 'info' );
		}
	}
}
