<?php
/**
 * Member email preferences: one-click unsubscribe links and the [tiercord_email_preferences] form.
 *
 * @package TierCord
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TierCord_Preferences {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'handle_unsubscribe' ), 5 );
		add_action( 'admin_post_tiercord_em_save_prefs', array( __CLASS__, 'save' ) );
		add_shortcode( 'tiercord_email_preferences', array( __CLASS__, 'shortcode' ) );
		add_filter( 'the_content', array( __CLASS__, 'append_to_account_page' ), 21 );
	}

	public static function opted_out( $user_id, $category ) {
		if ( ! in_array( $category, TierCord_Mailer::CATEGORIES, true ) ) {
			return false; // Account emails can't be switched off.
		}
		return (bool) get_user_meta( $user_id, 'tiercord_em_optout_' . $category, true );
	}

	public static function set( $user_id, $category, $opt_out ) {
		if ( $opt_out ) {
			update_user_meta( $user_id, 'tiercord_em_optout_' . $category, 1 );
		} else {
			delete_user_meta( $user_id, 'tiercord_em_optout_' . $category );
		}
	}

	/**
	 * ?tiercord_em_unsub=<token>. GET shows a confirmation page; POST is RFC 8058 one-click (mail apps).
	 */
	public static function handle_unsubscribe() {
		// Links in emails can't carry a nonce; the token is an HMAC tied to the user and email type.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['tiercord_em_unsub'] ) ) {
			return;
		}
		$parsed = TierCord_Mailer::verify_token( sanitize_text_field( wp_unslash( $_GET['tiercord_em_unsub'] ) ) );
		// phpcs:enable

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			if ( $parsed ) {
				self::set( $parsed[0], $parsed[1], true );
			}
			status_header( $parsed ? 200 : 400 );
			exit;
		}

		if ( ! $parsed ) {
			wp_die( esc_html__( 'This unsubscribe link is not valid. You can change your email preferences from your account page.', 'tiercord' ), esc_html__( 'Unsubscribe', 'tiercord' ), array( 'response' => 400 ) );
		}

		self::set( $parsed[0], $parsed[1], true );

		$what = 'posts' === $parsed[1] ? __( 'new-post emails', 'tiercord' ) : __( 'announcement emails', 'tiercord' );
		/* translators: 1: type of email, e.g. "new-post emails", 2: site name. */
		$line = sprintf( __( 'You will no longer receive %1$s from %2$s.', 'tiercord' ), $what, get_bloginfo( 'name' ) );
		$msg  = '<h1>' . esc_html__( 'You are unsubscribed', 'tiercord' ) . '</h1>'
			. '<p>' . esc_html( $line ) . '</p>'
			. '<p>' . esc_html__( 'Important emails about your membership and Discord access will still be sent.', 'tiercord' ) . '</p>'
			. '<p><a href="' . esc_url( TierCord_Email::account_url() ) . '">' . esc_html__( 'Change your email preferences', 'tiercord' ) . '</a></p>';
		wp_die( wp_kses_post( $msg ), esc_html__( 'Unsubscribed', 'tiercord' ), array( 'response' => 200 ) );
	}

	public static function save() {
		check_admin_referer( 'tiercord_em_save_prefs' );
		$user_id = get_current_user_id();
		if ( $user_id ) {
			foreach ( TierCord_Mailer::CATEGORIES as $cat ) {
				self::set( $user_id, $cat, empty( $_POST[ 'tiercord_em_' . $cat ] ) );
			}
		}
		$back = wp_get_referer() ? wp_get_referer() : TierCord_Email::account_url();
		wp_safe_redirect( add_query_arg( 'tiercord_em_prefs', 'saved', remove_query_arg( 'tiercord_em_prefs', $back ) ) );
		exit;
	}

	public static function append_to_account_page( $content ) {
		if ( ! TierCord_Helpers::get( 'account_page' ) || ! is_user_logged_in() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$account_page = (int) get_option( 'pmpro_account_page_id' );
		if ( ! $account_page || ! is_page( $account_page ) || TierCord_Helpers::page_has_shortcode( $content, 'tiercord_email_preferences' ) ) {
			return $content;
		}
		return $content . self::shortcode();
	}

	public static function shortcode() {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$user_id = get_current_user_id();
		TierCord_Frontend::enqueue_assets();
		$saved = isset( $_GET['tiercord_em_prefs'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice.
		ob_start();
		?>
		<div class="tiercord-box tiercord-em-prefs">
			<h3 class="tiercord-title"><?php esc_html_e( 'Email preferences', 'tiercord' ); ?></h3>
			<?php if ( $saved ) : ?>
				<p class="tiercord-msg tiercord-msg-success"><?php esc_html_e( 'Preferences saved.', 'tiercord' ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tiercord_em_save_prefs">
				<?php wp_nonce_field( 'tiercord_em_save_prefs' ); ?>
				<p><label><input type="checkbox" name="tiercord_em_posts" value="1" <?php checked( ! self::opted_out( $user_id, 'posts' ) ); ?>> <?php esc_html_e( 'Email me when a new post is published for my membership', 'tiercord' ); ?></label></p>
				<p><label><input type="checkbox" name="tiercord_em_announcements" value="1" <?php checked( ! self::opted_out( $user_id, 'announcements' ) ); ?>> <?php esc_html_e( 'Email me important announcements (including from our Discord servers)', 'tiercord' ); ?></label></p>
				<p><button type="submit" class="tiercord-btn"><?php esc_html_e( 'Save preferences', 'tiercord' ); ?></button></p>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}
}
