<?php
/**
 * Settings, default templates and small shared helpers.
 *
 * @package TierCord
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TierCord_Email {

	const OPTION = 'tiercord_em_settings';

	/** Usermeta key prefix marking "currently has access to server {id}". */
	const GUILD_PREFIX = 'tiercord_em_guild_';

	/** Usermeta key prefix marking "currently has role {id} through TierCord" (value: server ID). */
	const ROLE_PREFIX = 'tiercord_em_role_';
	const LOG    = 'tiercord_em_log';

	public static function defaults() {
		return array(
			// Sender & look.
			'from_name'          => get_bloginfo( 'name' ),
			'from_email'         => get_option( 'admin_email' ),
			'brand_color'        => '#5865F2',
			'footer_text'        => '',

			// Sending.
			'batch_size'         => 40,  // Emails per minute.

			// New posts.
			'posts_enabled'      => 1,
			'post_types'         => array( 'post' ),
			'notify_default'     => 1,   // Meta box pre-ticked.
			'unrestricted_to'    => 'all_members', // all_members | none (for posts with no level restriction).
			'post_delay'         => 2,   // Minutes after publishing (time to fix typos / untick).
			'post_subject'       => 'New post: {post_title}',
			'post_body'          => "<p>Hi {first_name},</p>\n<p>A new post is up for {level_name} members:</p>\n<h2 style=\"margin:16px 0 8px\">{post_title}</h2>\n<p>{post_excerpt}</p>\n<p>{button:Read the post|{post_url}}</p>",

			// Announcements.
			'announce_subject'   => '{subject}',
			'announce_body'      => "<p>Hi {first_name},</p>\n{message}",

			// Discord bridge.
			'bridge_enabled'     => 1,
			'bridge_secret'      => '',
			'bridge_channels'    => '', // Comma-separated channel IDs; empty = any channel the bot forwards.
			'discord_subject'    => '[{server_name}] New announcement',
			'discord_body'       => "<p>Hi {first_name},</p>\n<p><strong>{author}</strong> posted in <strong>#{channel_name}</strong> on {server_name}:</p>\n<blockquote style=\"margin:12px 0;padding:12px 16px;border-left:4px solid {brand_color};background:#f6f7fb\">{message}</blockquote>\n<p>{button:Open in Discord|{discord_url}}</p>",

			// Access emails (driven by tiercord_user_synced).
			'welcome_enabled'    => 1,
			'welcome_subject'    => 'You now have access to {access_names}',
			'welcome_body'       => "<p>Hi {first_name},</p>\n<p>Your membership now includes:</p>\n{access_list}\n<p>Open Discord and you'll find the channels for your membership waiting for you.</p>",
			'paused_enabled'     => 1,
			'paused_subject'     => 'Your access to {access_names} is paused',
			'paused_body'        => "<p>Hi {first_name},</p>\n<p>Your access to the following has been paused because your membership no longer includes it:</p>\n{access_list}\n<p>Renew or upgrade and access comes back automatically.</p>\n<p>{button:See membership options|{levels_url}}</p>",
			'reconnect_enabled'  => 1,
			'reconnect_subject'  => 'Please reconnect your Discord account',
			'reconnect_body'     => "<p>Hi {first_name},</p>\n<p>We couldn't add you to {server_names} because your Discord authorisation has expired. It takes one click to fix:</p>\n<p>{button:Reconnect Discord|{account_url}}</p>",
		);
	}

	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$s = self::settings();
		return isset( $s[ $key ] ) ? $s[ $key ] : null;
	}

	/* ------------------------------------------------------------------
	 * Lookups
	 * ------------------------------------------------------------------ */

	/** Usermeta key that marks "currently has access to this server". */
	public static function guild_key( $guild_id ) {
		return self::GUILD_PREFIX . preg_replace( '/\D/', '', (string) $guild_id );
	}

	/**
	 * Usermeta key for "has this role through TierCord".
	 *
	 * @param string $role_id Role ID.
	 * @return string
	 */
	public static function role_key( $role_id ) {
		return self::ROLE_PREFIX . preg_replace( '/\D/', '', (string) $role_id );
	}

	public static function server_name( $guild_id ) {
		$servers = (array) TierCord_Helpers::get( 'servers' );
		return isset( $servers[ $guild_id ]['name'] ) ? $servers[ $guild_id ]['name'] : (string) $guild_id;
	}

	public static function level_names( $level_ids ) {
		$names = array();
		foreach ( (array) $level_ids as $id ) {
			$level = pmpro_getLevel( (int) $id );
			if ( $level ) {
				$names[] = $level->name;
			}
		}
		return $names;
	}

	public static function user_level_names( $user_id ) {
		return TierCord_Helpers::user_level_names( $user_id );
	}

	public static function account_url() {
		$url = function_exists( 'pmpro_url' ) ? pmpro_url( 'account' ) : '';
		return $url ? $url : home_url( '/' );
	}

	public static function levels_url() {
		$url = function_exists( 'pmpro_url' ) ? pmpro_url( 'levels' ) : '';
		return $url ? $url : home_url( '/' );
	}

	/* ------------------------------------------------------------------
	 * Log
	 * ------------------------------------------------------------------ */

	public static function log( $message, $level = 'info' ) {
		$log = get_option( self::LOG, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				'time'    => time(),
				'level'   => $level,
				'message' => wp_strip_all_tags( $message ),
			)
		);
		update_option( self::LOG, array_slice( $log, 0, 150 ), false );
	}
}
