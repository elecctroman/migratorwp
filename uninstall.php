<?php
/**
 * Uninstall script.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( \MigratorWP\Logger::OPTION_KEY );
delete_option( \MigratorWP\Job_Manager::OPTION_KEY );
