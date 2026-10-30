<?php
/**
 * Thin Discord REST API client (v10) built on wp_remote_request.
 *
 * @package TierCord
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TierCord_API {

	const BASE = 'https://discord.com/api/v10';

	/**
	 * Perform a request.
	 *
	 * @param string       $method HTTP method.
	 * @param string       $path   Path beginning with "/".
	 * @param array|null   $body   JSON body (or form fields when $form is true).
	 * @param string       $auth   'bot' for the bot token, or a user access token for Bearer auth, or '' for none.
	 * @param bool         $form   Send as application/x-www-form-urlencoded (OAuth token endpoints).
	 * @param int          $tries  Internal retry counter for rate limits.
	 * @return array { code:int, body:array|null, error:string }
	 */
	public static function request( $method, $path, $body = null, $auth = 'bot', $form = false, $tries = 0 ) {
		$headers = array(
			'User-Agent' => 'DiscordBot (' . home_url() . ', ' . TIERCORD_VERSION . ')',
		);

		if ( 'bot' === $auth ) {
			$headers['Authorization'] = 'Bot ' . TierCord_Helpers::get( 'bot_token' );
		} elseif ( '' !== $auth ) {
			$headers['Authorization'] = 'Bearer ' . $auth;
		}

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => 15,
		);

		if ( null !== $body ) {
			if ( $form ) {
				$args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
				$args['body']                    = $body;
			} else {
				$args['headers']['Content-Type'] = 'application/json';
				$args['body']                    = wp_json_encode( $body );
			}
		} elseif ( in_array( $method, array( 'PUT', 'POST', 'PATCH' ), true ) ) {
			// Discord expects a Content-Length on bodiless PUTs (e.g. add role).
			$args['headers']['Content-Length'] = '0';
		}

		$response = wp_remote_request( self::BASE . $path, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'code'  => 0,
				'body'  => null,
				'error' => $response->get_error_message(),
			);
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		// Rate limited: wait briefly and retry (max 2 retries, max 10s wait).
		if ( 429 === $code && $tries < 2 ) {
			$wait = isset( $decoded['retry_after'] ) ? (float) $decoded['retry_after'] : 1.0;
			if ( $wait <= 10 ) {
				usleep( (int) ceil( $wait * 1000000 ) + 100000 );
				return self::request( $method, $path, $body, $auth, $form, $tries + 1 );
			}
		}

		$error = '';
		if ( $code >= 400 ) {
			$error = isset( $decoded['message'] ) ? $decoded['message'] : 'HTTP ' . $code;
			if ( isset( $decoded['error_description'] ) ) {
				$error = $decoded['error_description'];
			}
		}

		return array(
			'code'  => $code,
			'body'  => is_array( $decoded ) ? $decoded : null,
			'error' => $error,
		);
	}

	public static function ok( $res ) {
		return $res['code'] >= 200 && $res['code'] < 300;
	}

	/* ------------------------------------------------------------------
	 * OAuth2
	 * ------------------------------------------------------------------ */

	public static function authorize_url( $state ) {
		return 'https://discord.com/oauth2/authorize?' . http_build_query(
			array(
				'client_id'     => TierCord_Helpers::get( 'client_id' ),
				'redirect_uri'  => TierCord_Helpers::redirect_uri(),
				'response_type' => 'code',
				'scope'         => 'identify guilds.join',
				'state'         => $state,
				'prompt'        => 'consent',
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	public static function exchange_code( $code ) {
		return self::request(
			'POST',
			'/oauth2/token',
			array(
				'client_id'     => TierCord_Helpers::get( 'client_id' ),
				'client_secret' => TierCord_Helpers::get( 'client_secret' ),
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => TierCord_Helpers::redirect_uri(),
			),
			'',
			true
		);
	}

	public static function refresh( $refresh_token ) {
		return self::request(
			'POST',
			'/oauth2/token',
			array(
				'client_id'     => TierCord_Helpers::get( 'client_id' ),
				'client_secret' => TierCord_Helpers::get( 'client_secret' ),
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
			),
			'',
			true
		);
	}

	public static function revoke( $token ) {
		return self::request(
			'POST',
			'/oauth2/token/revoke',
			array(
				'client_id'     => TierCord_Helpers::get( 'client_id' ),
				'client_secret' => TierCord_Helpers::get( 'client_secret' ),
				'token'         => $token,
			),
			'',
			true
		);
	}

	public static function me( $access_token ) {
		return self::request( 'GET', '/users/@me', null, $access_token );
	}

	/* ------------------------------------------------------------------
	 * Bot endpoints
	 * ------------------------------------------------------------------ */

	public static function bot_user() {
		return self::request( 'GET', '/users/@me' );
	}

	public static function bot_guilds() {
		return self::request( 'GET', '/users/@me/guilds?limit=200' );
	}

	public static function guild_roles( $guild_id ) {
		return self::request( 'GET', '/guilds/' . rawurlencode( $guild_id ) . '/roles' );
	}

	/**
	 * All channels (and categories) in a server, including their permission overwrites.
	 *
	 * @param string $guild_id Server ID.
	 * @return array
	 */
	public static function guild_channels( $guild_id ) {
		return self::request( 'GET', '/guilds/' . rawurlencode( $guild_id ) . '/channels' );
	}

	public static function get_member( $guild_id, $discord_id ) {
		return self::request( 'GET', '/guilds/' . rawurlencode( $guild_id ) . '/members/' . rawurlencode( $discord_id ) );
	}

	/**
	 * Add a user to a guild (needs the user's guilds.join access token).
	 * Returns 201 when added, 204 when already a member (roles are NOT applied in that case).
	 */
	public static function add_member( $guild_id, $discord_id, $access_token, $roles = array() ) {
		$body = array( 'access_token' => $access_token );
		if ( $roles ) {
			$body['roles'] = array_values( $roles );
		}
		return self::request( 'PUT', '/guilds/' . rawurlencode( $guild_id ) . '/members/' . rawurlencode( $discord_id ), $body );
	}

	public static function add_role( $guild_id, $discord_id, $role_id ) {
		return self::request( 'PUT', '/guilds/' . rawurlencode( $guild_id ) . '/members/' . rawurlencode( $discord_id ) . '/roles/' . rawurlencode( $role_id ) );
	}

	public static function remove_role( $guild_id, $discord_id, $role_id ) {
		return self::request( 'DELETE', '/guilds/' . rawurlencode( $guild_id ) . '/members/' . rawurlencode( $discord_id ) . '/roles/' . rawurlencode( $role_id ) );
	}

	public static function kick( $guild_id, $discord_id ) {
		return self::request( 'DELETE', '/guilds/' . rawurlencode( $guild_id ) . '/members/' . rawurlencode( $discord_id ) );
	}
}
