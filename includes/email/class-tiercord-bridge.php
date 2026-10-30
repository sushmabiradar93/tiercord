<?php
/**
 * Discord → email bridge.
 *
 * A Discord bot that you run yourself watches your announcement channels and POSTs each new
 * message to
 *   /wp-json/tiercord/v1/announce   (header: X-TierCord-Secret: <secret from settings>)
 * with JSON: guild_id, channel_id, message_id (required); content, channel_name, author, url,
 * attachments (array of URLs) (optional). GET /wp-json/tiercord/v1/ping checks the connection.
 * Members whose TierCord roles can see the channel get an email (everyone with access to the server if
 * the channel is public).
 *
 * @package TierCord
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TierCord_Bridge {

	const NS = 'tiercord/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function endpoint() {
		return rest_url( self::NS . '/announce' );
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/announce',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'announce' ),
				'permission_callback' => array( __CLASS__, 'authorised' ),
				'args'                => array(
					'guild_id'   => array( 'required' => true, 'type' => 'string' ),
					'channel_id' => array( 'required' => true, 'type' => 'string' ),
					'message_id' => array( 'required' => true, 'type' => 'string' ),
					'content'    => array( 'required' => false, 'type' => 'string', 'default' => '' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'ping' ),
				'permission_callback' => array( __CLASS__, 'authorised' ),
			)
		);
	}

	public static function authorised( WP_REST_Request $req ) {
		$secret = (string) TierCord_Email::get( 'bridge_secret' );
		$given  = (string) $req->get_header( 'x_tiercord_secret' );
		if ( ! TierCord_Email::get( 'bridge_enabled' ) || strlen( $secret ) < 20 || ! hash_equals( $secret, $given ) ) {
			return new WP_Error( 'tiercord_em_forbidden', 'Invalid or missing X-TierCord-Secret header, or the bridge is disabled.', array( 'status' => 401 ) );
		}
		return true;
	}

	public static function ping() {
		$servers = array();
		foreach ( TierCord_Helpers::active_servers() as $gid => $srv ) {
			$servers[ $gid ] = $srv['name'];
		}
		return array(
			'ok'       => true,
			'site'     => get_bloginfo( 'name' ),
			'servers'  => $servers,
			'channels' => self::allowed_channels(),
		);
	}

	public static function allowed_channels() {
		return array_values( array_filter( array_map( 'trim', preg_split( '/[\s,]+/', (string) TierCord_Email::get( 'bridge_channels' ) ) ) ) );
	}

	public static function announce( WP_REST_Request $req ) {
		$guild_id   = preg_replace( '/\D/', '', (string) $req['guild_id'] );
		$channel_id = preg_replace( '/\D/', '', (string) $req['channel_id'] );
		$message_id = preg_replace( '/\D/', '', (string) $req['message_id'] );

		$servers = TierCord_Helpers::active_servers();
		if ( ! isset( $servers[ $guild_id ] ) ) {
			return new WP_Error( 'tiercord_em_unknown_server', 'This server is not managed by TierCord.', array( 'status' => 422 ) );
		}

		$allowed = self::allowed_channels();
		if ( $allowed && ! in_array( $channel_id, $allowed, true ) ) {
			return new WP_Error( 'tiercord_em_channel', 'This channel is not in the allowed announcement channels list.', array( 'status' => 422 ) );
		}

		// Ignore duplicates (bot restarts, retries).
		$seen = get_option( 'tiercord_em_seen_messages', array() );
		$seen = is_array( $seen ) ? $seen : array();
		if ( in_array( $message_id, $seen, true ) ) {
			return array(
				'ok'        => true,
				'duplicate' => true,
			);
		}

		// Email only the members whose roles can see this channel.
		$audience = self::audience_for_channel( $guild_id, $channel_id );
		if ( is_wp_error( $audience ) ) {
			return $audience;
		}

		array_unshift( $seen, $message_id );
		update_option( 'tiercord_em_seen_messages', array_slice( $seen, 0, 300 ), false );

		$content      = (string) $req['content'];
		$attachments  = array_filter( array_map( 'esc_url_raw', (array) $req['attachments'] ) );
		$channel_name = sanitize_text_field( (string) ( $req['channel_name'] ?? 'announcements' ) );
		$author       = sanitize_text_field( (string) ( $req['author'] ?? '' ) );
		$url          = $req['url'] ? esc_url_raw( (string) $req['url'] ) : 'https://discord.com/channels/' . $guild_id . '/' . $channel_id . '/' . $message_id;

		if ( '' === trim( $content ) && ! $attachments ) {
			return new WP_Error( 'tiercord_em_empty', 'Empty message.', array( 'status' => 422 ) );
		}

		$html = self::discord_to_html( $content );
		foreach ( $attachments as $a ) {
			$path = (string) wp_parse_url( $a, PHP_URL_PATH );
			if ( preg_match( '/\.(png|jpe?g|gif|webp)$/i', $path ) ) {
				$html .= '<p><img src="' . esc_url( $a ) . '" alt="" style="max-width:100%;height:auto;border-radius:6px"></p>';
			} else {
				$html .= '<p>📎 <a href="' . esc_url( $a ) . '">' . esc_html( wp_basename( $path ) ) . '</a></p>';
			}
		}

		$first_line = trim( strtok( wp_strip_all_tags( self::plain( $content ) ), "\n" ) );
		if ( mb_strlen( $first_line ) > 80 ) {
			$first_line = mb_substr( $first_line, 0, 77 ) . '…';
		}

		$s   = TierCord_Email::settings();
		$job = TierCord_Queue::add(
			array(
				'title'    => sprintf( 'Discord #%s (%s)', $channel_name, $servers[ $guild_id ]['name'] ),
				'category' => 'announcements',
				'subject'  => $s['discord_subject'],
				'body'     => $s['discord_body'],
				'vars'     => array(
					'server_name'  => $servers[ $guild_id ]['name'],
					'channel_name' => $channel_name,
					'author'       => $author,
					'message'      => $html,
					'first_line'   => $first_line,
					'discord_url'  => $url,
				),
				'audience' => $audience,
				'source'   => 'discord:' . $message_id,
			)
		);

		return array(
			'ok'         => true,
			'job'        => $job,
			'recipients' => TierCord_Queue::count_audience( $audience ),
			'audience'   => TierCord_Queue::describe_audience( $audience ),
		);
	}

	/**
	 * Who should get an email for a message posted in a channel.
	 *
	 * Public channels (visible to @everyone) go to everyone with access to the server; gated channels go to
	 * members holding a managed role that can see the channel. If visibility can't be checked, nothing is
	 * sent (503, so the bot retries) rather than risk emailing gated content to the wrong members.
	 *
	 * @param string $guild_id   Server ID.
	 * @param string $channel_id Channel ID.
	 * @return array|WP_Error Audience.
	 */
	public static function audience_for_channel( $guild_id, $channel_id ) {
		$access = TierCord_Permissions::channel_access( $guild_id, $channel_id );
		if ( is_wp_error( $access ) ) {
			$status = 'tiercord_unknown_channel' === $access->get_error_code() ? 422 : 503;
			return new WP_Error( 'tiercord_em_visibility', 'Could not check who can see this channel: ' . $access->get_error_message(), array( 'status' => $status ) );
		}
		if ( $access['public'] ) {
			return array(
				'type'  => 'guild',
				'guild' => (string) $guild_id,
			);
		}
		if ( ! $access['roles'] ) {
			return new WP_Error( 'tiercord_em_no_roles', 'None of the roles TierCord manages can see this channel, so nobody was emailed.', array( 'status' => 422 ) );
		}
		return array(
			'type'  => 'roles',
			'guild' => (string) $guild_id,
			'roles' => $access['roles'],
		);
	}

	/**
	 * Discord-flavoured text → plain text (mentions resolved to readable tokens).
	 */
	public static function plain( $text ) {
		$text = preg_replace( '/<@!?\d+>/', '@member', $text );
		$text = preg_replace( '/<@&\d+>/', '@role', $text );
		$text = preg_replace( '/<#\d+>/', '#channel', $text );
		$text = preg_replace( '/<a?:(\w+):\d+>/', ':$1:', $text );
		$text = preg_replace( '/<t:(\d+)(?::[a-zA-Z])?>/', '[time]', $text );
		return $text;
	}

	/**
	 * Discord markdown → safe email HTML (bold, italic, underline, strike, code, quotes, links, line breaks).
	 */
	public static function discord_to_html( $text ) {
		$text = esc_html( self::plain( $text ) );

		// Code blocks and inline code first, so their contents aren't formatted.
		$codes = array();
		$text  = preg_replace_callback(
			'/```(?:\w+\n)?(.*?)```|`([^`\n]+)`/s',
			function ( $m ) use ( &$codes ) {
				$key           = "\x01" . count( $codes ) . "\x01";
				$codes[ $key ] = isset( $m[2] ) && '' !== $m[2]
					? '<code style="background:#f1f2f6;padding:1px 4px;border-radius:3px">' . $m[2] . '</code>'
					: '<pre style="background:#f1f2f6;padding:10px;border-radius:6px;white-space:pre-wrap">' . trim( $m[1] ) . '</pre>';
				return $key;
			},
			$text
		);

		$text = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text );
		$text = preg_replace( '/__(.+?)__/s', '<u>$1</u>', $text );
		$text = preg_replace( '/~~(.+?)~~/s', '<s>$1</s>', $text );
		$text = preg_replace( '/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '<em>$1</em>', $text );
		$text = preg_replace( '/(?<![\w_])_(?!\s)(.+?)(?<!\s)_(?![\w_])/s', '<em>$1</em>', $text );
		$text = preg_replace( '/\|\|(.+?)\|\|/s', '$1', $text ); // Spoilers: just show.
		$text = preg_replace( '/^#{1,3} (.+)$/m', '<strong>$1</strong>', $text ); // Headings.
		$text = preg_replace( '/^&gt; ?(.*)$/m', '<span style="display:block;border-left:3px solid #d1d5db;padding-left:8px;color:#4b5563">$1</span>', $text );
		$text = preg_replace( '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', '<a href="$2">$1</a>', $text ); // Masked links.
		$text = make_clickable( $text );
		$text = nl2br( $text );

		$text = strtr( $text, $codes );
		return wp_kses_post( $text );
	}
}
