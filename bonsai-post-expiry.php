<?php
/**
 * Plugin Name:       Bonsai Post Expiry
 * Plugin URI:        https://github.com/Bonsai-Systems/bonsai-post-expiry
 * Description:       Set an expiry date and time on any post. When it passes, the post is switched to Draft. Upcoming and recent expiries are listed under Bonsai → Post Expiry.
 * Version:           1.2.0
 * Author:            The Bonsai Digital Collective
 * Author URI:        https://bonsaidigitalcollective.co.uk/
 * Update URI:        https://github.com/Bonsai-Systems/bonsai-post-expiry
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       bonsai-post-expiry
 *
 * @package BonsaiPostExpiry
 */

defined( 'ABSPATH' ) || exit;

/*
 * Duplicate install guard. If a second copy is active (e.g. a GitHub "Source
 * code" zip unpacked as bonsai-post-expiry-1.1.0/ next to the real folder),
 * loading it would fatal on redeclared BPE_ classes. Bail and tell the admin.
 */
if ( defined( 'BPE_VERSION' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>Bonsai Post Expiry is installed more than once. Please delete the duplicate plugin folder.</p></div>';
		}
	);
	return;
}

define( 'BPE_VERSION', '1.2.0' );
define( 'BPE_FILE', __FILE__ );
define( 'BPE_PATH', plugin_dir_path( __FILE__ ) );
define( 'BPE_URL', plugin_dir_url( __FILE__ ) );

// Shared Bonsai admin menu, page shell and suite installer. Bundled copy of
// the bonsai-hub repo; update it with bonsai-hub/bin/sync.sh, not by hand.
require_once BPE_PATH . 'lib/bonsai-hub/bonsai-hub.php';
require_once BPE_PATH . 'inc/class-bpe-updater.php';
require_once BPE_PATH . 'inc/class-bpe-expiry.php';
require_once BPE_PATH . 'inc/class-bpe-settings.php';
require_once BPE_PATH . 'inc/class-bpe-required.php';
require_once BPE_PATH . 'inc/class-bpe-admin.php';

BPE_Updater::init();

// Cron callbacks must be hooked on every request, including wp-cron.php, so
// the expiry class hooks in straight away rather than waiting for admin.
BPE_Expiry::init();

add_action(
	'plugins_loaded',
	function () {
		BPE_Settings::init();
		BPE_Required::init();
		BPE_Admin::init();
	}
);

register_activation_hook( __FILE__, array( 'BPE_Expiry', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BPE_Expiry', 'deactivate' ) );
