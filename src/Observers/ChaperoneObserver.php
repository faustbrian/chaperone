<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Observers;

use Cline\Chaperone\Database\Models\SupervisedJob;
use Cline\Chaperone\Database\Models\SupervisedJobError;
use Cline\Chaperone\DeadLetterQueue\DeadLetterQueueManager;
use Illuminate\Support\Facades\Config;

/**
 * Observer for broadcasting Chaperone supervision events to monitoring tools.
 *
 * Integrates with Laravel Pulse, Telescope, and Horizon to provide real-time monitoring
 * and historical tracking of supervision lifecycle events. Automatically records
 * job creation, completion, and deletion events when these monitoring tools are
 * enabled in the application configuration.
 *
 * The observer uses Laravel's model event system to intercept database changes
 * and broadcast them to configured monitoring tools. Events are only recorded
 * if the respective tools are installed and enabled in chaperone.monitoring config.
 *
 * Configuration options:
 * - chaperone.monitoring.pulse: Enable Pulse integration for real-time metrics
 * - chaperone.monitoring.telescope: Enable Telescope integration for debugging
 * - chaperone.monitoring.horizon: Enable Horizon integration for queue monitoring
 *
 * @author Brian Faust <brian@cline.sh>
 */
final readonly class ChaperoneObserver
{
    private PulseRecorder $pulseRecorder;

    private TelescopeRecorder $telescopeRecorder;

    /**
     * Create a new observer instance.
     *
     * Initializes the monitoring recorders for Pulse, Telescope, and Horizon.
     * Each recorder handles the integration with its respective monitoring tool.
     */
    public function __construct(private DeadLetterQueueManager $deadLetterQueueManager)
    {
        $this->pulseRecorder = new PulseRecorder();
        $this->telescopeRecorder = new TelescopeRecorder();
    }

    /**
     * Handle the supervised job created event.
     *
     * Records the supervision start event to Pulse and Telescope when the
     * supervised job record is first created in the database. This occurs when
     * a job begins supervision, providing visibility into active supervised jobs.
     *
     * @param SupervisedJob $job The supervised job model instance that was created in the
     *                           database. Contains job name, queue, timestamps, and metadata
     *                           for monitoring and audit trail purposes.
     */
    public function created(SupervisedJob $job): void
    {
        if (!Config::get('chaperone.monitoring.enabled', false)) {
            return;
        }

        $this->pulseRecorder->recordSupervisionStarted($job);
        $this->telescopeRecorder->recordSupervisionStarted($job);
    }

    /**
     * Handle the supervised job updated event.
     *
     * Monitors state changes on the supervised job model and records appropriate
     * events when completion, failure, or timeout timestamps are set for
     * the first time. Only fires when the specific timestamp fields change
     * to prevent duplicate event recording on subsequent updates.
     *
     * When a job fails and has exceeded max retries, it's automatically moved
     * to the dead letter queue for permanent failure tracking.
     *
     * @param SupervisedJob $job The supervised job model instance that was updated in the
     *                           database. Checked for changes in completed_at, failed_at,
     *                           and other lifecycle timestamps to determine which event
     *                           occurred and should be recorded to monitoring tools.
     */
    public function updated(SupervisedJob $job): void
    {
        if (!Config::get('chaperone.monitoring.enabled', false)) {
            return;
        }

        if ($job->completed_at && $job->wasChanged('completed_at')) {
            $this->pulseRecorder->recordSupervisionEnded($job);
            $this->telescopeRecorder->recordSupervisionEnded($job);
        }

        // Move to dead letter queue if job failed and exceeded max retries
        if ($job->failed_at && $job->wasChanged('failed_at')) {
            $this->handleJobFailure($job);
        }
    }

    /**
     * Handle job failure and move to dead letter queue if needed.
     *
     * Checks if the job has exceeded the maximum retry attempts and moves it
     * to the dead letter queue for permanent failure tracking. Uses the most
     * recent error from the job's error collection to provide failure context.
     *
     * @param SupervisedJob $job The failed supervised job
     */
    private function handleJobFailure(SupervisedJob $job): void
    {
        if (!Config::get('chaperone.dead_letter_queue.enabled', true)) {
            return;
        }

        // Get the error count for this job
        $errorCount = $job->errors()->count();
        $maxRetries = (int) Config::get('chaperone.supervision.max_retries', 3);

        // Move to DLQ if exceeded max retries
        if ($errorCount >= $maxRetries) {
            // Get the most recent error to include in DLQ
            $lastError = $job->errors()->latest('created_at')->first();

            if ($lastError instanceof SupervisedJobError) {
                // Create a throwable from the error record
                $exception = new \RuntimeException(
                    $lastError->message,
                    0
                );

                $this->deadLetterQueueManager->moveToDeadLetterQueue($job, $exception);
            }
        }
    }

    /**
     * Handle the supervised job deleted event.
     *
     * Records the supervision deletion event to monitoring tools when a
     * supervised job record is removed from the database. This helps track
     * cleanup operations and supervision lifecycle completeness.
     *
     * @param SupervisedJob $job The supervised job model instance that was deleted from
     *                           the database. Contains job information for final audit
     *                           trail recording before removal from active monitoring.
     */
    public function deleted(SupervisedJob $job): void
    {
        if (!Config::get('chaperone.monitoring.enabled', false)) {
            return;
        }

        $this->pulseRecorder->recordSupervisionEnded($job);
        $this->telescopeRecorder->recordSupervisionEnded($job);
    }
}
