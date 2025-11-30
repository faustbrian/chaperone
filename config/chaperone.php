<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
|--------------------------------------------------------------------------
| Chaperone Configuration
|--------------------------------------------------------------------------
|
| This file defines the configuration for Chaperone, a Laravel package that
| supervises long-running queue jobs with health monitoring, circuit breakers,
| and resource limits. It ensures queue jobs execute reliably with proper
| monitoring, automatic recovery, and resource constraint enforcement.
|
*/

use Cline\Chaperone\Database\Models\CircuitBreaker;
use Cline\Chaperone\Database\Models\DeadLetterJob;
use Cline\Chaperone\Database\Models\Heartbeat;
use Cline\Chaperone\Database\Models\JobHealthCheck;
use Cline\Chaperone\Database\Models\ResourceViolation;
use Cline\Chaperone\Database\Models\SupervisedJob;
use Cline\Chaperone\Database\Models\SupervisedJobError;
use Cline\Chaperone\Enums\MorphType;
use Cline\Chaperone\Enums\PrimaryKeyType;

return [
    /*
    |--------------------------------------------------------------------------
    | Primary Key Type
    |--------------------------------------------------------------------------
    |
    | This option controls the type of primary key used in Chaperone's database
    | tables. You may use traditional auto-incrementing integers or choose
    | ULIDs or UUIDs for distributed systems or enhanced privacy.
    |
    | Supported: "id", "ulid", "uuid"
    |
    */

    'primary_key_type' => env('CHAPERONE_PRIMARY_KEY_TYPE', 'id'),

    /*
    |--------------------------------------------------------------------------
    | Morph Type
    |--------------------------------------------------------------------------
    |
    | This option controls the type of polymorphic relationship columns used
    | for tracking job ownership and associations. This determines how jobs
    | are associated with different models in your application.
    |
    | Supported: "morph", "uuidMorph", "ulidMorph", "numericMorph"
    |
    */

    'morph_type' => env('CHAPERONE_MORPH_TYPE', 'string'),

    /*
    |--------------------------------------------------------------------------
    | Polymorphic Key Mapping
    |--------------------------------------------------------------------------
    |
    | This option allows you to specify which column should be used as the
    | foreign key for each model in polymorphic relationships. This is
    | particularly useful when different models in your application use
    | different primary key column names.
    |
    | Note: You may only configure either 'morphKeyMap' or 'enforceMorphKeyMap',
    | not both.
    |
    */

    'morphKeyMap' => [
        // App\Models\User::class => 'id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Enforced Polymorphic Key Mapping
    |--------------------------------------------------------------------------
    |
    | This option works identically to 'morphKeyMap' above, but enables strict
    | enforcement. Any model referenced without an explicit mapping will throw
    | a MorphKeyViolationException.
    |
    */

    'enforceMorphKeyMap' => [
        // App\Models\User::class => 'id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Eloquent Models
    |--------------------------------------------------------------------------
    |
    | When using Chaperone, these Eloquent models are used to interact with
    | the database. You may extend these models with your own implementations
    | whilst ensuring they extend the base classes provided by Chaperone.
    |
    */

    'models' => [
        /*
        |--------------------------------------------------------------------------
        | Supervised Job Model
        |--------------------------------------------------------------------------
        |
        | This model tracks all supervised jobs including their health status,
        | execution metrics, and resource usage.
        |
        */

        'supervised_job' => SupervisedJob::class,

        /*
        |--------------------------------------------------------------------------
        | Supervised Job Error Model
        |--------------------------------------------------------------------------
        |
        | This model stores detailed error information when supervised jobs fail,
        | providing a complete audit trail for debugging and recovery.
        |
        */

        'supervised_job_error' => SupervisedJobError::class,

        /*
        |--------------------------------------------------------------------------
        | Heartbeat Model
        |--------------------------------------------------------------------------
        |
        | This model stores periodic health signals from running jobs,
        | including resource usage and progress metrics.
        |
        */

        'heartbeat' => Heartbeat::class,

        /*
        |--------------------------------------------------------------------------
        | Circuit Breaker Model
        |--------------------------------------------------------------------------
        |
        | This model manages circuit breaker state for protected services.
        |
        */

        'circuit_breaker' => CircuitBreaker::class,

        /*
        |--------------------------------------------------------------------------
        | Resource Violation Model
        |--------------------------------------------------------------------------
        |
        | This model logs when jobs exceed configured resource limits.
        |
        */

        'resource_violation' => ResourceViolation::class,

        /*
        |--------------------------------------------------------------------------
        | Job Health Check Model
        |--------------------------------------------------------------------------
        |
        | This model stores health check results for supervised jobs.
        |
        */

        'job_health_check' => JobHealthCheck::class,

        /*
        |--------------------------------------------------------------------------
        | Dead Letter Job Model
        |--------------------------------------------------------------------------
        |
        | This model stores jobs that have permanently failed after exceeding
        | retry attempts or encountering unrecoverable errors.
        |
        */

        'dead_letter_job' => DeadLetterJob::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Table Names
    |--------------------------------------------------------------------------
    |
    | Chaperone uses these table names to store supervised job records,
    | health metrics, and error logs. You may customize these names to fit
    | your application's database schema conventions.
    |
    */

    'table_names' => [
        /*
        |--------------------------------------------------------------------------
        | Supervised Jobs Table
        |--------------------------------------------------------------------------
        |
        | This table stores records of all supervised jobs including their
        | health status, resource usage, and execution metrics.
        |
        */

        'supervised_jobs' => env('CHAPERONE_JOBS_TABLE', 'supervised_jobs'),

        /*
        |--------------------------------------------------------------------------
        | Supervised Job Errors Table
        |--------------------------------------------------------------------------
        |
        | This table stores detailed error information when jobs fail,
        | including exception messages, stack traces, and context data.
        |
        */

        'supervised_job_errors' => env('CHAPERONE_ERRORS_TABLE', 'supervised_job_errors'),

        /*
        |--------------------------------------------------------------------------
        | Heartbeats Table
        |--------------------------------------------------------------------------
        |
        | This table stores periodic health signals and resource usage from running jobs.
        |
        */

        'heartbeats' => env('CHAPERONE_HEARTBEATS_TABLE', 'heartbeats'),

        /*
        |--------------------------------------------------------------------------
        | Circuit Breakers Table
        |--------------------------------------------------------------------------
        |
        | This table stores circuit breaker state for protected services.
        |
        */

        'circuit_breakers' => env('CHAPERONE_CIRCUIT_BREAKERS_TABLE', 'circuit_breakers'),

        /*
        |--------------------------------------------------------------------------
        | Resource Violations Table
        |--------------------------------------------------------------------------
        |
        | This table stores records of jobs that exceeded resource limits.
        |
        */

        'resource_violations' => env('CHAPERONE_RESOURCE_VIOLATIONS_TABLE', 'resource_violations'),

        /*
        |--------------------------------------------------------------------------
        | Job Health Checks Table
        |--------------------------------------------------------------------------
        |
        | This table stores health check results for supervised jobs.
        |
        */

        'job_health_checks' => env('CHAPERONE_JOB_HEALTH_CHECKS_TABLE', 'job_health_checks'),

        /*
        |--------------------------------------------------------------------------
        | Dead Letter Queue Table
        |--------------------------------------------------------------------------
        |
        | This table stores jobs that have permanently failed after exceeding
        | retry attempts or encountering unrecoverable errors.
        |
        */

        'dead_letter_queue' => env('CHAPERONE_DLQ_TABLE', 'dead_letter_queue'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Supervision Configuration
    |--------------------------------------------------------------------------
    |
    | These options control the default supervision settings for monitored jobs.
    | These can be overridden on a per-job basis.
    |
    */

    'supervision' => [
        /*
        |--------------------------------------------------------------------------
        | Timeout
        |--------------------------------------------------------------------------
        |
        | Maximum execution time in seconds before a job is considered stuck
        | and marked for intervention. Set to 0 to disable timeout supervision.
        |
        */

        'timeout' => env('CHAPERONE_TIMEOUT', 3600),

        /*
        |--------------------------------------------------------------------------
        | Memory Limit
        |--------------------------------------------------------------------------
        |
        | Maximum memory usage in megabytes before a job is terminated.
        | Set to 0 to disable memory limit supervision.
        |
        */

        'memory_limit' => env('CHAPERONE_MEMORY_LIMIT', 512),

        /*
        |--------------------------------------------------------------------------
        | CPU Limit
        |--------------------------------------------------------------------------
        |
        | Maximum CPU percentage a job should consume (0-100).
        | Set to 0 to disable CPU limit supervision.
        |
        */

        'cpu_limit' => env('CHAPERONE_CPU_LIMIT', 80),

        /*
        |--------------------------------------------------------------------------
        | Heartbeat Interval
        |--------------------------------------------------------------------------
        |
        | Interval in seconds at which jobs should report their health status.
        | Jobs failing to report within this interval may be marked as unhealthy.
        |
        */

        'heartbeat_interval' => env('CHAPERONE_HEARTBEAT_INTERVAL', 60),

        /*
        |--------------------------------------------------------------------------
        | Max Retries
        |--------------------------------------------------------------------------
        |
        | Maximum number of retry attempts for failed jobs before they are
        | moved to the dead letter queue.
        |
        */

        'max_retries' => env('CHAPERONE_MAX_RETRIES', 3),

        /*
        |--------------------------------------------------------------------------
        | Retry Delay
        |--------------------------------------------------------------------------
        |
        | Base delay in seconds before retrying a failed job. Uses exponential
        | backoff: delay * (2 ^ attempt_number).
        |
        */

        'retry_delay' => env('CHAPERONE_RETRY_DELAY', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker Configuration
    |--------------------------------------------------------------------------
    |
    | Circuit breakers prevent cascading failures by temporarily stopping
    | execution of jobs that are consistently failing.
    |
    */

    'circuit_breaker' => [
        /*
        |--------------------------------------------------------------------------
        | Enabled
        |--------------------------------------------------------------------------
        |
        | Enable or disable circuit breaker functionality globally.
        |
        */

        'enabled' => env('CHAPERONE_CIRCUIT_BREAKER_ENABLED', true),

        /*
        |--------------------------------------------------------------------------
        | Failure Threshold
        |--------------------------------------------------------------------------
        |
        | Number of consecutive failures before the circuit breaker trips to Open.
        |
        */

        'failure_threshold' => env('CHAPERONE_CIRCUIT_BREAKER_THRESHOLD', 5),

        /*
        |--------------------------------------------------------------------------
        | Success Threshold
        |--------------------------------------------------------------------------
        |
        | Number of consecutive successes in HalfOpen state before closing circuit.
        |
        */

        'success_threshold' => env('CHAPERONE_CIRCUIT_BREAKER_SUCCESS_THRESHOLD', 2),

        /*
        |--------------------------------------------------------------------------
        | Timeout
        |--------------------------------------------------------------------------
        |
        | Seconds the circuit breaker remains Open before transitioning to HalfOpen
        | to allow test executions.
        |
        */

        'timeout' => env('CHAPERONE_CIRCUIT_BREAKER_TIMEOUT', 300),

        /*
        |--------------------------------------------------------------------------
        | Half Open Attempts
        |--------------------------------------------------------------------------
        |
        | Maximum number of jobs allowed to execute in HalfOpen state before
        | requiring success threshold to be met.
        |
        */

        'half_open_attempts' => env('CHAPERONE_CIRCUIT_BREAKER_HALF_OPEN_ATTEMPTS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dead Letter Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for handling jobs that have permanently failed.
    |
    */

    'dead_letter_queue' => [
        /*
        |--------------------------------------------------------------------------
        | Enabled
        |--------------------------------------------------------------------------
        |
        | Enable or disable dead letter queue functionality.
        |
        */

        'enabled' => env('CHAPERONE_DLQ_ENABLED', true),

        /*
        |--------------------------------------------------------------------------
        | Retention Period
        |--------------------------------------------------------------------------
        |
        | Number of days to retain dead letter queue entries before automatic
        | cleanup. Set to 0 to keep entries indefinitely.
        |
        */

        'retention_period' => env('CHAPERONE_DLQ_RETENTION_DAYS', 30),

        /*
        |--------------------------------------------------------------------------
        | Cleanup Schedule
        |--------------------------------------------------------------------------
        |
        | Cron expression for automatic cleanup of expired dead letter entries.
        |
        */

        'cleanup_schedule' => env('CHAPERONE_DLQ_CLEANUP_SCHEDULE', '0 2 * * *'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Resource Limits
    |--------------------------------------------------------------------------
    |
    | Global resource limits that apply to all supervised jobs unless
    | overridden at the job level.
    |
    */

    'resource_limits' => [
        /*
        |--------------------------------------------------------------------------
        | Disk Space Threshold
        |--------------------------------------------------------------------------
        |
        | Minimum free disk space in megabytes required for job execution.
        | Jobs won't start if available disk space is below this threshold.
        |
        */

        'disk_space_threshold' => env('CHAPERONE_DISK_SPACE_THRESHOLD', 1024),

        /*
        |--------------------------------------------------------------------------
        | Connection Pool Limit
        |--------------------------------------------------------------------------
        |
        | Maximum number of concurrent database connections allowed.
        |
        */

        'connection_pool_limit' => env('CHAPERONE_CONNECTION_POOL_LIMIT', 10),

        /*
        |--------------------------------------------------------------------------
        | File Descriptor Limit
        |--------------------------------------------------------------------------
        |
        | Maximum number of file descriptors a job may use.
        |
        */

        'file_descriptor_limit' => env('CHAPERONE_FILE_DESCRIPTOR_LIMIT', 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring Configuration
    |--------------------------------------------------------------------------
    |
    | Configure integration with Laravel monitoring and observability tools.
    |
    */

    'monitoring' => [
        /*
        |--------------------------------------------------------------------------
        | Laravel Pulse Integration
        |--------------------------------------------------------------------------
        |
        | When enabled, job health events and metrics are recorded in Laravel Pulse
        | for real-time monitoring and visualization.
        |
        */

        'pulse' => env('CHAPERONE_PULSE_ENABLED', false),

        /*
        |--------------------------------------------------------------------------
        | Laravel Telescope Integration
        |--------------------------------------------------------------------------
        |
        | When enabled, job events are recorded in Laravel Telescope for
        | detailed debugging and request inspection.
        |
        */

        'telescope' => env('CHAPERONE_TELESCOPE_ENABLED', false),

        /*
        |--------------------------------------------------------------------------
        | Laravel Horizon Integration
        |--------------------------------------------------------------------------
        |
        | When enabled, Chaperone integrates with Horizon for queue monitoring
        | and provides enhanced job supervision metrics.
        |
        */

        'horizon' => env('CHAPERONE_HORIZON_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerting Configuration
    |--------------------------------------------------------------------------
    |
    | Configure alerting channels and thresholds for critical job events.
    |
    */

    'alerting' => [
        /*
        |--------------------------------------------------------------------------
        | Enabled
        |--------------------------------------------------------------------------
        |
        | Enable or disable alerting functionality globally.
        |
        */

        'enabled' => env('CHAPERONE_ALERTING_ENABLED', true),

        /*
        |--------------------------------------------------------------------------
        | Channels
        |--------------------------------------------------------------------------
        |
        | Notification channels to use for alerts. Uses Laravel's notification
        | system. Available channels: mail, slack, database, broadcast, etc.
        |
        */

        'channels' => explode(',', env('CHAPERONE_ALERT_CHANNELS', 'mail,slack')),

        /*
        |--------------------------------------------------------------------------
        | Slack Webhook URL
        |--------------------------------------------------------------------------
        |
        | Slack webhook URL for sending alerts when using the slack channel.
        |
        */

        'slack_webhook_url' => env('CHAPERONE_SLACK_WEBHOOK_URL'),

        /*
        |--------------------------------------------------------------------------
        | Alert Recipients
        |--------------------------------------------------------------------------
        |
        | Email addresses or user IDs to notify when alerts are triggered.
        |
        */

        'recipients' => explode(',', env('CHAPERONE_ALERT_RECIPIENTS', '')),

        /*
        |--------------------------------------------------------------------------
        | Thresholds
        |--------------------------------------------------------------------------
        |
        | Configure thresholds for different alert types.
        |
        */

        'thresholds' => [
            /*
            |----------------------------------------------------------------------
            | Error Rate Threshold
            |----------------------------------------------------------------------
            |
            | Percentage of failed jobs (0-100) that triggers an alert.
            |
            */

            'error_rate' => env('CHAPERONE_ALERT_ERROR_RATE', 10),

            /*
            |----------------------------------------------------------------------
            | Response Time Threshold
            |----------------------------------------------------------------------
            |
            | Maximum average execution time in seconds before triggering alert.
            |
            */

            'response_time' => env('CHAPERONE_ALERT_RESPONSE_TIME', 300),

            /*
            |----------------------------------------------------------------------
            | Queue Length Threshold
            |----------------------------------------------------------------------
            |
            | Maximum number of pending jobs before triggering alert.
            |
            */

            'queue_length' => env('CHAPERONE_ALERT_QUEUE_LENGTH', 1000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Recording
    |--------------------------------------------------------------------------
    |
    | Configure how job failures are recorded and reported.
    |
    */

    'errors' => [
        /*
        |--------------------------------------------------------------------------
        | Record Errors
        |--------------------------------------------------------------------------
        |
        | When enabled, job failures are stored in the database with
        | full stack traces and context for debugging.
        |
        */

        'record' => env('CHAPERONE_RECORD_ERRORS', true),

        /*
        |--------------------------------------------------------------------------
        | Log Channel
        |--------------------------------------------------------------------------
        |
        | The log channel to use for job errors. Errors are always
        | logged regardless of database recording.
        |
        */

        'log_channel' => env('CHAPERONE_LOG_CHANNEL', 'stack'),

        /*
        |--------------------------------------------------------------------------
        | Include Job Payload
        |--------------------------------------------------------------------------
        |
        | Whether to include the full job payload in error records.
        | May contain sensitive data, so disable in production if needed.
        |
        */

        'include_payload' => env('CHAPERONE_INCLUDE_PAYLOAD', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | These options control which queues are supervised by Chaperone.
    |
    */

    'queue' => [
        /*
        |--------------------------------------------------------------------------
        | Supervised Queues
        |--------------------------------------------------------------------------
        |
        | List of queue names that should be supervised. Leave empty to
        | supervise all queues.
        |
        */

        'supervised_queues' => explode(',', env('CHAPERONE_SUPERVISED_QUEUES', '')),

        /*
        |--------------------------------------------------------------------------
        | Excluded Queues
        |--------------------------------------------------------------------------
        |
        | List of queue names that should NOT be supervised.
        |
        */

        'excluded_queues' => explode(',', env('CHAPERONE_EXCLUDED_QUEUES', '')),

        /*
        |--------------------------------------------------------------------------
        | Connection
        |--------------------------------------------------------------------------
        |
        | The queue connection to use for Chaperone's internal jobs.
        | Leave null to use the default queue connection.
        |
        */

        'connection' => env('CHAPERONE_QUEUE_CONNECTION'),
    ],
];

// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ //
// Here endeth thy configuration, noble developer!                            //
// Beyond: code so wretched, even wyrms learned the scribing arts.            //
// Forsooth, they but penned "// TODO: remedy ere long"                       //
// Three realms have fallen since...                                          //
// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ //
//                                                  .~))>>                    //
//                                                 .~)>>                      //
//                                               .~))))>>>                    //
//                                             .~))>>             ___         //
//                                           .~))>>)))>>      .-~))>>         //
//                                         .~)))))>>       .-~))>>)>          //
//                                       .~)))>>))))>>  .-~)>>)>              //
//                   )                 .~))>>))))>>  .-~)))))>>)>             //
//                ( )@@*)             //)>))))))  .-~))))>>)>                 //
//              ).@(@@               //))>>))) .-~))>>)))))>>)>               //
//            (( @.@).              //))))) .-~)>>)))))>>)>                   //
//          ))  )@@*.@@ )          //)>))) //))))))>>))))>>)>                 //
//       ((  ((@@@.@@             |/))))) //)))))>>)))>>)>                    //
//      )) @@*. )@@ )   (\_(\-\b  |))>)) //)))>>)))))))>>)>                   //
//    (( @@@(.@(@ .    _/`-`  ~|b |>))) //)>>)))))))>>)>                      //
//     )* @@@ )@*     (@)  (@) /\b|))) //))))))>>))))>>                       //
//   (( @. )@( @ .   _/  /    /  \b)) //))>>)))))>>>_._                       //
//    )@@ (@@*)@@.  (6///6)- / ^  \b)//))))))>>)))>>   ~~-.                   //
// ( @jgs@@. @@@.*@_ VvvvvV//  ^  \b/)>>))))>>      _.     `bb                //
//  ((@@ @@@*.(@@ . - | o |' \ (  ^   \b)))>>        .'       b`,             //
//   ((@@).*@@ )@ )   \^^^/  ((   ^  ~)_        \  /           b `,           //
//     (@@. (@@ ).     `-'   (((   ^    `\ \ \ \ \|             b  `.         //
//       (*.@*              / ((((        \| | |  \       .       b `.        //
//                         / / (((((  \    \ /  _.-~\     Y,      b  ;        //
//                        / / / (((((( \    \.-~   _.`" _.-~`,    b  ;        //
//                       /   /   `(((((()    )    (((((~      `,  b  ;        //
//                     _/  _/      `"""/   /'                  ; b   ;        //
//                 _.-~_.-~           /  /'              _.'~bb _.'         //
//               ((((~~              / /'              _.'~bb.--~             //
//                                  ((((          __.-~bb.-~                  //
//                                              .'  b .~~                     //
//                                              :bb ,'                        //
//                                              ~~~~                          //
// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ //
