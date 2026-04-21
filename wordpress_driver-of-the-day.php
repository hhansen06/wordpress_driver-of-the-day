<?php
/**
 * Plugin Name: Driver of the Day
 * Description: „Driver of the Day" Abstimmung für Rallye-Events via rallyestage.de API. Einbindung per Shortcode [driver_of_the_day].
 * Version:     1.0.0
 * Text Domain: driver-of-the-day
 * Domain Path: /languages
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOTD_VERSION',    '1.0.0' );
define( 'DOTD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DOTD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DOTD_API_BASE',   'https://api.rallyestage.de/api/public/event/' );

require_once DOTD_PLUGIN_DIR . 'includes/class-dotd-db.php';
require_once DOTD_PLUGIN_DIR . 'includes/class-dotd-api.php';
require_once DOTD_PLUGIN_DIR . 'includes/class-dotd-vote.php';
require_once DOTD_PLUGIN_DIR . 'includes/class-dotd-admin.php';
require_once DOTD_PLUGIN_DIR . 'includes/class-dotd-shortcode.php';
require_once DOTD_PLUGIN_DIR . 'includes/class-dotd-i18n.php';

register_activation_hook( __FILE__, [ 'DOTD_DB', 'create_table' ] );

add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'driver-of-the-day', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	DOTD_I18N::init();
	DOTD_Vote::init();
	DOTD_Admin::init();
	DOTD_Shortcode::init();
} );
