<?php
namespace MigratorWP;

use WP_CLI; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
use WP_CLI_Command;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WP-CLI integration.
 */
class CLI_Command extends WP_CLI_Command {
    /**
     * Exporter.
     *
     * @var Exporter
     */
    protected $exporter;

    /**
     * Importer.
     *
     * @var Importer
     */
    protected $importer;

    /**
     * Logger.
     *
     * @var Logger
     */
    protected $logger;

    /**
     * Constructor.
     *
     * @param Exporter $exporter Exporter instance.
     * @param Importer $importer Importer instance.
     * @param Logger   $logger   Logger instance.
     */
    public function __construct( Exporter $exporter, Importer $importer, Logger $logger ) {
        $this->exporter = $exporter;
        $this->importer = $importer;
        $this->logger   = $logger;
    }

    /**
     * Siteyi dışa aktar.
     *
     * ## OPTIONS
     *
     * [--destination=<path>]
     * : Varsayılan dışa aktarma klasörü yerine başka bir dosyaya kopyalar.
     *
     * ## EXAMPLES
     *
     *     wp migratorwp export --destination=/tmp/site.zip
     *
     * @param array $args       Positional args.
     * @param array $assoc_args Assoc args.
     *
     * @return void
     */
    public function export( $args, $assoc_args ) {
        $path = $this->exporter->export();

        if ( is_wp_error( $path ) ) {
            WP_CLI::error( $path->get_error_message() );
        }

        if ( ! empty( $assoc_args['destination'] ) ) {
            if ( ! copy( $path, $assoc_args['destination'] ) ) {
                WP_CLI::warning( __( 'Arşiv kopyalanamadı, varsayılan konum kullanılacak.', 'migratorwp' ) );
            } else {
                $path = $assoc_args['destination'];
            }
        }

        WP_CLI::success( sprintf( __( 'Dışa aktarma tamamlandı: %s', 'migratorwp' ), $path ) );
    }

    /**
     * Bir migratorwp paketini içe aktar.
     *
     * ## OPTIONS
     *
     * <file>
     * : İçe aktarılacak .zip paket yolu.
     *
     * ## EXAMPLES
     *
     *     wp migratorwp import ./migratorwp-20230101-120000.zip
     *
     * @param array $args       Positional args.
     * @param array $assoc_args Assoc args.
     *
     * @return void
     */
    public function import( $args, $assoc_args ) {
        if ( empty( $args[0] ) ) {
            WP_CLI::error( __( 'Lütfen bir paket yolu belirtin.', 'migratorwp' ) );
        }

        $result = $this->importer->import( $args[0] );

        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }

        WP_CLI::success( __( 'İçe aktarma tamamlandı.', 'migratorwp' ) );
    }
}
