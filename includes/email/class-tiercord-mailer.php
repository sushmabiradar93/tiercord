<?php
/**
 * Templates → HTML email → wp_mail.
 *
 * Placeholders: {first_name} {display_name} {site_name} {level_name} {account_url} {levels_url}
 * {brand_color} {unsubscribe_url} plus context ones ({post_title}, {message}, {server_names}, …).
 * Buttons: {button:Label|https://url} (placeholders allowed inside, e.g. {button:Read|{post_url}}).
 *
 * @package TierCord
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TierCord_Mailer {

	/** Placeholders whose values are already safe HTML. Everything else is escaped. */
	const HTML_VARS = array( 'message', 'post_excerpt', 'server_list', 'access_list' );

	/** Unsubscribable categories. 'account' emails (welcome/paused/reconnect) are transactional. */
	const CATEGORIES = array( 'posts', 'announcements' );

	/* ------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	public static function render_body( $template, $vars ) {
		$pairs = array();
		foreach ( $vars as $key => $value ) {
			$value = (string) $value;
			if ( ! in_array( $key, self::HTML_VARS, true ) ) {
				$value = esc_html( $value );
			}
			// Neutralise braces so content (e.g. a Discord message) can't inject placeholders or buttons.
			$pairs[ '{' . $key . '}' ] = str_replace( array( '{', '}' ), array( '&#123;', '&#125;' ), $value );
		}
		// strtr replaces in a single pass, so replaced values are never re-scanned.
		$out = strtr( $template, $pairs );

		// {button:Label|url}
		$color = isset( $vars['brand_color'] ) ? $vars['brand_color'] : '#5865F2';
		$out   = preg_replace_callback(
			'/\{button:([^|{}]+)\|([^{}]+)\}/',
			function ( $m ) use ( $color ) {
				$url = esc_url( html_entity_decode( trim( $m[2] ), ENT_QUOTES ) );
				return '<a href="' . $url . '" style="display:inline-block;background:' . esc_attr( $color ) . ';color:#ffffff;text-decoration:none;font-weight:600;padding:12px 22px;border-radius:6px">' . esc_html( html_entity_decode( trim( $m[1] ), ENT_QUOTES ) ) . '</a>';
			},
			$out
		);

		// Drop any unknown placeholders rather than mailing "{foo}".
		return preg_replace( '/\{[a-z_]+\}/', '', $out );
	}

	public static function render_subject( $template, $vars ) {
		$pairs = array();
		foreach ( $vars as $key => $value ) {
			$value = (string) $value;
			if ( in_array( $key, self::HTML_VARS, true ) ) {
				$value = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES );
			}
			$pairs[ '{' . $key . '}' ] = $value;
		}
		// Drop unknown placeholders in the template first, then fill known ones in a single pass.
		$known = array_keys( $pairs );
		$out   = preg_replace_callback(
			'/\{[a-z_]+\}/',
			function ( $m ) use ( $known ) {
				return in_array( $m[0], $known, true ) ? $m[0] : '';
			},
			$template
		);
		$out = strtr( $out, $pairs );
		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $out ) ) );
	}

	public static function wrap( $inner, $category, $unsubscribe_url ) {
		$s      = TierCord_Email::settings();
		$site   = get_bloginfo( 'name' );
		$color  = $s['brand_color'];
		$footer = $s['footer_text'] ? wpautop( wp_kses_post( $s['footer_text'] ) ) : '';

		$links = '<a href="' . esc_url( TierCord_Email::account_url() ) . '" style="color:#6b7280">' . esc_html__( 'Manage email preferences', 'tiercord' ) . '</a>';
		if ( $unsubscribe_url ) {
			$label = 'posts' === $category ? __( 'Unsubscribe from new-post emails', 'tiercord' ) : __( 'Unsubscribe from announcements', 'tiercord' );
			$links = '<a href="' . esc_url( $unsubscribe_url ) . '" style="color:#6b7280">' . esc_html( $label ) . '</a> · ' . $links;
		}

		return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
			. '<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#111827">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px"><tr><td align="center">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden">'
			. '<tr><td style="background:' . esc_attr( $color ) . ';padding:18px 28px;color:#ffffff;font-size:18px;font-weight:700">' . esc_html( $site ) . '</td></tr>'
			. '<tr><td style="padding:28px;font-size:15px;line-height:1.6">' . $inner . '</td></tr>'
			. '<tr><td style="padding:18px 28px;border-top:1px solid #e5e7eb;font-size:12px;line-height:1.5;color:#6b7280">' . $footer . '<p style="margin:0">' . $links . '</p></td></tr>'
			. '</table></td></tr></table></body></html>';
	}

	/* ------------------------------------------------------------------
	 * Sending
	 * ------------------------------------------------------------------ */

	/**
	 * Per-recipient placeholders.
	 */
	public static function user_vars( WP_User $user, $category ) {
		$first = $user->first_name ? $user->first_name : $user->display_name;
		$names = TierCord_Email::user_level_names( $user->ID );
		return array(
			'first_name'      => $first,
			'display_name'    => $user->display_name,
			'site_name'       => get_bloginfo( 'name' ),
			'level_name'      => $names ? implode( ' / ', $names ) : __( 'our', 'tiercord' ),
			'account_url'     => TierCord_Email::account_url(),
			'levels_url'      => TierCord_Email::levels_url(),
			'brand_color'     => TierCord_Email::get( 'brand_color' ),
			'unsubscribe_url' => in_array( $category, self::CATEGORIES, true ) ? self::unsubscribe_url( $user->ID, $category ) : '',
		);
	}

	/**
	 * Render and send one email.
	 *
	 * @param WP_User|int $user
	 * @param string      $subject_tpl
	 * @param string      $body_tpl
	 * @param array       $vars      Context placeholders (merged over user vars).
	 * @param string      $category  posts | announcements | account
	 * @return bool
	 */
	public static function send( $user, $subject_tpl, $body_tpl, $vars, $category ) {
		$user = $user instanceof WP_User ? $user : get_userdata( (int) $user );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return false;
		}

		$all     = array_merge( self::user_vars( $user, $category ), (array) $vars );
		$subject = self::render_subject( $subject_tpl, $all );
		$body    = self::wrap( self::render_body( $body_tpl, $all ), $category, $all['unsubscribe_url'] );

		$s       = TierCord_Email::settings();
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( $s['from_email'] && is_email( $s['from_email'] ) ) {
			$headers[] = sprintf( 'From: %s <%s>', str_replace( array( "\r", "\n", '<', '>' ), '', $s['from_name'] ), $s['from_email'] );
		}
		if ( $all['unsubscribe_url'] ) {
			// One-click unsubscribe (RFC 8058) — Gmail/Yahoo expect this for bulk mail.
			$headers[] = 'List-Unsubscribe: <' . esc_url_raw( $all['unsubscribe_url'] ) . '>';
			$headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
		}

		/**
		 * Last chance to change an outgoing email.
		 *
		 * @param array   $mail     { subject, body, headers }
		 * @param WP_User $user
		 * @param string  $category
		 * @param array   $vars
		 */
		$mail = apply_filters(
			'tiercord_em_email',
			array(
				'subject' => $subject,
				'body'    => $body,
				'headers' => $headers,
			),
			$user,
			$category,
			$all
		);

		return (bool) wp_mail( $user->user_email, $mail['subject'], $mail['body'], $mail['headers'] );
	}

	/* ------------------------------------------------------------------
	 * Unsubscribe tokens
	 * ------------------------------------------------------------------ */

	public static function token( $user_id, $category ) {
		$sig = hash_hmac( 'sha256', $user_id . '|' . $category, wp_salt( 'auth' ) . 'pdan' );
		return $user_id . '.' . $category . '.' . substr( $sig, 0, 32 );
	}

	public static function unsubscribe_url( $user_id, $category ) {
		return add_query_arg( 'tiercord_em_unsub', self::token( $user_id, $category ), home_url( '/' ) );
	}

	/**
	 * @return array|false [ user_id, category ] when valid.
	 */
	public static function verify_token( $token ) {
		$parts = explode( '.', (string) $token );
		if ( 3 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! in_array( $parts[1], self::CATEGORIES, true ) ) {
			return false;
		}
		$expected = self::token( (int) $parts[0], $parts[1] );
		return hash_equals( $expected, $token ) ? array( (int) $parts[0], $parts[1] ) : false;
	}
}
