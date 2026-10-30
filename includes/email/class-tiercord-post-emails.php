<?php
/**
 * New-post emails.
 *
 * Audience = the levels the post is restricted to in PMPro ("Require Membership" box, or its
 * category's restriction). Unrestricted posts go to all active members (or nobody — setting).
 * A per-post "Email members" box lets you switch it off or pick levels by hand.
 *
 * Sending is delayed a couple of minutes after publishing: this gives time to fix typos, and
 * makes sure the block editor has saved the meta box before we read it.
 *
 * @package TierCord
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TierCord_Post_Emails {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_box' ), 10, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
		add_action( 'tiercord_em_post_published', array( __CLASS__, 'send_for_post' ) );
	}

	private static function enabled_for( $post_type ) {
		return TierCord_Email::get( 'posts_enabled' ) && in_array( $post_type, (array) TierCord_Email::get( 'post_types' ), true );
	}

	/* ------------------------------------------------------------------
	 * Audience
	 * ------------------------------------------------------------------ */

	/**
	 * PMPro levels that may read a post (post restriction + category restriction).
	 *
	 * @return int[] Empty when the post is not restricted.
	 */
	public static function restricted_levels( $post_id ) {
		global $wpdb;
		$levels = array();

		// PMPro keeps restrictions in its own tables and has no API that returns them for a post,
		// so read them directly (once per published post).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! empty( $wpdb->pmpro_memberships_pages ) ) {
			$levels = $wpdb->get_col( $wpdb->prepare( "SELECT membership_id FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = %d", $post_id ) );
		}

		if ( ! empty( $wpdb->pmpro_memberships_categories ) && 'post' === get_post_type( $post_id ) ) {
			$cats = array_map( 'absint', wp_get_post_categories( $post_id ) );
			if ( $cats ) {
				$levels = array_merge(
					$levels,
					$wpdb->get_col(
						$wpdb->prepare(
							"SELECT membership_id FROM {$wpdb->pmpro_memberships_categories} WHERE category_id IN (" . implode( ', ', array_fill( 0, count( $cats ), '%d' ) ) . ')',
							$cats
						)
					)
				);
			}
		}
		// phpcs:enable

		return array_values( array_unique( array_map( 'intval', $levels ) ) );
	}

	/**
	 * @return array|null Audience for the queue, or null if nobody should be emailed.
	 */
	public static function audience_for( $post_id ) {
		if ( 'custom' === get_post_meta( $post_id, '_tiercord_em_mode', true ) ) {
			$levels = array_filter( array_map( 'absint', (array) get_post_meta( $post_id, '_tiercord_em_levels', true ) ) );
			return $levels ? array( 'type' => 'levels', 'levels' => array_values( $levels ) ) : null;
		}

		$levels = self::restricted_levels( $post_id );
		if ( $levels ) {
			return array( 'type' => 'levels', 'levels' => $levels );
		}
		return 'all_members' === TierCord_Email::get( 'unrestricted_to' ) ? array( 'type' => 'all_members' ) : null;
	}

	/* ------------------------------------------------------------------
	 * Meta box
	 * ------------------------------------------------------------------ */

	public static function add_box( $post_type ) {
		if ( ! self::enabled_for( $post_type ) ) {
			return;
		}
		add_meta_box( 'tiercord_em_box', __( 'Email members', 'tiercord' ), array( __CLASS__, 'render_box' ), $post_type, 'side', 'default' );
	}

	public static function render_box( $post ) {
		wp_nonce_field( 'tiercord_em_box', 'tiercord_em_box_nonce' );

		$notify = get_post_meta( $post->ID, '_tiercord_em_notify', true );
		$notify = '' === $notify ? (int) TierCord_Email::get( 'notify_default' ) : (int) $notify;
		$mode   = get_post_meta( $post->ID, '_tiercord_em_mode', true ) === 'custom' ? 'custom' : 'auto';
		$chosen = array_map( 'intval', (array) get_post_meta( $post->ID, '_tiercord_em_levels', true ) );
		$sent   = (int) get_post_meta( $post->ID, '_tiercord_em_sent', true );
		$levels = pmpro_getAllLevels( true, true );

		$auto   = self::restricted_levels( $post->ID );
		$auto_t = $auto
			? implode( ', ', TierCord_Email::level_names( $auto ) )
			: ( 'all_members' === TierCord_Email::get( 'unrestricted_to' ) ? __( 'all active members (post is not restricted)', 'tiercord' ) : __( 'nobody (post is not restricted)', 'tiercord' ) );

		if ( $sent ) {
			/* translators: %s: date and time the post was emailed. */
			$sent_text = sprintf( __( 'Emailed on %s.', 'tiercord' ), wp_date( 'j M Y H:i', $sent ) );
			echo '<p class="tiercord-em-sent">' . esc_html( $sent_text ) . '</p>';
			echo '<p><label><input type="checkbox" name="tiercord_em_resend" value="1"> ' . esc_html__( 'Email again when I click Update', 'tiercord' ) . '</label></p>';
			echo '<input type="hidden" name="tiercord_em_notify" value="' . (int) $notify . '">';
		} else {
			echo '<p><label><input type="checkbox" name="tiercord_em_notify" value="1" ' . checked( $notify, 1, false ) . '> ' . esc_html__( 'Email members when published', 'tiercord' ) . '</label></p>';
		}
		?>
		<p><strong><?php esc_html_e( 'Send to', 'tiercord' ); ?></strong></p>
		<p><label><input type="radio" name="tiercord_em_mode" value="auto" <?php checked( $mode, 'auto' ); ?>> <?php esc_html_e( 'Levels that can read this post', 'tiercord' ); ?></label></p>
		<p class="description"><?php echo esc_html( $auto_t ); ?></p>
		<p><label><input type="radio" name="tiercord_em_mode" value="custom" <?php checked( $mode, 'custom' ); ?>> <?php esc_html_e( 'These levels:', 'tiercord' ); ?></label></p>
		<div class="tiercord-em-levels">
			<?php foreach ( $levels as $level ) : ?>
				<label><input type="checkbox" name="tiercord_em_levels[]" value="<?php echo (int) $level->id; ?>" <?php checked( in_array( (int) $level->id, $chosen, true ) ); ?>> <?php echo esc_html( $level->name ); ?></label>
			<?php endforeach; ?>
		</div>
		<?php
		/* translators: %d: number of minutes. */
		$delay_text = sprintf( __( 'Sent %d minute(s) after publishing. Members who unsubscribed from new-post emails are skipped.', 'tiercord' ), max( 1, (int) TierCord_Email::get( 'post_delay' ) ) );
		?>
		<p class="description"><?php echo esc_html( $delay_text ); ?></p>
		<?php
	}

	public static function save_box( $post_id, $post ) {
		if ( ! isset( $_POST['tiercord_em_box_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tiercord_em_box_nonce'] ) ), 'tiercord_em_box' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, '_tiercord_em_notify', empty( $_POST['tiercord_em_notify'] ) ? 0 : 1 );
		update_post_meta( $post_id, '_tiercord_em_mode', ( isset( $_POST['tiercord_em_mode'] ) && 'custom' === $_POST['tiercord_em_mode'] ) ? 'custom' : 'auto' );
		update_post_meta( $post_id, '_tiercord_em_levels', isset( $_POST['tiercord_em_levels'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['tiercord_em_levels'] ) ) : array() );

		// "Email again" on an already-published post.
		if ( ! empty( $_POST['tiercord_em_resend'] ) && 'publish' === $post->post_status ) {
			update_post_meta( $post_id, '_tiercord_em_resend', 1 );
			self::schedule( $post_id );
		}
	}

	/* ------------------------------------------------------------------
	 * Trigger
	 * ------------------------------------------------------------------ */

	public static function on_transition( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status || ! self::enabled_for( $post->post_type ) ) {
			return;
		}
		if ( wp_is_post_revision( $post ) || ! empty( $post->post_password ) ) {
			return;
		}
		self::schedule( $post->ID );
	}

	private static function schedule( $post_id ) {
		wp_clear_scheduled_hook( 'tiercord_em_post_published', array( (int) $post_id ) );
		$delay = max( 1, (int) TierCord_Email::get( 'post_delay' ) ) * MINUTE_IN_SECONDS;
		wp_schedule_single_event( time() + $delay, 'tiercord_em_post_published', array( (int) $post_id ) );
	}

	public static function send_for_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || ! self::enabled_for( $post->post_type ) ) {
			return;
		}

		$notify = get_post_meta( $post_id, '_tiercord_em_notify', true );
		if ( '' === $notify ) {
			$notify = TierCord_Email::get( 'notify_default' ); // Published without the meta box (REST, app, import…).
		}
		$resend = (bool) get_post_meta( $post_id, '_tiercord_em_resend', true );
		if ( ! $notify && ! $resend ) {
			return;
		}
		if ( get_post_meta( $post_id, '_tiercord_em_sent', true ) && ! $resend ) {
			return; // Never email the same post twice by accident (e.g. unpublish → republish).
		}

		$audience = self::audience_for( $post_id );
		delete_post_meta( $post_id, '_tiercord_em_resend' );
		if ( ! $audience ) {
			return;
		}

		$excerpt = has_excerpt( $post ) ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 45 );
		$s       = TierCord_Email::settings();

		TierCord_Queue::add(
			array(
				/* translators: %s: post title. */
				'title'    => sprintf( __( 'New post: %s', 'tiercord' ), get_the_title( $post ) ),
				'category' => 'posts',
				'subject'  => $s['post_subject'],
				'body'     => $s['post_body'],
				'vars'     => array(
					'post_title'   => html_entity_decode( get_the_title( $post ), ENT_QUOTES ),
					'post_url'     => get_permalink( $post ),
					'post_excerpt' => esc_html( $excerpt ),
					'author'       => get_the_author_meta( 'display_name', $post->post_author ),
				),
				'audience' => $audience,
				'source'   => 'post:' . $post_id,
			)
		);

		update_post_meta( $post_id, '_tiercord_em_sent', time() );
	}
}
