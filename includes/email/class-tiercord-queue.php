<?php
/**
 * Background send queue. Large mailings are sent in batches (default 40/minute)
 * via WP-Cron so publishing a post never times out and hosts' hourly limits are respected.
 *
 * Audiences:
 *   [ 'type' => 'levels', 'levels' => [1,2] ]  active members of these PMPro levels
 *   [ 'type' => 'all_members' ]                every active member
 *   [ 'type' => 'guild', 'guild' => '123' ]    members who currently have access to this Discord server
 *   [ 'type' => 'roles', 'guild' => '123', 'roles' => ['456'] ]  members who currently hold any of these roles
 *
 * @package TierCord
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TierCord_Queue {

	const OPTION   = 'tiercord_em_jobs';
	const KEEP     = 50;  // Finished jobs kept for the History tab.

	public static function init() {
		add_action( 'tiercord_em_process_queue', array( __CLASS__, 'process' ) );

		// Self-heal: if there is pending work but nothing scheduled (e.g. cron was cleared), schedule it.
		add_action(
			'init',
			function () {
				if ( self::has_pending() && ! wp_next_scheduled( 'tiercord_em_process_queue' ) ) {
					wp_schedule_single_event( time() + 30, 'tiercord_em_process_queue' );
				}
			}
		);
	}

	/* ------------------------------------------------------------------
	 * Jobs
	 * ------------------------------------------------------------------ */

	public static function jobs() {
		$jobs = get_option( self::OPTION, array() );
		return is_array( $jobs ) ? $jobs : array();
	}

	private static function save_jobs( $jobs ) {
		// Prune old finished jobs.
		$finished = array_filter(
			$jobs,
			function ( $j ) {
				return in_array( $j['status'], array( 'done', 'cancelled' ), true );
			}
		);
		if ( count( $finished ) > self::KEEP ) {
			uasort(
				$finished,
				function ( $a, $b ) {
					return $a['created'] - $b['created'];
				}
			);
			foreach ( array_slice( array_keys( $finished ), 0, count( $finished ) - self::KEEP ) as $old ) {
				unset( $jobs[ $old ] );
			}
		}
		update_option( self::OPTION, $jobs, false );
	}

	public static function has_pending() {
		foreach ( self::jobs() as $job ) {
			if ( in_array( $job['status'], array( 'queued', 'sending' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Queue a mailing.
	 *
	 * @param array $args { title, category, subject, body, vars, audience, source }
	 * @return string Job ID.
	 */
	public static function add( $args ) {
		$id   = 'j' . time() . wp_rand( 100, 999 );
		$jobs = self::jobs();

		$jobs[ $id ] = array(
			'id'       => $id,
			'title'    => (string) ( $args['title'] ?? $args['subject'] ),
			'category' => (string) $args['category'],
			'subject'  => (string) $args['subject'],
			'body'     => (string) $args['body'],
			'vars'     => (array) ( $args['vars'] ?? array() ),
			'audience' => (array) $args['audience'],
			'source'   => (string) ( $args['source'] ?? 'manual' ),
			'total'    => self::count_audience( $args['audience'] ),
			'cursor'   => 0,
			'sent'     => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'status'   => 'queued',
			'created'  => time(),
			'finished' => 0,
		);
		self::save_jobs( $jobs );

		TierCord_Email::log( sprintf( 'Queued "%s" for %s (~%d recipients).', $jobs[ $id ]['title'], self::describe_audience( $args['audience'] ), $jobs[ $id ]['total'] ) );

		if ( ! wp_next_scheduled( 'tiercord_em_process_queue' ) ) {
			wp_schedule_single_event( time(), 'tiercord_em_process_queue' );
		}
		return $id;
	}

	public static function cancel( $id ) {
		$jobs = self::jobs();
		if ( isset( $jobs[ $id ] ) && in_array( $jobs[ $id ]['status'], array( 'queued', 'sending' ), true ) ) {
			$jobs[ $id ]['status']   = 'cancelled';
			$jobs[ $id ]['finished'] = time();
			self::save_jobs( $jobs );
		}
	}

	/* ------------------------------------------------------------------
	 * Processing
	 * ------------------------------------------------------------------ */

	public static function process() {
		if ( get_transient( 'tiercord_em_lock' ) ) {
			return;
		}
		set_transient( 'tiercord_em_lock', 1, 90 );

		$budget = max( 1, (int) TierCord_Email::get( 'batch_size' ) );
		$jobs   = self::jobs();

		uasort(
			$jobs,
			function ( $a, $b ) {
				return $a['created'] - $b['created'];
			}
		);

		foreach ( $jobs as $id => $job ) {
			if ( $budget <= 0 ) {
				break;
			}
			if ( ! in_array( $job['status'], array( 'queued', 'sending' ), true ) ) {
				continue;
			}

			$job['status'] = 'sending';
			$requested     = $budget;
			$ids           = self::next_recipients( $job['audience'], $job['cursor'], $requested );

			foreach ( $ids as $user_id ) {
				$job['cursor'] = $user_id;
				$budget--;

				if ( TierCord_Preferences::opted_out( $user_id, $job['category'] ) ) {
					$job['skipped']++;
					continue;
				}
				if ( TierCord_Mailer::send( $user_id, $job['subject'], $job['body'], $job['vars'], $job['category'] ) ) {
					$job['sent']++;
				} else {
					$job['failed']++;
				}
			}

			if ( count( $ids ) < $requested ) {
				// Fewer recipients than we asked for → audience exhausted.
				$job['status']   = 'done';
				$job['finished'] = time();
				TierCord_Email::log( sprintf( 'Finished "%s": %d sent, %d opted out, %d failed.', $job['title'], $job['sent'], $job['skipped'], $job['failed'] ), $job['failed'] ? 'warning' : 'info' );
			}

			$jobs[ $id ] = $job;
			// Persist after each job so a timeout never causes double-sending.
			$fresh = self::jobs();
			if ( isset( $fresh[ $id ] ) && 'cancelled' === $fresh[ $id ]['status'] ) {
				$job['status'] = 'cancelled';
			}
			$fresh[ $id ] = $job;
			self::save_jobs( $fresh );
		}

		delete_transient( 'tiercord_em_lock' );

		if ( self::has_pending() ) {
			wp_schedule_single_event( time() + 60, 'tiercord_em_process_queue' );
		}
	}

	/* ------------------------------------------------------------------
	 * Audiences
	 * ------------------------------------------------------------------ */

	/**
	 * Next batch of user IDs greater than $after_id (stable cursor even if members join/leave mid-send).
	 *
	 * @return int[]
	 */
	public static function next_recipients( $audience, $after_id, $limit ) {
		global $wpdb;
		$after_id = (int) $after_id;
		$limit    = max( 1, (int) $limit );
		$type     = $audience['type'] ?? '';

		// Live reads on purpose: a mailing must use current access data, so results are not cached.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( 'guild' === $type ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND user_id > %d ORDER BY user_id ASC LIMIT %d",
					TierCord_Email::guild_key( $audience['guild'] ?? '' ),
					$after_id,
					$limit
				)
			);
		} elseif ( 'roles' === $type ) {
			$keys = self::role_meta_keys( $audience );
			if ( ! $keys ) {
				return array();
			}
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN (" . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ') AND user_id > %d ORDER BY user_id ASC LIMIT %d',
					array_merge( $keys, array( $after_id, $limit ) )
				)
			);
		} elseif ( 'all_members' === $type ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT user_id FROM {$wpdb->pmpro_memberships_users} WHERE status = 'active' AND user_id > %d ORDER BY user_id ASC LIMIT %d",
					$after_id,
					$limit
				)
			);
		} elseif ( 'levels' === $type ) {
			$levels = self::level_ids( $audience );
			if ( ! $levels ) {
				return array();
			}
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT user_id FROM {$wpdb->pmpro_memberships_users} WHERE status = 'active' AND membership_id IN (" . implode( ', ', array_fill( 0, count( $levels ), '%d' ) ) . ') AND user_id > %d ORDER BY user_id ASC LIMIT %d',
					array_merge( $levels, array( $after_id, $limit ) )
				)
			);
		} else {
			$ids = array();
		}
		// phpcs:enable

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Number of distinct recipients in an audience.
	 *
	 * @param array $audience Audience definition.
	 * @return int
	 */
	public static function count_audience( $audience ) {
		global $wpdb;
		$type = $audience['type'] ?? '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live count shown before sending.
		if ( 'guild' === $type ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s",
					TierCord_Email::guild_key( $audience['guild'] ?? '' )
				)
			);
		}
		if ( 'roles' === $type ) {
			$keys = self::role_meta_keys( $audience );
			if ( ! $keys ) {
				return 0;
			}
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key IN (" . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ')',
					$keys
				)
			);
		}
		if ( 'all_members' === $type ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->pmpro_memberships_users} WHERE status = %s",
					'active'
				)
			);
		}
		if ( 'levels' === $type ) {
			$levels = self::level_ids( $audience );
			if ( ! $levels ) {
				return 0;
			}
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->pmpro_memberships_users} WHERE status = 'active' AND membership_id IN (" . implode( ', ', array_fill( 0, count( $levels ), '%d' ) ) . ')',
					$levels
				)
			);
		}
		// phpcs:enable
		return 0;
	}

	/**
	 * Usermeta keys for a roles audience.
	 *
	 * @param array $audience Audience definition.
	 * @return string[]
	 */
	private static function role_meta_keys( $audience ) {
		$keys = array();
		foreach ( (array) ( $audience['roles'] ?? array() ) as $role_id ) {
			$role_id = preg_replace( '/\D/', '', (string) $role_id );
			if ( '' !== $role_id ) {
				$keys[] = TierCord_Email::role_key( $role_id );
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Clean list of level IDs from an audience definition.
	 *
	 * @param array $audience Audience definition.
	 * @return int[]
	 */
	private static function level_ids( $audience ) {
		return array_values( array_filter( array_map( 'absint', (array) ( $audience['levels'] ?? array() ) ) ) );
	}

	public static function describe_audience( $audience ) {
		switch ( $audience['type'] ?? '' ) {
			case 'guild':
				/* translators: %s: Discord server name. */
				return sprintf( __( 'members with access to %s', 'tiercord' ), TierCord_Email::server_name( $audience['guild'] ) );
			case 'roles':
				$names = array();
				foreach ( (array) ( $audience['roles'] ?? array() ) as $role_id ) {
					$names[] = TierCord_Helpers::role_label( (string) ( $audience['guild'] ?? '' ), (string) $role_id );
				}
				/* translators: %s: Discord role names. */
				return sprintf( __( 'members with %s', 'tiercord' ), implode( ', ', $names ) );
			case 'levels':
				return implode( ', ', TierCord_Email::level_names( $audience['levels'] ) ) . ' ' . __( 'members', 'tiercord' );
			case 'all_members':
				return __( 'all active members', 'tiercord' );
		}
		return '?';
	}
}
