<?php
/**
 * Works out which managed roles can see which Discord channels.
 *
 * Used for the "Who can see which channel" preview on the settings screen and to email Discord
 * announcements only to members whose roles can see the channel they were posted in.
 *
 * Discord's rule for one member: start from the @everyone role's permissions plus the permissions of
 * every role the member has; Administrator sees everything; then apply the channel's @everyone
 * overwrite (deny, then allow) and finally the role overwrites (all denies, then all allows).
 *
 * @package TierCord
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Channel visibility calculations.
 */
class TierCord_Permissions {

	const ADMINISTRATOR = 3;
	const VIEW_CHANNEL  = 10;

	// Channel types shown in the preview.
	const TYPE_TEXT         = 0;
	const TYPE_VOICE        = 2;
	const TYPE_CATEGORY     = 4;
	const TYPE_ANNOUNCEMENT = 5;
	const TYPE_STAGE        = 13;
	const TYPE_FORUM        = 15;
	const TYPE_MEDIA        = 16;

	/**
	 * Whether a Discord permission value (a decimal string that can exceed 32 bits) has a given low bit set.
	 * Uses digit-by-digit arithmetic so it is exact on every PHP build.
	 *
	 * @param string|int $permissions Permission integer as a string.
	 * @param int        $bit         Bit number (0–30).
	 * @return bool
	 */
	public static function has_bit( $permissions, $bit ) {
		$digits = preg_replace( '/\D/', '', (string) $permissions );
		$mod    = 1 << ( $bit + 1 );
		$rest   = 0;
		$len    = strlen( $digits );
		for ( $i = 0; $i < $len; $i++ ) {
			$rest = ( $rest * 10 + (int) $digits[ $i ] ) % $mod;
		}
		return (bool) ( ( $rest >> $bit ) & 1 );
	}

	/**
	 * Can a member with @everyone plus the given roles see the channel?
	 *
	 * @param array    $channel  Channel object from Discord (with permission_overwrites).
	 * @param string   $guild_id Server ID (also the @everyone role ID).
	 * @param array    $perms    [ role_id => permission string ] for every role, including @everyone.
	 * @param string[] $role_ids Roles the member has (besides @everyone).
	 * @return bool
	 */
	public static function can_view( $channel, $guild_id, $perms, $role_ids ) {
		$base_roles = array_merge( array( (string) $guild_id ), array_map( 'strval', $role_ids ) );

		$view = false;
		foreach ( $base_roles as $role_id ) {
			$p = isset( $perms[ $role_id ] ) ? $perms[ $role_id ] : '0';
			if ( self::has_bit( $p, self::ADMINISTRATOR ) ) {
				return true;
			}
			$view = $view || self::has_bit( $p, self::VIEW_CHANNEL );
		}

		$overwrites = array();
		foreach ( (array) ( $channel['permission_overwrites'] ?? array() ) as $ow ) {
			if ( isset( $ow['id'] ) && 0 === (int) ( $ow['type'] ?? 0 ) ) {
				$overwrites[ (string) $ow['id'] ] = $ow;
			}
		}

		// @everyone overwrite.
		if ( isset( $overwrites[ (string) $guild_id ] ) ) {
			$ow = $overwrites[ (string) $guild_id ];
			if ( self::has_bit( $ow['deny'] ?? '0', self::VIEW_CHANNEL ) ) {
				$view = false;
			}
			if ( self::has_bit( $ow['allow'] ?? '0', self::VIEW_CHANNEL ) ) {
				$view = true;
			}
		}

		// Role overwrites: any deny, then any allow (allow wins).
		$deny  = false;
		$allow = false;
		foreach ( $role_ids as $role_id ) {
			if ( isset( $overwrites[ (string) $role_id ] ) ) {
				$deny  = $deny || self::has_bit( $overwrites[ (string) $role_id ]['deny'] ?? '0', self::VIEW_CHANNEL );
				$allow = $allow || self::has_bit( $overwrites[ (string) $role_id ]['allow'] ?? '0', self::VIEW_CHANNEL );
			}
		}
		if ( $deny ) {
			$view = false;
		}
		if ( $allow ) {
			$view = true;
		}

		return $view;
	}

	/**
	 * Channels in a server (cached for 10 minutes).
	 *
	 * @param string $guild_id Server ID.
	 * @param bool   $force    Skip the cache.
	 * @return array|WP_Error
	 */
	public static function channels( $guild_id, $force = false ) {
		$key    = 'tiercord_channels_' . $guild_id;
		$cached = get_transient( $key );
		if ( ! $force && is_array( $cached ) ) {
			return $cached;
		}
		$res = TierCord_API::guild_channels( $guild_id );
		if ( ! TierCord_API::ok( $res ) || ! is_array( $res['body'] ) ) {
			return new WP_Error( 'tiercord_channels', $res['error'] ? $res['error'] : 'Could not load channels from Discord.' );
		}
		set_transient( $key, $res['body'], 10 * MINUTE_IN_SECONDS );
		return $res['body'];
	}

