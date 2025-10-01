<?php
/**
 * Plugin Name: MigratorWP
 * Plugin URI:  https://example.com/migratorwp
 * Description: Profesyonel WordPress site taşıma ve kopyalama eklentisi. Sınırsız boyutta dışa ve içe aktarma yapın.
 * Version:     1.0.0
 * Author:      MigratorWP Team
 * Author URI:  https://example.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: migratorwp
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MIGRATORWP_VERSION', '1.0.0' );
define( 'MIGRATORWP_PLUGIN_FILE', __FILE__ );
define( 'MIGRATORWP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'MIGRATORWP_PATH', plugin_dir_path( __FILE__ ) );
define( 'MIGRATORWP_URL', plugin_dir_url( __FILE__ ) );

autoload_migratorwp();
register_activation_hook( __FILE__, 'migratorwp_activate' );
register_deactivation_hook( __FILE__, 'migratorwp_deactivate' );

/**
 * Very small autoloader to keep files organised.
 *
 * @return void
 */
function autoload_migratorwp() {
    spl_autoload_register(
        static function ( $class ) {
            if ( 0 !== strpos( $class, 'MigratorWP' ) ) {
                return;
            }

            $filename = strtolower( str_replace( 'MigratorWP\\', '', $class ) );
            $filename = str_replace( '_', '-', $filename );
            $filename = str_replace( '\\', DIRECTORY_SEPARATOR, $filename );
            $path     = MIGRATORWP_PATH . 'includes/class-' . $filename . '.php';

            if ( file_exists( $path ) ) {
                require_once $path;
            }
        }
    );
}

/**
 * Activation callback.
 *
 * @return void
 */
function migratorwp_activate() {
    // Ensure upload folder exists on activation for faster first run.
    $uploads = wp_upload_dir();
    $dir     = trailingslashit( $uploads['basedir'] ) . 'migratorwp';

    if ( ! file_exists( $dir ) ) {
        wp_mkdir_p( $dir );
    }
}

/**
 * Deactivation callback.
 *
 * @return void
 */
function migratorwp_deactivate() {
    // Nothing for now. Keeping hooks for future cleanups.
}

add_action(
    'plugins_loaded',
    static function () {
        load_plugin_textdomain( 'migratorwp', false, dirname( MIGRATORWP_PLUGIN_BASENAME ) . '/languages' );

        \MigratorWP\Plugin::instance();
    }
);
