<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Events;

/**
 * Event dispatched when a job exceeds its maximum allowed duration.
 *
 * Fired when a supervised job runs longer than its configured timeout limit.
 * Unlike JobStuck which indicates lack of progress, this event strictly
 * enforces a time-based limit regardless of job activity.
 *
 * Used to enforce SLA requirements, prevent runaway jobs from consuming
 * resources indefinitely, and trigger timeout recovery procedures. Provides
 * both the configured limit and actual duration for analysis.
 *
 * ```php
 * Event::listen(JobTimeout::class, function ($event) {
 *     Log::warning("Job timeout", [
 *         'supervision_id' => $event->supervisionId,
 *         'job_id' => $event->jobId,
 *         'timeout_limit' => $event->timeoutSeconds,
 *         'actual_duration' => $event->actualDuration,
 *     ]);
 *
 *     // Force terminate the job
 *     JobTerminator::kill($event->jobId);
 *
 *     // Queue retry with extended timeout
 *     RetryQueue::dispatch($event->jobId, timeout: $event->timeoutSeconds * 2);
 * });
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class JobTimeout
{
    /**
     * Create a new job timeout event.
     *
     * @param string $supervisionId  Unique identifier for the supervision session
     * @param string $jobId          The job instance identifier that timed out
     * @param int    $timeoutSeconds The configured timeout limit in seconds
     * @param int    $actualDuration The actual duration the job ran in milliseconds
     */
    public function __construct(
        public string $supervisionId,
        public string $jobId,
        public int $timeoutSeconds,
        public int $actualDuration,
    ) {}
}
