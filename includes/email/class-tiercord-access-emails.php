<?php
/**
 * Listens to TierCord via the `tiercord_user_synced` hook.
 *
 *  1. Keeps an index of which Discord roles (and servers) each member currently has through TierCord:
 *     usermeta tiercord_em_role_{role_id} = server ID, and tiercord_em_guild_{server_id} = 1.
 *     Used to email exactly the members who can see a channel when a Discord announcement is relayed.
 *  2. Sends access emails when that changes: welcome (roles gained), paused (roles lost), reconnect needed.
 *
 * @package TierCord
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Access-change emails and the role index.
 */
class TierCord_Access_Emails {

	const BATCH  = 100;
	const SCHEMA = 2; // Bumped when the index format changes; the first sync after that is recorded silently.

	public static function init() {
		add_action( 'tiercord_user_synced', array( __CLASS__, 'on_synced' ), 10, 2 );
		add_action( 'tiercord_em_backfill', array( __CLASS__, 'backfill' ) );

		// When a member disconnects Discord (or an admin unlinks them), forget their access.
		add_action( 'deleted_user_meta', array( __CLASS__, 'on_meta_deleted' ), 10, 3 );
	}

	/**
	 * Update the index and send access emails.
	 *
	 * @param int   $user_id User ID.
	 * @param array $status  [ guild_id => [ 'state' => active|paused|none|error|needs_reconnect, 'roles' => [ role_id ] ] ].
	 */
	public static function on_synced( $user_id, $status ) {
		$user_id = (int) $user_id;
		$status  = is_array( $status ) ? $status : array();

		$known  = (bool) get_user_meta( $user_id, 'tiercord_em_init', true );
		$schema = (int) get_user_meta( $user_id, 'tiercord_em_schema', true );
		// Record silently: members seen before the activation backfill finished, and members indexed by an older version.
		$silent = ( ! $known && ! get_option( 'tiercord_em_backfill_done' ) ) || ( $known && $schema < self::SCHEMA );

		$indexed   = self::indexed_roles( $user_id ); // role_id => guild_id.
		$gained    = array();
		$lost      = array();
		$reconnect = array();

		foreach ( $status as $guild_id => $st ) {
			$guild_id = (string) $guild_id;
			$state    = isset( $st['state'] ) ? $st['state'] : 'none';

			if ( 'error' === $state ) {
				// Temporary Discord problem: don't flip anything, the next sync settles it.
				continue;
			}

			$now = 'active' === $state && ! empty( $st['roles'] ) ? array_map( 'strval', $st['roles'] ) : array();

			// Server index.
			if ( $now ) {
				update_user_meta( $user_id, TierCord_Email::guild_key( $guild_id ), 1 );
			} else {
				delete_user_meta( $user_id, TierCord_Email::guild_key( $guild_id ) );
			}

			// Role index.
			foreach ( $now as $role_id ) {
				if ( ! isset( $indexed[ $role_id ] ) ) {
					update_user_meta( $user_id, TierCord_Email::role_key( $role_id ), $guild_id );
					$gained[] = array( $guild_id, $role_id );
				}
			}
			foreach ( $indexed as $role_id => $role_guild ) {
				if ( (string) $role_guild === $guild_id && ! in_array( (string) $role_id, $now, true ) ) {
					delete_user_meta( $user_id, TierCord_Email::role_key( $role_id ) );
					$lost[] = array( $guild_id, (string) $role_id );
				}
			}

			if ( 'needs_reconnect' === $state ) {
				$reconnect[] = $guild_id;
			}
		}

		// Servers no longer managed at all: drop silently.
		foreach ( $indexed as $role_id => $role_guild ) {
			if ( ! isset( $status[ $role_guild ] ) ) {
				delete_user_meta( $user_id, TierCord_Email::role_key( $role_id ) );
				delete_user_meta( $user_id, TierCord_Email::guild_key( $role_guild ) );
			}
		}

		update_user_meta( $user_id, 'tiercord_em_init', 1 );
		update_user_meta( $user_id, 'tiercord_em_schema', self::SCHEMA );

		if ( $gained ) {
			delete_user_meta( $user_id, 'tiercord_em_reconnect_sent' );
		}

		if ( $silent ) {
			return;
		}

		$s = TierCord_Email::settings();

		if ( $gained && $s['welcome_enabled'] ) {
			TierCord_Mailer::send( $user_id, $s['welcome_subject'], $s['welcome_body'], self::access_vars( $gained ), 'account' );
		}
		if ( $lost && $s['paused_enabled'] ) {
			TierCord_Mailer::send( $user_id, $s['paused_subject'], $s['paused_body'], self::access_vars( $lost ), 'account' );
		}
		if ( $reconnect && $s['reconnect_enabled'] ) {
			// At most once a week; the daily re-sync would otherwise nag every day.
			$last = (int) get_user_meta( $user_id, 'tiercord_em_reconnect_sent', true );
			if ( $last < time() - WEEK_IN_SECONDS ) {
				TierCord_Mailer::send( $user_id, $s['reconnect_subject'], $s['reconnect_body'], self::server_vars( $reconnect ), 'account' );
				update_user_meta( $user_id, 'tiercord_em_reconnect_sent', time() );
			}
		}
	}

