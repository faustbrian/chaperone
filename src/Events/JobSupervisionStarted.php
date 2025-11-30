<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Events;

use DateTimeImmutable;

/**
 * Event dispatched when job supervision begins.
 *
 * Fired immediately when a job enters supervision, before any monitoring
 * or heartbeat tracking starts. Provides the supervision session identifier
 * and job metadata for logging and tracking purposes.
 *
 * Pairs with JobSupervisionEnded to bracket the entire supervision lifecycle,
 * enabling measurement of total supervision duration and resource tracking
 * across the supervised job's execution.
 *
 * ```php
 * Event::listen(JobSupervisionStarted::class, function ($event) {
 *     Log::info("Supervision started for {$event->jobClass}", [
 *         'supervision_id' => $event->supervisionId,
 *         'job_id' => $event->jobId,
 *     ]);
 * });
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final readonly class JobSupervisionStarted
{
    /**
     * Create a new job supervision started event.
     *
     * @param string            $supervisionId Unique identifier for this supervision session
     * @param string            $jobId         The job instance identifier being supervised
     * @param string            $jobClass      Fully qualified class name of the supervised job
     * @param DateTimeImmutable $startedAt     Timestamp when supervision began
     */
    public function __construct(
        public string $supervisionId,
        public string $jobId,
        public string $jobClass,
        public DateTimeImmutable $startedAt,
    ) {}
}
