<?php
namespace MigratorWP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles admin menu and screens.
 */
class Admin_Page {
    /**
     * Exporter.
     *
     * @var Exporter
     */
    private $exporter;

    /**
     * Importer.
     *
     * @var Importer
     */
    private $importer;

    /**
     * Logger.
     *
     * @var Logger
     */
    private $logger;

    /**
     * Job manager.
     *
     * @var Job_Manager
     */
    private $jobs;

    /**
     * Constructor.
     *
     * @param Exporter    $exporter Exporter instance.
     * @param Importer    $importer Importer instance.
     * @param Logger      $logger   Logger instance.
     * @param Job_Manager $jobs     Job manager instance.
     */
    public function __construct( Exporter $exporter, Importer $importer, Logger $logger, Job_Manager $jobs ) {
        $this->exporter = $exporter;
        $this->importer = $importer;
        $this->logger   = $logger;
        $this->jobs     = $jobs;

        add_action( 'admin_menu', [ $this, 'register_page' ] );
        add_action( 'admin_notices', [ $this, 'maybe_render_notice' ] );
    }

    /**
     * Register admin menu.
     *
     * @return void
     */
    public function register_page() {
        add_menu_page(
            __( 'MigratorWP', 'migratorwp' ),
            __( 'MigratorWP', 'migratorwp' ),
            'manage_options',
            'migratorwp',
            [ $this, 'render_page' ],
            'dashicons-migrate',
            65
        );
    }

    /**
     * Render admin page.
     *
     * @return void
     */
    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'İzin yok.', 'migratorwp' ) );
        }

        $logs = $this->logger->latest();
        $jobs = $this->jobs->all();
        $status_labels = [
            'pending' => __( 'Beklemede', 'migratorwp' ),
            'running' => __( 'Çalışıyor', 'migratorwp' ),
            'success' => __( 'Tamamlandı', 'migratorwp' ),
            'error'   => __( 'Hata', 'migratorwp' ),
        ];
        $type_labels = [
            'export' => __( 'Dışa Aktarma', 'migratorwp' ),
            'import' => __( 'İçe Aktarma', 'migratorwp' ),
        ];
        ?>
        <div class="wrap migratorwp-admin">
            <h1><?php esc_html_e( 'MigratorWP - Profesyonel Site Taşıma', 'migratorwp' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Tam site yedekleri oluşturun ve yeni ortamınıza tek tıkla taşıyın.', 'migratorwp' ); ?></p>

            <div class="migratorwp-columns">
                <div class="migratorwp-card">
                    <h2><?php esc_html_e( 'Dışa Aktar', 'migratorwp' ); ?></h2>
                    <p><?php esc_html_e( 'Tüm WordPress sitenizi tek paket halinde indirir. Manifest, veritabanı ve dosya sistemi dahil.', 'migratorwp' ); ?></p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'migratorwp_export' ); ?>
                        <input type="hidden" name="action" value="migratorwp_export">
                        <p>
                            <button type="submit" class="button button-primary button-large">
                                <?php esc_html_e( 'Dışa Aktarmayı Başlat', 'migratorwp' ); ?>
                            </button>
                        </p>
                    </form>
                </div>

                <div class="migratorwp-card">
                    <h2><?php esc_html_e( 'İçe Aktar', 'migratorwp' ); ?></h2>
                    <p><?php esc_html_e( 'MigratorWP paketini yükleyin, dosyalarınız ve veritabanınız otomatik olarak kurulacaktır.', 'migratorwp' ); ?></p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                        <?php wp_nonce_field( 'migratorwp_import' ); ?>
                        <input type="hidden" name="action" value="migratorwp_import">
                        <p>
                            <input type="file" name="migratorwp_package" accept=".zip" required>
                        </p>
                        <p>
                            <button type="submit" class="button button-primary button-large">
                                <?php esc_html_e( 'İçe Aktarmayı Başlat', 'migratorwp' ); ?>
                            </button>
                        </p>
                    </form>
                </div>
            </div>

            <h2><?php esc_html_e( 'İş Kuyruğu', 'migratorwp' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Uzun süren işlemler otomatik olarak arka planda yürütülür. Durumlarını buradan takip edebilirsiniz.', 'migratorwp' ); ?></p>
            <table class="widefat migratorwp-jobs">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Başlangıç', 'migratorwp' ); ?></th>
                        <th><?php esc_html_e( 'İşlem', 'migratorwp' ); ?></th>
                        <th><?php esc_html_e( 'Durum', 'migratorwp' ); ?></th>
                        <th><?php esc_html_e( 'Mesaj', 'migratorwp' ); ?></th>
                        <th><?php esc_html_e( 'Güncelleme', 'migratorwp' ); ?></th>
                        <th><?php esc_html_e( 'Aksiyon', 'migratorwp' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $jobs ) ) : ?>
                    <tr>
                        <td colspan="6"><?php esc_html_e( 'Kuyrukta herhangi bir görev bulunmuyor.', 'migratorwp' ); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $jobs as $job ) : ?>
                        <?php
                        $status = isset( $status_labels[ $job['status'] ] ) ? $status_labels[ $job['status'] ] : $job['status'];
                        $type   = isset( $type_labels[ $job['type'] ] ) ? $type_labels[ $job['type'] ] : $job['type'];
                        $message = ! empty( $job['message'] ) ? $job['message'] : __( 'Bekliyor…', 'migratorwp' );
                        $progress = isset( $job['progress'] ) ? absint( $job['progress'] ) : 0;
                        $actions = '&mdash;';

                        if ( 'export' === $job['type'] && 'success' === $job['status'] && ! empty( $job['result']['file'] ) ) {
                            $download_url = add_query_arg(
                                [
                                    'action' => 'migratorwp_download',
                                    'job'    => rawurlencode( $job['id'] ),
                                    'token'  => rawurlencode( $job['token'] ),
                                ],
                                admin_url( 'admin-post.php' )
                            );

                            $size = ! empty( $job['result']['filesize'] ) ? size_format( (int) $job['result']['filesize'] ) : '';
                            $label = $size ? sprintf( __( 'İndir (%s)', 'migratorwp' ), $size ) : __( 'İndir', 'migratorwp' );
                            $actions = sprintf( '<a class="button" href="%1$s">%2$s</a>', esc_url( $download_url ), esc_html( $label ) );
                        }
                        ?>
                        <tr>
                            <td><?php echo esc_html( $job['created_at'] ); ?></td>
                            <td><?php echo esc_html( $type ); ?></td>
                            <td><span class="migratorwp-status migratorwp-status--<?php echo esc_attr( $job['status'] ); ?>"><?php echo esc_html( $status ); ?></span><br><small><?php echo esc_html( $progress ); ?>%</small></td>
                            <td><?php echo esc_html( $message ); ?></td>
                            <td><?php echo esc_html( $job['updated_at'] ); ?></td>
                            <td><?php echo $actions; // phpcs:ignore ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <h2><?php esc_html_e( 'Son İşlemler', 'migratorwp' ); ?></h2>
            <table class="widefat migratorwp-log">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Zaman', 'migratorwp' ); ?></th>
                        <th><?php esc_html_e( 'Mesaj', 'migratorwp' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $logs ) ) : ?>
                    <tr>
                        <td colspan="2"><?php esc_html_e( 'Henüz kayıt yok.', 'migratorwp' ); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $logs as $log ) : ?>
                        <tr>
                            <td><?php echo esc_html( $log['time'] ); ?></td>
                            <td><?php echo esc_html( $log['message'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <style>
                .migratorwp-columns {
                    display: flex;
                    gap: 2rem;
                    flex-wrap: wrap;
                }
                .migratorwp-card {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    border-radius: 8px;
                    padding: 1.5rem;
                    flex: 1 1 320px;
                    box-shadow: 0 5px 18px rgba(0,0,0,0.06);
                }
                .migratorwp-log {
                    margin-top: 1.5rem;
                }
                .migratorwp-jobs {
                    margin-top: 1rem;
                }
                .migratorwp-status {
                    display: inline-block;
                    padding: 0.2em 0.6em;
                    border-radius: 999px;
                    font-weight: 600;
                }
                .migratorwp-status--pending {
                    background: #fef3c7;
                    color: #92400e;
                }
                .migratorwp-status--running {
                    background: #dbeafe;
                    color: #1d4ed8;
                }
                .migratorwp-status--success {
                    background: #dcfce7;
                    color: #166534;
                }
                .migratorwp-status--error {
                    background: #fee2e2;
                    color: #991b1b;
                }
            </style>
        </div>
        <?php
    }

    /**
     * Display admin notices if we have query vars.
     *
     * @return void
     */
    public function maybe_render_notice() {
        if ( isset( $_GET['page'] ) && 'migratorwp' === $_GET['page'] ) { // phpcs:ignore
            if ( ! empty( $_GET['migratorwp_error'] ) ) { // phpcs:ignore
                printf(
                    '<div class="notice notice-error"><p>%s</p></div>',
                    esc_html( wp_unslash( $_GET['migratorwp_error'] ) ) // phpcs:ignore
                );
            }

            if ( ! empty( $_GET['migratorwp_success'] ) ) { // phpcs:ignore
                printf(
                    '<div class="notice notice-success"><p>%s</p></div>',
                    esc_html( wp_unslash( $_GET['migratorwp_success'] ) ) // phpcs:ignore
                );
            }
        }
    }
}
