<?php
/**
 * Plugin Name: RUSPIC Cat
 * Plugin URI: https://github.com/semakininfo-tech/ruspic-main
 * Description: Независимый каталог RUSPIC с брендами, категориями, товарами, характеристиками, REST API и собственной корзиной-заявкой. Не требует WooCommerce.
 * Version: 1.1.0
 * Author: RUSPIC
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Text Domain: ruspic-cat
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'RUSPIC_CAT_VERSION', '1.1.0' );
define( 'RUSPIC_CAT_FILE', __FILE__ );
define( 'RUSPIC_CAT_DIR', plugin_dir_path( __FILE__ ) );
define( 'RUSPIC_CAT_URL', plugin_dir_url( __FILE__ ) );
define( 'RUSPIC_CAT_DB_VERSION', '1.1.0' );

require_once RUSPIC_CAT_DIR . 'includes/class-db.php';
require_once RUSPIC_CAT_DIR . 'includes/class-parser-import.php';
require_once RUSPIC_CAT_DIR . 'includes/class-admin.php';
require_once RUSPIC_CAT_DIR . 'includes/class-rest.php';
require_once RUSPIC_CAT_DIR . 'includes/class-shortcodes.php';
require_once RUSPIC_CAT_DIR . 'includes/class-cart.php';

register_activation_hook( __FILE__, array( 'RUSPIC_Cat_DB', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RUSPIC_Cat_DB', 'deactivate' ) );

function ruspic_cat() {
    static $plugin = null;
    if ( null === $plugin ) {
        $plugin = new stdClass();
        $plugin->db = new RUSPIC_Cat_DB();
        $plugin->admin = new RUSPIC_Cat_Admin( $plugin->db );
        $plugin->parser_import = new RUSPIC_Cat_Parser_Import( $plugin->db );
        $plugin->rest = new RUSPIC_Cat_REST( $plugin->db );
        $plugin->shortcodes = new RUSPIC_Cat_Shortcodes( $plugin->db );
        $plugin->cart = new RUSPIC_Cat_Cart( $plugin->db );
    }
    return $plugin;
}

add_action( 'plugins_loaded', 'ruspic_cat' );
