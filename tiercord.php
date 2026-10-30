<?php
/**
 * Plugin Name:       TierCord – Discord Access for Paid Memberships Pro
 * Plugin URI:        https://github.com/sushmabiradar93/tiercord
 * Description:       Give Paid Memberships Pro members access to your Discord servers by membership level, pause access automatically when a membership ends, and email members about new posts, announcements and access changes.
 * Version:           1.0.1
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  paid-memberships-pro
 * Author:            Sushma Biradar
 * Author URI:        https://profiles.wordpress.org/sushma4393/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tiercord
 *
 * @package TierCord
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TIERCORD_VERSION', '1.0.1' );
define( 'TIERCORD_FILE', __FILE__ );
define( 'TIERCORD_DIR', plugin_dir_path( __FILE__ ) );
define( 'TIERCORD_URL', plugin_dir_url( __FILE__ ) );

// Discord access.
require_once TIERCORD_DIR . 'includes/class-tiercord-helpers.php';
require_once TIERCORD_DIR . 'includes/class-tiercord-api.php';
require_once TIERCORD_DIR . 'includes/class-tiercord-sync.php';
require_once TIERCORD_DIR . 'includes/class-tiercord-permissions.php';
require_once TIERCORD_DIR . 'includes/class-tiercord-oauth.php';
require_once TIERCORD_DIR . 'includes/class-tiercord-settings.php';
require_once TIERCORD_DIR . 'includes/class-tiercord-frontend.php';

// Email notifications module.
require_once TIERCORD_DIR . 'includes/email/class-tiercord-email.php';
require_once TIERCORD_DIR . 'includes/email/class-tiercord-mailer.php';
require_once TIERCORD_DIR . 'includes/email/class-tiercord-queue.php';
require_once TIERCORD_DIR . 'includes/email/class-tiercord-access-emails.php';
require_once TIERCORD_DIR . 'includes/email/class-tiercord-post-emails.php';
require_once TIERCORD_DIR . 'includes/email/class-tiercord-bridge.php';
require_once TIERCORD_DIR . 'includes/email/class-tiercord-preferences.php';
require_once TIERCORD_DIR . 'includes/email/class-tiercord-email-admin.php';

/**
 * Boot once all plugins are loaded so Paid Memberships Pro functions exist.
 */
function tiercord_boot() {
	if ( ! function_exists( 'pmpro_getMembershipLevelsForUser' ) ) {
		add_action( 'admin_notices', 'tiercord_missing_pmpro_notice' );
		return;
	}

	// Upgrade settings saved by development builds that used one role per server.
	TierCord_Helpers::maybe_migrate();

	// Make sure the daily safety-net resync is always scheduled.
	if ( ! wp_next_scheduled( 'tiercord_daily_resync' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'tiercord_daily_resync' );
	}

	TierCord_Sync::init();
	TierCord_OAuth::init();
	TierCord_Settings::init();
	TierCord_Frontend::init();

	if ( TierCord_Helpers::get( 'emails_enabled' ) ) {
		TierCord_Queue::init();
		TierCord_Access_Emails::init();
		TierCord_Post_Emails::init();
		TierCord_Bridge::init();
		TierCord_Preferences::init();
		TierCord_Email_Admin::init();
	}
}
add_action( 'plugins_loaded', 'tiercord_boot' );

/**
 * Admin notice shown when Paid Memberships Pro is not active.
 */
function tiercord_missing_pmpro_notice() {
	echo '<div class="notice notice-error"><p>';
	esc_html_e( 'TierCord needs Paid Memberships Pro to be installed and active.', 'tiercord' );
	echo '</p></div>';
}

/**
 * Activation: schedule background jobs and create the email bridge secret.
 */
function tiercord_activate() {
	if ( ! wp_next_scheduled( 'tiercord_daily_resync' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'tiercord_daily_resync' );
	}

	// Record who already has Discord access, silently, so nobody gets a surprise "welcome" email.
	update_option( 'tiercord_em_backfill_done', 0, false );
	wp_schedule_single_event( time() + 5, 'tiercord_em_backfill', array( 0 ) );

	if ( ! get_option( TierCord_Email::OPTION ) ) {
		$settings                  = TierCord_Email::defaults();
		$settings['bridge_secret'] = wp_generate_password( 40, false, false );
		update_option( TierCord_Email::OPTION, $settings );
	}
}
register_activation_hook( __FILE__, 'tiercord_activate' );

/**
 * Deactivation: clear scheduled events.
 */
function tiercord_deactivate() {
	foreach ( array( 'tiercord_daily_resync', 'tiercord_resync_batch', 'tiercord_sync_user', 'tiercord_em_process_queue', 'tiercord_em_post_published', 'tiercord_em_backfill' ) as $hook ) {
		wp_unschedule_hook( $hook );
	}
}
register_deactivation_hook( __FILE__, 'tiercord_deactivate' );
