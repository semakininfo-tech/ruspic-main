<?php
/**
 * Plugin Name: Importaizer
 * Plugin URI: https://github.com/semakininfo-tech/ruspic-main
 * Description: Визуальный импорт каталога поставщика в RUSPIC Cat.
 * Version: 0.1.0
 * Author: RUSPIC
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Text Domain: importaizer
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'IMPORTAIZER_VERSION', '0.1.0' );
define( 'IMPORTAIZER_FILE', __FILE__ );
define( 'IMPORTAIZER_DIR', plugin_dir_path( __FILE__ ) );
define( 'IMPORTAIZER_URL', plugin_dir_url( __FILE__ ) );
define( 'IMPORTAIZER_DB_VERSION', '0.1.0' );

require_once IMPORTAIZER_DIR . 'includes/class-db.php';
require_once IMPORTAIZER_DIR . 'includes/class-analyzer.php';
require_once IMPORTAIZER_DIR . 'includes/class-parser.php';
require_once IMPORTAIZER_DIR . 'includes/class-ruspic-adapter.php';
require_once IMPORTAIZER_DIR . 'includes/class-admin.php';

register_activation_hook( __FILE__, array( 'Importaizer_DB', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Importaizer_DB', 'deactivate' ) );

function importaizer() {
    static $plugin = null;
    if ( null === $plugin ) {
        $plugin = new stdClass();
        $plugin->db = new Importaizer_DB();
        $plugin->analyzer = new Importaizer_Analyzer();
        $plugin->parser = new Importaizer_Parser();
        $plugin->ruspic = new Importaizer_RUSPIC_Adapter();
        $plugin->admin = new Importaizer_Admin( $plugin->db, $plugin->analyzer, $plugin->parser, $plugin->ruspic );
    }
    return $plugin;
}

add_action( 'plugins_loaded', 'importaizer' );
