<?php
namespace MigratorWP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Lightweight file-based logger for status messages.
 */
class Logger {
    const OPTION_KEY = 'migratorwp_logs';
    const MAX_LOGS   = 20;

    /**
     * Log a message.
     *
     * @param string $message Message to log.
     *
     * @return void
     */
    public function info( $message ) {
        $this->add( 'info', $message );
    }

    /**
     * Log error message.
     *
     * @param string $message Message to log.
     *
     * @return void
     */
    public function error( $message ) {
        $this->add( 'error', $message );
    }

    /**
     * Return latest logs.
     *
     * @return array
     */
    public function latest() {
        $logs = get_option( static::OPTION_KEY, [] );

        if ( ! is_array( $logs ) ) {
            return [];
        }

        return array_slice( array_reverse( $logs ), 0, static::MAX_LOGS );
    }

    /**
     * Persist message.
     *
     * @param string $level   Level string.
     * @param string $message Message.
     *
     * @return void
     */
    protected function add( $level, $message ) {
        $logs   = get_option( static::OPTION_KEY, [] );
        $logs   = is_array( $logs ) ? $logs : [];
        $logs[] = [
            'level'   => $level,
            'message' => $message,
            'time'    => current_time( 'mysql' ),
        ];

        if ( count( $logs ) > static::MAX_LOGS * 2 ) {
            $logs = array_slice( $logs, - static::MAX_LOGS * 2 );
        }

        update_option( static::OPTION_KEY, $logs, false );
    }
}
