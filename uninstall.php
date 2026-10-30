<?php
/**
 * Remove everything TierCord stored when the plugin is deleted.
 *
 * Discord roles that were already handed out are not touched: unlink members or change the
 * level mapping before deleting the plugin if you want roles removed.
 *
 * @package TierCord
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'tiercord_settings', 'tiercord_log', 'tiercord_resync_started', 'tiercord_resync_finished', 'tiercord_em_settings', 'tiercord_em_log', 'tiercord_em_jobs', 'tiercord_em_seen_messages', 'tiercord_em_backfill_done', 'tiercord_schema' ) as $tiercord_option ) {
	delete_option( $tiercord_option );
}

global $wpdb;

// One-off cleanup of this plugin's own keys; there is no core API for "delete by prefix".
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'tiercord_' ) . '%' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_tiercord_' ) . '%' ) );
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_tiercord_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_tiercord_' ) . '%'
	)
);
// phpcs:enable

foreach ( array( 'tiercord_daily_resync', 'tiercord_resync_batch', 'tiercord_sync_user', 'tiercord_em_process_queue', 'tiercord_em_post_published', 'tiercord_em_backfill' ) as $tiercord_hook ) {
	wp_unschedule_hook( $tiercord_hook );
}