	/**
	 * Placeholders describing a list of roles: {access_names}, {access_list} (and the older {server_names}, {server_list}).
	 *
	 * @param array $pairs List of [ guild_id, role_id ].
	 * @return array
	 */
	private static function access_vars( $pairs ) {
		$names = array();
		$items = '';
		foreach ( $pairs as $pair ) {
			$label   = TierCord_Helpers::role_label( $pair[0], $pair[1] );
			$names[] = $label;
			$items  .= '<li><a href="' . esc_url( 'https://discord.com/channels/' . $pair[0] ) . '">' . esc_html( $label ) . '</a></li>';
		}
		$list = '<ul>' . $items . '</ul>';
		return array(
			'access_names' => implode( ', ', $names ),
			'access_list'  => $list,
			'server_names' => implode( ', ', $names ),
			'server_list'  => $list,
		);
	}

	/**
	 * Placeholders describing a list of servers.
	 *
	 * @param string[] $guild_ids Server IDs.
	 * @return array
	 */
	private static function server_vars( $guild_ids ) {
		$names = array_map( array( 'TierCord_Email', 'server_name' ), $guild_ids );
		$items = '';
		foreach ( $guild_ids as $gid ) {
			$items .= '<li><a href="' . esc_url( 'https://discord.com/channels/' . $gid ) . '">' . esc_html( TierCord_Email::server_name( $gid ) ) . '</a></li>';
		}
		$list = '<ul>' . $items . '</ul>';
		return array(
			'server_names' => implode( ', ', $names ),
			'server_list'  => $list,
			'access_names' => implode( ', ', $names ),
			'access_list'  => $list,
		);
	}

	/**
	 * Roles currently indexed for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array [ role_id => guild_id ]
	 */
	public static function indexed_roles( $user_id ) {
		$out = array();
		foreach ( (array) get_user_meta( $user_id ) as $key => $values ) {
			if ( 0 === strpos( $key, TierCord_Email::ROLE_PREFIX ) ) {
				$out[ substr( $key, strlen( TierCord_Email::ROLE_PREFIX ) ) ] = (string) ( is_array( $values ) ? reset( $values ) : $values );
			}
		}
		return $out;
	}

	/**
	 * Servers currently indexed for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string[]
	 */
	public static function indexed_guilds( $user_id ) {
		$out = array();
		foreach ( array_keys( (array) get_user_meta( $user_id ) ) as $key ) {
			if ( 0 === strpos( $key, TierCord_Email::GUILD_PREFIX ) ) {
				$out[] = substr( $key, strlen( TierCord_Email::GUILD_PREFIX ) );
			}
		}
		return $out;
	}

	/**
	 * Forget a member's access when their Discord account is unlinked.
	 *
	 * @param array  $meta_ids  Deleted meta IDs.
	 * @param int    $user_id   User ID.
	 * @param string $meta_key  Meta key.
	 */
	public static function on_meta_deleted( $meta_ids, $user_id, $meta_key ) {
		if ( 'tiercord_discord_id' !== $meta_key ) {
			return;
		}
		foreach ( array_keys( self::indexed_roles( $user_id ) ) as $role_id ) {
			delete_user_meta( $user_id, TierCord_Email::role_key( $role_id ) );
		}
		foreach ( self::indexed_guilds( $user_id ) as $guild_id ) {
			delete_user_meta( $user_id, TierCord_Email::guild_key( $guild_id ) );
		}
	}

	/**
	 * Activation backfill: index everyone who already has access, without sending emails.
	 *
	 * @param int $offset Batch offset.
	 */
	public static function backfill( $offset ) {
		$offset = (int) $offset;
		$users  = get_users(
			array(
				'meta_key'     => 'tiercord_discord_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- batched background job.
				'meta_compare' => 'EXISTS',
				'fields'       => 'ID',
				'number'       => self::BATCH,
				'offset'       => $offset,
				'orderby'      => 'ID',
				'order'        => 'ASC',
			)
		);

		foreach ( $users as $user_id ) {
			foreach ( TierCord_Sync::previous_status( $user_id ) as $guild_id => $st ) {
				if ( 'active' === ( $st['state'] ?? '' ) && ! empty( $st['roles'] ) ) {
					update_user_meta( $user_id, TierCord_Email::guild_key( $guild_id ), 1 );
					foreach ( $st['roles'] as $role_id ) {
						update_user_meta( $user_id, TierCord_Email::role_key( $role_id ), (string) $guild_id );
					}
				}
			}
			update_user_meta( $user_id, 'tiercord_em_init', 1 );
			update_user_meta( $user_id, 'tiercord_em_schema', self::SCHEMA );
		}

		if ( count( $users ) === self::BATCH ) {
			wp_schedule_single_event( time() + 10, 'tiercord_em_backfill', array( $offset + self::BATCH ) );
		} else {
			update_option( 'tiercord_em_backfill_done', 1, false );
			TierCord_Email::log( sprintf( 'Indexed Discord access for %d existing members.', $offset + count( $users ) ) );
		}
	}
}
