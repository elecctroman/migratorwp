<?php
namespace MigratorWP;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles import logic.
 */
class Importer {
    /**
     * Logger instance.
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
     * Import from uploaded file array.
     *

        if ( empty( $file ) || ! empty( $file['error'] ) ) {
            return new WP_Error( 'migratorwp_import_upload', __( 'Geçersiz dosya yüklemesi.', 'migratorwp' ) );
        }

        $overrides = [
            'test_form' => false,
            'mimes'     => [ 'zip' => 'application/zip' ],
        ];

        $uploaded = wp_handle_upload( $file, $overrides );

        if ( isset( $uploaded['error'] ) ) {
            return new WP_Error( 'migratorwp_import_upload', $uploaded['error'] );
        }


    }

    /**
     * Import package from filesystem.
     *
     * @param string $package_path Path to package zip.
     *
     * @return true|WP_Error
     */
    public function import( $package_path ) {
        if ( function_exists( '\\wp_raise_memory_limit' ) ) {
            \wp_raise_memory_limit( 'admin' );
        }

        @set_time_limit( 0 );

        if ( ! file_exists( $package_path ) ) {
            return new WP_Error( 'migratorwp_import_missing', __( 'Paket bulunamadı.', 'migratorwp' ) );
        }

        $this->logger->info( __( 'Paket doğrulanıyor…', 'migratorwp' ) );

        $zip = new \ZipArchive();
        if ( true !== $zip->open( $package_path ) ) {
            return new WP_Error( 'migratorwp_import_zip', __( 'Arşiv açılamadı.', 'migratorwp' ) );
        }

        $uploads = wp_upload_dir();
        $base    = trailingslashit( $uploads['basedir'] ) . 'migratorwp';
        $target  = trailingslashit( $base ) . 'import-' . wp_generate_password( 8, false );

        if ( ! wp_mkdir_p( $target ) ) {
            $zip->close();
            return new WP_Error( 'migratorwp_import_temp', __( 'Geçici klasör oluşturulamadı.', 'migratorwp' ) );
        }

        if ( true !== $zip->extractTo( $target ) ) {
            $zip->close();
            $this->cleanup_directory( $target );
            return new WP_Error( 'migratorwp_import_extract', __( 'Arşiv açılırken hata oluştu.', 'migratorwp' ) );
        }

        $zip->close();

        $manifest_path = $target . '/manifest.json';
        $sql_path      = $target . '/database.sql';
        $files_dir     = $target . '/files';

        if ( ! file_exists( $manifest_path ) || ! file_exists( $sql_path ) || ! file_exists( $files_dir ) ) {
            $this->cleanup_directory( $target );
            return new WP_Error( 'migratorwp_import_manifest', __( 'Manifest, veritabanı veya dosya bilgisi eksik.', 'migratorwp' ) );
        }

        $manifest = json_decode( file_get_contents( $manifest_path ), true );

        if ( empty( $manifest ) ) {
            return new WP_Error( 'migratorwp_import_manifest', __( 'Manifest okunamadı.', 'migratorwp' ) );
        }

        global $wpdb;

        if ( isset( $manifest['table_prefix'] ) && $manifest['table_prefix'] !== $wpdb->prefix ) {
            $this->logger->info( __( 'Tablo ön eki farklı. İçe aktarma mevcut tablo ön ekini kullanacaktır.', 'migratorwp' ) );
        }

        $this->logger->info( __( 'Veritabanı içe aktarılıyor…', 'migratorwp' ) );
        $result = $this->import_database( $sql_path );

        if ( is_wp_error( $result ) ) {
            $this->cleanup_directory( $target );
            return $result;
        }

        $this->logger->info( __( 'Dosyalar kopyalanıyor…', 'migratorwp' ) );
        $result = $this->import_files( $files_dir );

        if ( is_wp_error( $result ) ) {
            $this->cleanup_directory( $target );
            return $result;
        }

        $this->cleanup_directory( $target );

        $this->logger->info( __( 'İçe aktarma tamamlandı.', 'migratorwp' ) );

        return true;
    }

    /**
     * Import database SQL.
     *
     * @param string $sql_path Path to SQL file.
     *
     * @return true|WP_Error
     */
    protected function import_database( $sql_path ) {
        global $wpdb;

        $handle = fopen( $sql_path, 'r' );

        if ( ! $handle ) {
            return new WP_Error( 'migratorwp_import_sql', __( 'SQL dosyası okunamadı.', 'migratorwp' ) );
        }

        $statement = '';
        while ( ! feof( $handle ) ) {
            $line = fgets( $handle );

            if ( false === $line ) {
                break;
            }

            $trimmed = trim( $line );
            if ( '' === $trimmed || 0 === strpos( $trimmed, '--' ) || 0 === strpos( $trimmed, '/*' ) ) {
                continue;
            }

            $statement .= $line;

            if ( preg_match( '/;\s*$/', $trimmed ) ) {
                $wpdb->query( $statement ); // phpcs:ignore
                $statement = '';
            }
        }

        fclose( $handle );

        return true;
    }

    /**
     * Copy files from package into WordPress root.
     *
     * @param string $files_dir Files directory path.
     *
     * @return true|WP_Error
     */
    protected function import_files( $files_dir ) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $files_dir, \FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $item ) {
            $source   = $item->getPathname();
            $relative = ltrim( str_replace( $files_dir, '', $source ), '/\\' );
            $target   = trailingslashit( ABSPATH ) . $relative;

            if ( 'wp-config.php' === $relative ) {
                // Do not override target configuration.
                continue;
            }

            if ( $item->isDir() ) {
                if ( ! file_exists( $target ) ) {
                    wp_mkdir_p( $target );
                }
                continue;
            }

            $target_dir = dirname( $target );
            if ( ! file_exists( $target_dir ) ) {
                wp_mkdir_p( $target_dir );
            }

            if ( ! copy( $source, $target ) ) {
                return new WP_Error( 'migratorwp_import_copy', sprintf( __( 'Dosya kopyalanamadı: %s', 'migratorwp' ), $relative ) );
            }
        }

        return true;
    }

    /**
     * Remove temporary directory recursively.
     *
     * @param string $dir Directory to remove.
     *
     * @return void
     */
    protected function cleanup_directory( $dir ) {
        if ( empty( $dir ) || ! file_exists( $dir ) ) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $iterator as $item ) {
            if ( $item->isDir() ) {
                @rmdir( $item->getPathname() );
            } else {
                @unlink( $item->getPathname() );
            }
        }

        @rmdir( $dir );
    }

}
