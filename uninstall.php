<?php
/**
 * Uninstall script.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( \MigratorWP\Logger::OPTION_KEY );
