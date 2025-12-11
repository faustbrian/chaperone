<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Queue;

use Cline\Chaperone\Supervisors\JobSupervisor;
use Illuminate\Support\Facades\Log;
use Throwable;

use function is_string;
use function property_exists;
use function resolve;

/**
 * Laravel queue middleware for automatic job supervision.
 *
 * Automatically starts supervision for jobs on configured queues. Checks if the
 * job's queue should be supervised using QueueFilter and initiates supervision
 * accordingly. Skips supervision for excluded queues or queues not in the
 * supervised list.
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class SupervisionMiddleware
{
    /**
     * Create a new supervision middleware instance.
     *
     * @param QueueFilter $queueFilter Service for determining which queues to supervise
     */
    public function __construct(
        private QueueFilter $queueFilter,
    ) {}

    /**
     * Process the queued job through supervision middleware.
     *
     * Checks if the job's queue should be supervised and automatically starts
     * supervision if configured. Passes execution to the next middleware
     * regardless of supervision status.
     *
     * @param mixed    $job  The queued job instance
     * @param callable $next The next middleware in the chain
     *
     * @return mixed Result from the next middleware
     */
    public function handle(mixed $job, callable $next): mixed
    {
        // Get the queue name from the job
        $queueName = $this->getQueueName($job);

        // Check if this queue should be supervised
        if ($this->queueFilter->shouldSupervise($queueName)) {
            $this->startSupervision($job, $queueName);
        } else {
            Log::debug('Skipping supervision for job', [
                'job_class' => $job::class,
                'queue' => $queueName,
                'reason' => 'Queue not configured for supervision',
            ]);
        }

        return $next($job);
    }

    /**
     * Get the queue name for a job.
     *
     * Extracts the queue name from the job instance, falling back to 'default'
     * if not specified.
     *
     * @param mixed $job The queued job instance
     *
     * @return string The queue name
     */
    private function getQueueName(mixed $job): string
    {
        // Try to get queue from job property
        if (property_exists($job, 'queue') && is_string($job->queue)) {
            return $job->queue;
        }

        // Try to get from connection property
        if (property_exists($job, 'connection') && is_string($job->connection)) {
            return $job->connection;
        }

        // Fall back to default
        return 'default';
    }

    /**
     * Start supervision for the job.
     *
     * Initializes a JobSupervisor instance and starts monitoring the job's
     * execution, resource usage, and health status.
     *
     * @param mixed  $job       The queued job instance
     * @param string $queueName The name of the queue the job is on
     */
    private function startSupervision(mixed $job, string $queueName): void
    {
        try {
            $supervisor = resolve(JobSupervisor::class);
            $jobClass = $job::class;

            $supervisor->supervise($jobClass);

            Log::info('Started supervision for job', [
                'job_class' => $jobClass,
                'queue' => $queueName,
                'supervision_id' => $supervisor->getSupervisionId(),
            ]);
        } catch (Throwable $throwable) {
            // Don't let supervision errors break job execution
            Log::error('Failed to start supervision for job', [
                'job_class' => $job::class,
                'queue' => $queueName,
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}
