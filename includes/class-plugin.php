<?php
namespace MigratorWP;

use MigratorWP\Admin_Page;
use MigratorWP\Exporter;
use MigratorWP\Importer;
use MigratorWP\Job_Manager;
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
     * Job manager instance.
     *
     * @var Job_Manager
     */
    private $jobs;

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
        $this->jobs     = new Job_Manager();
        $this->exporter = new Exporter( $this->logger );
        $this->importer = new Importer( $this->logger );

        new Admin_Page( $this->exporter, $this->importer, $this->logger, $this->jobs );

        add_action( 'admin_post_migratorwp_export', [ $this, 'handle_export' ] );
        add_action( 'admin_post_migratorwp_import', [ $this, 'handle_import' ] );
        add_action( 'admin_post_migratorwp_download', [ $this, 'handle_download' ] );
        add_action( 'migratorwp_run_export', [ $this, 'process_export_job' ] );
        add_action( 'migratorwp_run_import', [ $this, 'process_import_job' ] );

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

        $job_id = $this->jobs->create( 'export' );
        $this->jobs->progress( $job_id, 0, __( 'Dışa aktarma kuyruğa alındı.', 'migratorwp' ) );

        $this->dispatch_async( 'migratorwp_run_export', [ $job_id ] );

        $redirect = add_query_arg(
            'migratorwp_success',
            rawurlencode( __( 'Dışa aktarma arka planda çalışıyor. İlerlemesini aşağıdaki listeden takip edebilirsiniz.', 'migratorwp' ) ),
            admin_url( 'admin.php?page=migratorwp' )
        );

        wp_safe_redirect( $redirect );
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

        $job_id = $this->importer->import_from_upload( $file, $this->jobs );

        if ( is_wp_error( $job_id ) ) {
            $this->logger->error( $job_id->get_error_message() );
            wp_safe_redirect( add_query_arg( 'migratorwp_error', rawurlencode( $job_id->get_error_message() ), wp_get_referer() ) );
            exit;
        }

        $this->dispatch_async( 'migratorwp_run_import', [ $job_id ] );

        $redirect = add_query_arg(
            'migratorwp_success',
            rawurlencode( __( 'İçe aktarma kuyruğa alındı. İşlem tamamlandığında bilgilendirileceksiniz.', 'migratorwp' ) ),
            admin_url( 'admin.php?page=migratorwp' )
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Run export job via async dispatcher.
     *
     * @param string $job_id Job identifier.
     *
     * @return void
     */
    public function process_export_job( $job_id ) {
        $this->exporter->run_job( $this->jobs, $job_id );
    }

    /**
     * Run import job via async dispatcher.
     *
     * @param string $job_id Job identifier.
     *
     * @return void
     */
    public function process_import_job( $job_id ) {
        $this->importer->run_job( $this->jobs, $job_id );
    }

    /**
     * Stream exported file download for completed jobs.
     *
     * @return void
     */
    public function handle_download() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'İzin yok.', 'migratorwp' ) );
        }

        $job_id = isset( $_GET['job'] ) ? sanitize_text_field( wp_unslash( $_GET['job'] ) ) : ''; // phpcs:ignore
        $token  = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore

        if ( empty( $job_id ) || empty( $token ) || ! $this->jobs->verify_token( $job_id, $token ) ) {
            wp_die( esc_html__( 'Geçersiz indirme isteği.', 'migratorwp' ) );
        }

        $job = $this->jobs->get( $job_id );

        if ( ! $job || 'success' !== $job['status'] || empty( $job['result']['file'] ) ) {
            wp_die( esc_html__( 'İndirilecek dosya bulunamadı.', 'migratorwp' ) );
        }

        $path = $job['result']['file'];

        $uploads = wp_upload_dir();
        $base    = trailingslashit( $uploads['basedir'] ) . 'migratorwp';
        $real    = realpath( $path );
        $realbase = realpath( $base );

        if ( ! $real || ! $realbase || 0 !== strpos( $real, $realbase ) || ! file_exists( $real ) ) {
            wp_die( esc_html__( 'Dosya güvenlik nedeniyle indirilemiyor.', 'migratorwp' ) );
        }

        $this->exporter->stream_download( $real );
        exit;
    }

    /**
     * Schedule asynchronous job execution.
     *
     * @param string $hook Hook name.
     * @param array  $args Hook arguments.
     *
     * @return void
     */
    protected function dispatch_async( $hook, array $args ) {
        $timestamp = time();
        wp_schedule_single_event( $timestamp, $hook, $args );

        if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
            // If cron is disabled run immediately in current request.
            do_action_ref_array( $hook, $args );
            wp_clear_scheduled_hook( $hook, $args );
            return;
        }

        $cron_url = site_url( 'wp-cron.php' );
        wp_remote_post(
            $cron_url,
            [
                'timeout'  => 0.01,
                'blocking' => false,
            ]
        );
    }
}
