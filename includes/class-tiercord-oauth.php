<?php
/**
 * "Connect Discord" flow: OAuth2 authorisation, callback and disconnect.
 *
 * @package TierCord
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discord account linking.
 */
class TierCord_OAuth {

	public static function init() {
		add_action( 'admin_post_tiercord_connect', array( __CLASS__, 'connect' ) );
		add_action( 'admin_post_tiercord_callback', array( __CLASS__, 'callback' ) );
		add_action( 'admin_post_tiercord_disconnect', array( __CLASS__, 'disconnect' ) );

		// Logged-out visitors get sent to the login page first.
		add_action( 'admin_post_nopriv_tiercord_connect', array( __CLASS__, 'require_login' ) );
		add_action( 'admin_post_nopriv_tiercord_callback', array( __CLASS__, 'require_login' ) );
		add_action( 'admin_post_nopriv_tiercord_disconnect', array( __CLASS__, 'require_login' ) );
	}

	/* ------------------------------------------------------------------
	 * URLs used by buttons
	 * ------------------------------------------------------------------ */

	public static function connect_url( $return_to = '' ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'    => 'tiercord_connect',
					'return_to' => rawurlencode( $return_to ? $return_to : self::current_url() ),
				),
				admin_url( 'admin-post.php' )
			),
			'tiercord_connect'
		);
	}

	public static function disconnect_url( $return_to = '' ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'    => 'tiercord_disconnect',
					'return_to' => rawurlencode( $return_to ? $return_to : self::current_url() ),
				),
				admin_url( 'admin-post.php' )
			),
			'tiercord_disconnect'
		);
	}

	/**
	 * Add discord.com to the hosts wp_safe_redirect() may use.
	 *
	 * @param string[] $hosts Allowed hosts.
	 * @return string[]
	 */
	public static function allow_discord_host( $hosts ) {
		$hosts[] = 'discord.com';
		return $hosts;
	}

	private static function current_url() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return remove_query_arg( 'tiercord_msg', home_url( $uri ) );
	}

	private static function safe_return( $raw ) {
		$url = $raw ? esc_url_raw( $raw ) : '';
		$url = wp_validate_redirect( $url, '' );
		if ( '' === $url ) {
			$url = function_exists( 'pmpro_url' ) && pmpro_url( 'account' ) ? pmpro_url( 'account' ) : home_url( '/' );
		}
		return $url;
	}

	private static function back( $url, $msg ) {
		wp_safe_redirect( add_query_arg( 'tiercord_msg', $msg, $url ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------ */

	public static function require_login() {
		// Logged-out visitor: no nonce can exist yet; the URL is validated by safe_return().
		$return = isset( $_GET['return_to'] ) ? esc_url_raw( wp_unslash( $_GET['return_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( wp_login_url( self::safe_return( $return ) ) );
		exit;
	}

	public static function connect() {
		check_admin_referer( 'tiercord_connect' );

		$return = self::safe_return( isset( $_GET['return_to'] ) ? esc_url_raw( wp_unslash( $_GET['return_to'] ) ) : '' );

		if ( ! TierCord_Helpers::is_configured() ) {
			self::back( $return, 'not_configured' );
		}

		$state = wp_generate_password( 32, false, false );
		set_transient(
			'tiercord_state_' . $state,
			array(
				'user_id' => get_current_user_id(),
				'return'  => $return,
			),
			15 * MINUTE_IN_SECONDS
		);

		// Allow this one redirect to Discord's authorisation screen.
		add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'allow_discord_host' ) );
		wp_safe_redirect( TierCord_API::authorize_url( $state ) );
		exit;
	}

	public static function callback() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Discord redirects here; protected by the single-use OAuth "state" value instead of a nonce.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$data  = $state ? get_transient( 'tiercord_state_' . $state ) : false;

		if ( ! $data || (int) $data['user_id'] !== get_current_user_id() ) {
			self::back( self::safe_return( '' ), 'invalid_state' );
		}
		delete_transient( 'tiercord_state_' . $state );
		$return  = $data['return'];
		$user_id = get_current_user_id();

		if ( isset( $_GET['error'] ) || empty( $_GET['code'] ) ) {
			self::back( $return, 'cancelled' );
		}

		$code = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		// phpcs:enable

		$token = TierCord_API::exchange_code( $code );
		if ( ! TierCord_API::ok( $token ) || empty( $token['body']['access_token'] ) ) {
			TierCord_Helpers::log( 'Token exchange failed: ' . $token['error'], 'error', $user_id );
			self::back( $return, 'error' );
		}

		$me = TierCord_API::me( $token['body']['access_token'] );
		if ( ! TierCord_API::ok( $me ) || empty( $me['body']['id'] ) ) {
			TierCord_Helpers::log( 'Could not read Discord profile: ' . $me['error'], 'error', $user_id );
			self::back( $return, 'error' );
		}

		$discord_id = (string) $me['body']['id'];

		// One Discord account per website account, so a subscription can't be shared.
		$taken = get_users(
			array(
				'meta_key'   => 'tiercord_discord_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-off lookup on account linking.
				'meta_value' => $discord_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one-off lookup on account linking.
				'exclude'    => array( $user_id ),
				'fields'     => 'ID',
				'number'     => 1,
			)
		);
		if ( $taken ) {
			TierCord_Helpers::log( sprintf( 'Discord account %s is already linked to user #%d.', $discord_id, $taken[0] ), 'warning', $user_id );
			TierCord_API::revoke( $token['body']['access_token'] );
			self::back( $return, 'already_linked' );
		}

		// Switching to a different Discord account: take access off the old one first.
		$old = TierCord_Helpers::discord_id( $user_id );
		if ( '' !== $old && $old !== $discord_id ) {
			TierCord_Sync::revoke_everything( $user_id );
		}

		$username = isset( $me['body']['global_name'] ) && $me['body']['global_name'] ? $me['body']['global_name'] : $me['body']['username'];

		update_user_meta( $user_id, 'tiercord_discord_id', $discord_id );
		update_user_meta( $user_id, 'tiercord_discord_username', sanitize_text_field( $username . ' (@' . $me['body']['username'] . ')' ) );
		update_user_meta( $user_id, 'tiercord_discord_avatar', isset( $me['body']['avatar'] ) ? sanitize_text_field( (string) $me['body']['avatar'] ) : '' );
		delete_user_meta( $user_id, 'tiercord_needs_reconnect' );
		TierCord_Helpers::save_tokens( $user_id, $token['body'] );

		TierCord_Helpers::log( 'Connected Discord account ' . $me['body']['username'] . '.', 'info', $user_id );

		TierCord_Sync::sync_user( $user_id );

		self::back( $return, 'connected' );
	}

	public static function disconnect() {
		check_admin_referer( 'tiercord_disconnect' );

		$user_id = get_current_user_id();
		$return  = self::safe_return( isset( $_GET['return_to'] ) ? esc_url_raw( wp_unslash( $_GET['return_to'] ) ) : '' );

		self::unlink_user( $user_id );

		self::back( $return, 'disconnected' );
	}

	/**
	 * Remove access everywhere, revoke the token and forget the link.
	 */
	public static function unlink_user( $user_id ) {
		if ( ! TierCord_Helpers::is_linked( $user_id ) ) {
			return;
		}
		TierCord_Sync::revoke_everything( $user_id );

		$token = TierCord_Helpers::access_token( $user_id );
		if ( '' !== $token ) {
			TierCord_API::revoke( $token );
		}

		TierCord_Helpers::clear_link( $user_id );
		delete_user_meta( $user_id, 'tiercord_last_sync' );
		TierCord_Helpers::log( 'Discord account disconnected.', 'info', $user_id );
	}
}
