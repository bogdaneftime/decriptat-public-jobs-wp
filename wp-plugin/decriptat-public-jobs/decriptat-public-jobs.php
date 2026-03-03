<?php
/**
 * Plugin Name: Decriptat Public Jobs
 * Description: Public sector jobs listing for decriptat.ro.
 * Version: 0.1.0
 * Author: Decriptat
 * Text Domain: decriptat-public-jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DECRIPTAT_PJ_PLUGIN_FILE', __FILE__ );
define( 'DECRIPTAT_PJ_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DECRIPTAT_PJ_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once DECRIPTAT_PJ_PLUGIN_DIR . 'includes/post-types.php';
require_once DECRIPTAT_PJ_PLUGIN_DIR . 'includes/meta.php';
require_once DECRIPTAT_PJ_PLUGIN_DIR . 'includes/templates.php';
require_once DECRIPTAT_PJ_PLUGIN_DIR . 'includes/shortcodes.php';

/**
 * Ensure rewrite rules are refreshed when plugin is activated.
 */
function decriptat_pj_activate() {
	decriptat_pj_register_post_type();
	decriptat_pj_register_taxonomies();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'decriptat_pj_activate' );

/**
 * Refresh rewrite rules on deactivation.
 */
function decriptat_pj_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'decriptat_pj_deactivate' );
