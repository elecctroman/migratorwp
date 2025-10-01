<?php
namespace MigratorWP;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Responsible for export packages.
 */
class Exporter {
    /**
     * Logger reference.
     *
     * @var Logger
     */
    private $logger;

    /**
     * Constructor.
     *
     * @param Logger $logger Logger instance.
     */
    public function __construct( Logger $logger ) {
        $this->logger = $logger;
    }

    /**
     * Execute export.
     *
     * @return string|WP_Error Absolute path to export package or error.
     */
    public function export() {
        if ( function_exists( '\\wp_raise_memory_limit' ) ) {
            \wp_raise_memory_limit( 'admin' );
        }

        if ( function_exists( '\\wp_suspend_cache_invalidation' ) ) {
            \wp_suspend_cache_invalidation( true );
        }

        @set_time_limit( 0 );

        global $wpdb;

        $uploads = wp_upload_dir();
        $base    = trailingslashit( $uploads['basedir'] ) . 'migratorwp';

        if ( ! file_exists( $base ) ) {
            wp_mkdir_p( $base );
        }

        $timestamp = current_time( 'Ymd-His' );
        $work_dir  = trailingslashit( $base ) . 'export-' . $timestamp;

        if ( ! wp_mkdir_p( $work_dir ) ) {
            return new WP_Error( 'migratorwp_export', __( 'Geçici klasör oluşturulamadı.', 'migratorwp' ) );
        }

        $this->logger->info( __( 'Dışa aktarma hazırlanıyor…', 'migratorwp' ) );

        $manifest = [
            'created_at'   => current_time( 'mysql' ),
            'version'      => MIGRATORWP_VERSION,
            'site_url'     => site_url(),
            'home_url'     => home_url(),
            'php_version'  => PHP_VERSION,
            'wp_version'   => get_bloginfo( 'version' ),
            'table_prefix' => $wpdb->prefix,
        ];

        file_put_contents( $work_dir . '/manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

        $db_file = $work_dir . '/database.sql';
        $result  = $this->export_database( $db_file );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $archive = trailingslashit( $base ) . 'migratorwp-' . $timestamp . '.zip';

        $zip = new \ZipArchive();
        if ( true !== $zip->open( $archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
            return new WP_Error( 'migratorwp_zip', __( 'Arşiv dosyası oluşturulamadı.', 'migratorwp' ) );
        }

        $zip->addFile( $work_dir . '/manifest.json', 'manifest.json' );
        $zip->addFile( $db_file, 'database.sql' );

        $this->logger->info( __( 'Dosyalar arşivleniyor…', 'migratorwp' ) );

        $this->add_site_files_to_zip( $zip, $base );

        $zip->close();

        $this->logger->info( sprintf( __( 'Dışa aktarma tamamlandı: %s', 'migratorwp' ), basename( $archive ) ) );

        return $archive;
    }

    /**
     * Stream the generated archive to browser.
     *
     * @param string $path Path to archive.
     *
     * @return void
     */
    public function stream_download( $path ) {
        if ( ! file_exists( $path ) ) {
            wp_die( esc_html__( 'Arşiv bulunamadı.', 'migratorwp' ) );
        }

        nocache_headers();
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
        header( 'Content-Length: ' . filesize( $path ) );
        header( 'Content-Transfer-Encoding: binary' );

        $handle = fopen( $path, 'rb' );

        if ( ! $handle ) {
            wp_die( esc_html__( 'Arşiv okunamadı.', 'migratorwp' ) );
        }

        while ( ! feof( $handle ) ) {
            echo fread( $handle, 1024 * 1024 );
            flush();
        }

        fclose( $handle );
    }

    /**
     * Export database to SQL file.
     *
     * @param string $destination Destination file.
     *
     * @return true|WP_Error
     */
    protected function export_database( $destination ) {
        global $wpdb;

        $handle = fopen( $destination, 'w' );

        if ( ! $handle ) {
            return new WP_Error( 'migratorwp_export_db', __( 'Veritabanı dosyası yazılamıyor.', 'migratorwp' ) );
        }

        fwrite( $handle, "SET NAMES utf8mb4;\n" );
        fwrite( $handle, "SET FOREIGN_KEY_CHECKS=0;\n" );

        $tables = $wpdb->get_col( 'SHOW TABLES' );

        foreach ( $tables as $table ) {
            $this->logger->info( sprintf( __( 'Tablo dışa aktarılıyor: %s', 'migratorwp' ), $table ) );
            $create_table = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
            if ( empty( $create_table[1] ) ) {
                continue;
            }

            fwrite( $handle, "DROP TABLE IF EXISTS `{$table}`;\n" );
            fwrite( $handle, $create_table[1] . ";\n\n" );

            $row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

            if ( 0 === $row_count ) {
                continue;
            }

            $chunk_size = 500;
            $offset     = 0;

            while ( $offset < $row_count ) {
                $results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $chunk_size, $offset ), ARRAY_A );
                if ( empty( $results ) ) {
                    break;
                }

                $values = [];
                foreach ( $results as $row ) {
                    $escaped = array_map( [ $this, 'sql_escape_value' ], $row );
                    $values[] = '(' . implode( ',', $escaped ) . ')';
                }

                fwrite( $handle, "INSERT INTO `{$table}` VALUES\n" . implode( ",\n", $values ) . ";\n" );

                $offset += $chunk_size;
            }

            fwrite( $handle, "\n" );
        }

        fwrite( $handle, "SET FOREIGN_KEY_CHECKS=1;\n" );

        fclose( $handle );

        return true;
    }

