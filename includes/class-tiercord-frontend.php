<?php
/**
 * Member-facing "Connect Discord" box: [tiercord] shortcode and PMPro Account page.
 *
 * @package TierCord
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end output.
 */
class TierCord_Frontend {

	public static function init() {
		add_shortcode( 'tiercord', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_filter( 'the_content', array( __CLASS__, 'append_to_account_page' ), 20 );
	}

	public static function append_to_account_page( $content ) {
		if ( ! TierCord_Helpers::get( 'account_page' ) || ! is_user_logged_in() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$account_page = (int) get_option( 'pmpro_account_page_id' );
		if ( ! $account_page || ! is_page( $account_page ) || TierCord_Helpers::page_has_shortcode( $content, 'tiercord' ) ) {
			return $content;
		}
		return $content . self::shortcode();
	}

	/**
	 * Register front-end assets; they are enqueued only when a TierCord box is shown.
	 */
	public static function register_assets() {
		wp_register_style( 'tiercord', TIERCORD_URL . 'assets/frontend.css', array(), TIERCORD_VERSION );
		wp_register_script( 'tiercord', TIERCORD_URL . 'assets/frontend.js', array(), TIERCORD_VERSION, true );
	}

	/**
	 * Enqueue front-end assets (safe to call several times).
	 */
	public static function enqueue_assets() {
		if ( ! wp_style_is( 'tiercord', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'tiercord' );
		wp_enqueue_script( 'tiercord' );
	}

	private static function message() {
		$code = isset( $_GET['tiercord_msg'] ) ? sanitize_key( wp_unslash( $_GET['tiercord_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only status message.
		$map  = array(
			'connected'      => array( 'success', __( 'Your Discord account is connected and your Discord access has been updated.', 'tiercord' ) ),
			'disconnected'   => array( 'success', __( 'Your Discord account has been disconnected.', 'tiercord' ) ),
			'cancelled'      => array( 'info', __( 'Discord connection was cancelled.', 'tiercord' ) ),
			'already_linked' => array( 'error', __( 'That Discord account is already linked to another member account.', 'tiercord' ) ),
			'invalid_state'  => array( 'error', __( 'The connection link expired. Please try again.', 'tiercord' ) ),
			'not_configured' => array( 'error', __( 'Discord is not set up on this site yet.', 'tiercord' ) ),
			'error'          => array( 'error', __( 'Something went wrong talking to Discord. Please try again.', 'tiercord' ) ),
		);
		if ( ! isset( $map[ $code ] ) ) {
			return '';
		}
		return '<div class="tiercord-msg tiercord-msg-' . esc_attr( $map[ $code ][0] ) . '">' . esc_html( $map[ $code ][1] ) . '</div>';
	}

	/**
	 * Levels (names) that unlock a given server — used to hint at upgrades.
	 */
	/**
	 * Names of open-for-signup levels that include a role (used for upgrade hints).
	 *
	 * @param string $guild_id Server ID.
	 * @param string $role_id  Role ID.
	 * @return string[]
	 */
	private static function level_names_for_role( $guild_id, $role_id ) {
		$names = array();
		foreach ( TierCord_Helpers::levels_with_role( $guild_id, $role_id ) as $level_id ) {
			$level = pmpro_getLevel( $level_id );
			if ( $level && ! empty( $level->allow_signups ) ) {
				$names[] = $level->name;
			}
		}
		return $names;
	}

	public static function shortcode( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'title'        => __( 'Discord Community', 'tiercord' ),
				'show_locked'  => 'yes',
			),
			$atts,
			'tiercord'
		);

		ob_start();
		self::enqueue_assets();

		echo '<div class="tiercord-box">';
		echo '<h3 class="tiercord-title">' . esc_html( $atts['title'] ) . '</h3>';
		echo wp_kses_post( self::message() );

		if ( ! is_user_logged_in() ) {
			echo '<p>' . esc_html__( 'Log in to connect your Discord account.', 'tiercord' ) . '</p>';
			echo '<p><a class="tiercord-btn" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Log in', 'tiercord' ) . '</a></p></div>';
			return ob_get_clean();
		}

		if ( ! TierCord_Helpers::is_configured() ) {
			echo '<p>' . esc_html__( 'Discord access is not available yet.', 'tiercord' ) . '</p></div>';
			return ob_get_clean();
		}

		$user_id = get_current_user_id();
		$servers = TierCord_Helpers::active_servers();
		$allowed = TierCord_Helpers::allowed_roles_for_user( $user_id );

		if ( ! TierCord_Helpers::is_linked( $user_id ) ) {
			if ( $allowed ) {
				echo '<p>' . esc_html__( 'Your membership includes access to our Discord servers. Connect your Discord account and you will be added automatically.', 'tiercord' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'Connect your Discord account so we can give you access to the servers included in your membership.', 'tiercord' ) . '</p>';
			}
			echo '<p><a class="tiercord-btn tiercord-btn-discord" href="' . esc_url( TierCord_OAuth::connect_url() ) . '">' . esc_html__( 'Connect Discord', 'tiercord' ) . '</a></p>';
		} else {
			$status = get_user_meta( $user_id, 'tiercord_status', true );
			$status = is_array( $status ) ? $status : array();

			echo '<p class="tiercord-linked">' . esc_html__( 'Connected as', 'tiercord' ) . ' <strong>' . esc_html( get_user_meta( $user_id, 'tiercord_discord_username', true ) ) . '</strong></p>';

			if ( get_user_meta( $user_id, 'tiercord_needs_reconnect', true ) ) {
				echo '<div class="tiercord-msg tiercord-msg-error">' . esc_html__( 'Your Discord authorisation has expired. Please reconnect so we can add you to your servers.', 'tiercord' ) . '</div>';
			}

			if ( $servers ) {
				$levels_link = function_exists( 'pmpro_url' ) ? pmpro_url( 'levels' ) : '';
				echo '<ul class="tiercord-servers">';
				foreach ( $servers as $gid => $srv ) {
					$mine   = isset( $allowed[ $gid ] ) ? $allowed[ $gid ] : array();
					$state  = isset( $status[ $gid ]['state'] ) ? $status[ $gid ]['state'] : '';
					$held   = 'active' === $state && ! empty( $status[ $gid ]['roles'] ) ? array_map( 'strval', $status[ $gid ]['roles'] ) : array();
					$locked = array_diff( array_map( 'strval', array_keys( $srv['roles'] ) ), $mine );

					if ( ! $mine && 'yes' !== $atts['show_locked'] ) {
						continue;
					}

					echo '<li class="tiercord-server"><div class="tiercord-server-head"><span>' . ( $mine ? '' : '🔒 ' ) . esc_html( $srv['name'] ) . '</span> ';
					if ( $held ) {
						echo '<a href="' . esc_url( 'https://discord.com/channels/' . $gid ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open in Discord →', 'tiercord' ) . '</a>';
					} elseif ( $mine ) {
						echo '<em>' . esc_html__( 'included — being set up', 'tiercord' ) . '</em>';
					}
					echo '</div><ul class="tiercord-roles">';

					foreach ( $mine as $rid ) {
						$label = $srv['roles'][ $rid ] ?? $rid;
						echo '<li class="tiercord-role-ok">✓ ' . esc_html( $label ) . ( in_array( $rid, $held, true ) ? '' : ' <em>' . esc_html__( '(being set up)', 'tiercord' ) . '</em>' ) . '</li>';
					}
					if ( 'yes' === $atts['show_locked'] ) {
						foreach ( $locked as $rid ) {
							$levels = self::level_names_for_role( $gid, $rid );
							/* translators: %s: names of the membership levels that include this role. */
							$hint = $levels ? sprintf( __( 'available with %s', 'tiercord' ), implode( ' / ', $levels ) ) : __( 'not included', 'tiercord' );
							echo '<li class="tiercord-locked"><span>🔒 ' . esc_html( $srv['roles'][ $rid ] ) . '</span> — ';
							echo ( $levels && $levels_link ) ? '<a href="' . esc_url( $levels_link ) . '">' . esc_html( $hint ) . '</a>' : '<em>' . esc_html( $hint ) . '</em>';
							echo '</li>';
						}
					}
					echo '</ul></li>';
				}
				echo '</ul>';
			}

			echo '<p class="tiercord-actions">';
			if ( get_user_meta( $user_id, 'tiercord_needs_reconnect', true ) ) {
				echo '<a class="tiercord-btn tiercord-btn-discord" href="' . esc_url( TierCord_OAuth::connect_url() ) . '">' . esc_html__( 'Reconnect Discord', 'tiercord' ) . '</a> ';
			}
			echo '<a class="tiercord-link" data-tiercord-confirm="' . esc_attr__( 'Disconnect Discord? You will lose access to the members-only servers until you reconnect.', 'tiercord' ) . '" href="' . esc_url( TierCord_OAuth::disconnect_url() ) . '">' . esc_html__( 'Disconnect', 'tiercord' ) . '</a>';
			echo '</p>';
		}

		echo '</div>';
		return ob_get_clean();
	}

}
