<?php
namespace MigratorWP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Stores and manages asynchronous job states.
 */
class Job_Manager {
    const OPTION_KEY = 'migratorwp_jobs';

    /**
     * Maximum jobs to retain.
     */
    const MAX_JOBS = 10;

    /**
     * Create a new job entry.
     *
     * @param string $type Job type.
     * @param array  $data Additional data to persist.
     *
     * @return string Job identifier.
     */
    public function create( $type, array $data = [] ) {
        $jobs   = $this->load_jobs();
        $id     = wp_generate_uuid4();
        $now    = current_time( 'mysql' );
        $token  = wp_generate_password( 20, false );
        $jobs[ $id ] = [
            'id'         => $id,
            'type'       => $type,
            'status'     => 'pending',
            'message'    => '',
            'progress'   => 0,
            'data'       => $data,
            'result'     => [],
            'token'      => $token,
            'created_at' => $now,
            'updated_at' => $now,
            'log'        => [],
        ];

        $jobs = $this->prune( $jobs );
        $this->save_jobs( $jobs );

        return $id;
    }

    /**
     * Retrieve a job.
     *
     * @param string $id Job id.
     *
     * @return array|null
     */
    public function get( $id ) {
        $jobs = $this->load_jobs();

        return isset( $jobs[ $id ] ) ? $jobs[ $id ] : null;
    }

    /**
     * Return last jobs ordered by creation date.
     *
     * @param int $limit Max jobs to return.
     *
     * @return array
     */
    public function all( $limit = self::MAX_JOBS ) {
        $jobs = $this->load_jobs();
        $jobs = $this->sort_jobs( $jobs );

        return array_slice( $jobs, 0, $limit, true );
    }

    /**
     * Append a log message.
     *
     * @param string $id      Job id.
     * @param string $message Message to append.
     *
     * @return void
     */
    public function log( $id, $message ) {
        $jobs = $this->load_jobs();
        if ( ! isset( $jobs[ $id ] ) ) {
            return;
        }

        $jobs[ $id ]['log'][] = [
            'time'    => current_time( 'mysql' ),
            'message' => $message,
        ];

        $this->touch_and_save( $jobs, $id, null );
    }

    /**
     * Update job progress and optionally message.
     *
     * @param string      $id       Job id.
     * @param int|float   $progress Progress percent.
     * @param string|null $message  Optional status message.
     *
     * @return void
     */
    public function progress( $id, $progress, $message = null ) {
        $jobs = $this->load_jobs();
        if ( ! isset( $jobs[ $id ] ) ) {
            return;
        }

        $jobs[ $id ]['progress'] = max( 0, min( 100, (int) $progress ) );

        if ( null !== $message ) {
            $jobs[ $id ]['message'] = $message;
            $jobs[ $id ]['log'][]   = [
                'time'    => current_time( 'mysql' ),
                'message' => $message,
            ];
        }

        $this->touch_and_save( $jobs, $id, null );
    }

    /**
     * Mark job as running.
     *
     * @param string $id      Job id.
     * @param string $message Status message.
     *
     * @return void
     */
    public function mark_running( $id, $message = '' ) {
        $this->mark_status( $id, 'running', $message );
    }

    /**
     * Mark job as succeeded.
     *
     * @param string $id      Job id.
     * @param array  $result  Result payload.
     * @param string $message Status message.
     *
     * @return void
     */
    public function mark_success( $id, array $result = [], $message = '' ) {
        $jobs = $this->load_jobs();
        if ( ! isset( $jobs[ $id ] ) ) {
            return;
        }

        $jobs[ $id ]['status']   = 'success';
        $jobs[ $id ]['message']  = $message;
        $jobs[ $id ]['progress'] = 100;
        $jobs[ $id ]['result']   = $result;
        if ( $message ) {
            $jobs[ $id ]['log'][] = [
                'time'    => current_time( 'mysql' ),
                'message' => $message,
            ];
        }

        $this->touch_and_save( $jobs, $id, $jobs[ $id ] );
    }

    /**
     * Mark job as failed.
     *
     * @param string $id      Job id.
     * @param string $message Failure message.
     *
     * @return void
     */
    public function mark_error( $id, $message ) {
        $this->mark_status( $id, 'error', $message );
    }

    /**
     * Update stored data payload.
     *
     * @param string $id   Job id.
     * @param array  $data Data to merge.
     *
     * @return void
     */
    public function update_data( $id, array $data ) {
        $jobs = $this->load_jobs();
        if ( ! isset( $jobs[ $id ] ) ) {
            return;
        }

        $jobs[ $id ]['data'] = array_merge( $jobs[ $id ]['data'], $data );
        $this->touch_and_save( $jobs, $id, null );
    }

    /**
     * Validate download token.
     *
     * @param string $id    Job id.
     * @param string $token Token string.
     *
     * @return bool
     */
    public function verify_token( $id, $token ) {
        $job = $this->get( $id );
        if ( ! $job ) {
            return false;
        }

        return hash_equals( $job['token'], (string) $token );
    }

    /**
     * Sort helper preserving keys.
     *
     * @param array $jobs Jobs array.
     *
     * @return array
     */
    protected function sort_jobs( array $jobs ) {
        uasort(
            $jobs,
            static function ( $a, $b ) {
                return strcmp( $b['created_at'], $a['created_at'] );
            }
        );

        return $jobs;
    }

    /**
     * Prune to the maximum amount of jobs kept.
     *
     * @param array $jobs Jobs array.
     *
     * @return array
     */
    protected function prune( array $jobs ) {
        $jobs = $this->sort_jobs( $jobs );

        if ( count( $jobs ) <= self::MAX_JOBS ) {
            return $jobs;
        }

        return array_slice( $jobs, 0, self::MAX_JOBS, true );
    }

    /**
     * Save and update timestamp.
     *
     * @param array       $jobs Jobs.
     * @param string      $id   Job id.
     * @param array|null  $job  Optional job override.
     *
     * @return void
     */
    protected function touch_and_save( array $jobs, $id, $job ) {
        if ( $job ) {
            $jobs[ $id ] = $job;
        }

        if ( isset( $jobs[ $id ] ) ) {
            $jobs[ $id ]['updated_at'] = current_time( 'mysql' );
        }

        $jobs = $this->prune( $jobs );
        $this->save_jobs( $jobs );
    }

    /**
     * Change job status with message.
     *
     * @param string $id      Job id.
     * @param string $status  New status.
     * @param string $message Status message.
     *
     * @return void
     */
    protected function mark_status( $id, $status, $message = '' ) {
        $jobs = $this->load_jobs();
        if ( ! isset( $jobs[ $id ] ) ) {
            return;
        }

        $jobs[ $id ]['status'] = $status;
        $jobs[ $id ]['message'] = $message;
        if ( 'error' === $status ) {
            $jobs[ $id ]['progress'] = 100;
        }
        if ( $message ) {
            $jobs[ $id ]['log'][] = [
                'time'    => current_time( 'mysql' ),
                'message' => $message,
            ];
        }

        $this->touch_and_save( $jobs, $id, null );
    }

    /**
     * Persist jobs option.
     *
     * @param array $jobs Jobs array.
     *
     * @return void
     */
    protected function save_jobs( array $jobs ) {
        update_option( self::OPTION_KEY, $jobs, false );
    }

    /**
     * Load jobs from option.
     *
     * @return array
     */
    protected function load_jobs() {
        $jobs = get_option( self::OPTION_KEY, [] );

        if ( ! is_array( $jobs ) ) {
            return [];
        }

        return $jobs;
    }
}