    /**
     * Escape SQL value for dump.
     *
     * @param mixed $value Value to escape.
     *
     * @return string
     */
    protected function sql_escape_value( $value ) {
        global $wpdb;

        if ( is_null( $value ) ) {
            return 'NULL';
        }

        if ( is_numeric( $value ) && ! preg_match( '/^0[0-9]+$/', (string) $value ) ) {
            return (string) $value;
        }

        return "'" . $wpdb->_real_escape( $value ) . "'"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Add WordPress files to zip archive.
     *
     * @param \ZipArchive $zip      Zip archive instance.
     * @param string       $base_dir Upload base dir for skipping.
     *
     * @return void
     */
    protected function add_site_files_to_zip( \ZipArchive $zip, $base_dir ) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( ABSPATH, \FilesystemIterator::SKIP_DOTS )
        );

        foreach ( $iterator as $file_info ) {
            if ( $file_info->isDir() ) {
                continue;
            }

            $path = $file_info->getRealPath();

            if ( false !== strpos( $path, $base_dir ) ) {
                continue;
            }

            if ( false !== strpos( $path, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR ) ) {
                continue;
            }

            $relative = ltrim( str_replace( ABSPATH, '', $path ), '/\\' );

            if ( empty( $relative ) ) {
                continue;
            }

            $zip->addFile( $path, 'files/' . $relative );
        }
    }

    /**
     * Execute export in background job context.
     *
     * @param Job_Manager $jobs   Job manager instance.
     * @param string      $job_id Job identifier.
     *
     * @return void
     */
    public function run_job( Job_Manager $jobs, $job_id ) {
        $job = $jobs->get( $job_id );
        if ( ! $job || 'export' !== $job['type'] ) {
            return;
        }

        ignore_user_abort( true );
        if ( function_exists( '\\wp_raise_memory_limit' ) ) {
            \wp_raise_memory_limit( 'admin' );
        }
        @set_time_limit( 0 );

        $jobs->mark_running( $job_id, __( 'Dışa aktarma başlatıldı…', 'migratorwp' ) );

        $path = $this->export();

        if ( is_wp_error( $path ) ) {
            $jobs->mark_error( $job_id, $path->get_error_message() );
            return;
        }

        $result = [
            'file'     => $path,
            'filename' => basename( $path ),
            'filesize' => file_exists( $path ) ? filesize( $path ) : 0,
        ];

        $jobs->mark_success( $job_id, $result, __( 'Dışa aktarma tamamlandı. Dosyayı indirebilirsiniz.', 'migratorwp' ) );
    }
}