	/**
	 * Who can see one channel.
	 *
	 * @param string $guild_id   Server ID.
	 * @param string $channel_id Channel ID.
	 * @return array|WP_Error [ 'public' => bool, 'roles' => managed role IDs that can see it, 'name' => channel name ]
	 */
	public static function channel_access( $guild_id, $channel_id ) {
		$channels = self::channels( $guild_id );
		if ( is_wp_error( $channels ) ) {
			return $channels;
		}

		$channel = null;
		foreach ( $channels as $c ) {
			if ( (string) ( $c['id'] ?? '' ) === (string) $channel_id ) {
				$channel = $c;
				break;
			}
		}
		if ( ! $channel ) {
			// A channel created in the last few minutes: refresh once.
			$channels = self::channels( $guild_id, true );
			foreach ( is_array( $channels ) ? $channels : array() as $c ) {
				if ( (string) ( $c['id'] ?? '' ) === (string) $channel_id ) {
					$channel = $c;
					break;
				}
			}
		}
		if ( ! $channel ) {
			return new WP_Error( 'tiercord_unknown_channel', 'Channel not found in this server.' );
		}

		return self::visibility( $guild_id, $channel );
	}

	/**
	 * Visibility of a channel object for @everyone and for each managed role.
	 *
	 * @param string $guild_id Server ID.
	 * @param array  $channel  Channel object.
	 * @return array|WP_Error
	 */
	public static function visibility( $guild_id, $channel ) {
		$info = TierCord_Settings::guild_roles( $guild_id );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		$servers = TierCord_Helpers::active_servers();
		$managed = isset( $servers[ $guild_id ] ) ? array_keys( $servers[ $guild_id ]['roles'] ) : array();

		$public = self::can_view( $channel, $guild_id, $info['perms'], array() );
		$roles  = array();
		foreach ( $managed as $role_id ) {
			if ( self::can_view( $channel, $guild_id, $info['perms'], array( (string) $role_id ) ) ) {
				$roles[] = (string) $role_id;
			}
		}

		return array(
			'public' => $public,
			'roles'  => $roles,
			'name'   => isset( $channel['name'] ) ? (string) $channel['name'] : '',
		);
	}

	/**
	 * Channels of a server grouped under their categories, with who can see each (for the settings preview).
	 *
	 * @param string $guild_id Server ID.
	 * @return array|WP_Error List of [ 'channel' => array, 'is_category' => bool, 'public' => bool, 'roles' => role IDs ].
	 */
	public static function overview( $guild_id ) {
		$channels = self::channels( $guild_id );
		if ( is_wp_error( $channels ) ) {
			return $channels;
		}

		$shown      = array( self::TYPE_TEXT, self::TYPE_VOICE, self::TYPE_ANNOUNCEMENT, self::TYPE_STAGE, self::TYPE_FORUM, self::TYPE_MEDIA );
		$categories = array();
		$children   = array();
		foreach ( $channels as $c ) {
			$type = (int) ( $c['type'] ?? -1 );
			if ( self::TYPE_CATEGORY === $type ) {
				$categories[ (string) $c['id'] ] = $c;
			} elseif ( in_array( $type, $shown, true ) ) {
				$children[ (string) ( $c['parent_id'] ?? '' ) ][] = $c;
			}
		}

		$by_position = function ( $a, $b ) {
			return ( (int) ( $a['position'] ?? 0 ) ) - ( (int) ( $b['position'] ?? 0 ) );
		};
		uasort( $categories, $by_position );
		foreach ( $children as $parent => $list ) {
			usort( $list, $by_position );
			$children[ $parent ] = $list;
		}

		$rows = array();
		$add  = function ( $channel, $is_category ) use ( $guild_id, &$rows ) {
			$vis = self::visibility( $guild_id, $channel );
			if ( ! is_wp_error( $vis ) ) {
				$rows[] = array_merge( $vis, array( 'channel' => $channel, 'is_category' => $is_category ) );
			}
		};

		foreach ( $children[''] ?? array() as $c ) {
			$add( $c, false );
		}
		foreach ( $categories as $cat_id => $cat ) {
			$add( $cat, true );
			foreach ( $children[ $cat_id ] ?? array() as $c ) {
				$add( $c, false );
			}
		}
		return $rows;
	}
}
