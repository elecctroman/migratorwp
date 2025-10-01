<?php
namespace MigratorWP;

use MigratorWP\Admin_Page;
use MigratorWP\Exporter;
use MigratorWP\Importer;

use MigratorWP\Logger;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main plugin bootstrap.
 */
class Plugin {
    /**
     * Holds singleton instance.
     *
     * @var Plugin|null
     */
    private static $instance;

    /**
     * Logger instance shared across components.
     *
     * @var Logger
     */
    private $logger;

    /**
     * Exporter instance.
     *
     * @var Exporter
     */
    private $exporter;

    /**
     * Importer instance.
     *
     * @var Importer
     */
    private $importer;

    /**

     * Retrieve singleton.
     *
     * @return Plugin
     */
    public static function instance() {
        if ( ! static::$instance ) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    /**
     * Constructor is private, use ::instance().
     */
    private function __construct() {
        $this->logger   = new Logger();


        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            WP_CLI::add_command( 'migratorwp', new CLI_Command( $this->exporter, $this->importer, $this->logger ) );
        }
    }

    /**
     * Handle export requests from admin.
     *
     * @return void
     */
    public function handle_export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'İzin yok.', 'migratorwp' ) );
        }

        check_admin_referer( 'migratorwp_export' );


        exit;
    }

    /**
     * Handle import requests from admin.
     *
     * @return void
     */
    public function handle_import() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'İzin yok.', 'migratorwp' ) );
        }

        check_admin_referer( 'migratorwp_import' );

        $file = isset( $_FILES['migratorwp_package'] ) ? wp_unslash( $_FILES['migratorwp_package'] ) : null; // phpcs:ignore


}
