<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\DeadLetterQueue;

use Cline\Chaperone\Database\Models\DeadLetterJob;
use Cline\Chaperone\Database\Models\SupervisedJob;
use Cline\Chaperone\Events\JobMovedToDeadLetterQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Throwable;

use function class_exists;
use function config;
use function dispatch;
use function sprintf;
use function throw_if;
use function throw_unless;

/**
 * Manages the dead letter queue for permanently failed jobs.
 *
 * Provides functionality to move failed jobs to a dead letter queue after they exceed
 * retry attempts, retrieve dead letter entries, retry failed jobs, and prune old entries.
 * Integrates with the supervision system to track jobs that cannot be recovered.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class DeadLetterQueueManager
{
    /**
     * Move a supervised job to the dead letter queue.
     *
     * Records the job failure with complete exception details, stack trace, and payload
     * for later analysis or retry. Fires JobMovedToDeadLetterQueue event for monitoring.
     *
     * @param SupervisedJob $job       The supervised job that failed
     * @param Throwable     $exception The exception that caused the failure
     */
    public function moveToDeadLetterQueue(SupervisedJob $job, Throwable $exception): void
    {
        if (!config('chaperone.dead_letter_queue.enabled', true)) {
            return;
        }

        /** @var class-string<DeadLetterJob> $model */
        $model = Config::get('chaperone.models.dead_letter_job', DeadLetterJob::class);

        $model::query()->create([
            'supervised_job_id' => $job->id,
            'job_class' => $job->job_class,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'payload' => $job->metadata,
            'failed_at' => Date::now(),
        ]);

        Event::dispatch(
            new JobMovedToDeadLetterQueue(
                supervisionId: (string) $job->id,
                jobClass: $job->job_class,
                exception: $exception,
                failedAt: Date::now(),
            )
        );
    }

    /**
     * Retry a dead letter job by re-dispatching it to the queue.
     *
     * Retrieves the dead letter entry, extracts its payload, and attempts to
     * re-dispatch the job. Marks the entry with the retry timestamp.
     *
     * @param string $deadLetterId The ID of the dead letter entry to retry
     *
     * @throws RuntimeException If the dead letter entry doesn't exist or cannot be retried
     */
    public function retry(string $deadLetterId): void
    {
        $deadLetterJob = $this->get($deadLetterId);

        throw_if($deadLetterJob === null, RuntimeException::class, sprintf('Dead letter job %s not found', $deadLetterId));

        /** @var class-string<DeadLetterJob> $model */
        $model = Config::get('chaperone.models.dead_letter_job', DeadLetterJob::class);

        /** @var DeadLetterJob $entry */
        $entry = $model::query()->findOrFail($deadLetterId);

        // Mark as retried
        $entry->update([
            'retried_at' => Date::now(),
        ]);

        // Re-dispatch the job (implementation depends on job class and payload structure)
        $jobClass = $entry->job_class;

        throw_unless(class_exists($jobClass), RuntimeException::class, sprintf('Job class %s does not exist', $jobClass));

        // Dispatch the job with the stored payload
        dispatch(
            new $jobClass(...($entry->payload ?? []))
        );
    }

    /**
     * Prune dead letter queue entries older than the specified number of days.
     *
     * Deletes entries that have exceeded the retention period. Uses the configured
     * retention period from chaperone.dead_letter_queue.retention_period by default.
     *
     * @param null|int $days Number of days to retain entries (null uses config)
     *
     * @return int Number of entries deleted
     */
    public function prune(?int $days = null): int
    {
        $retentionDays = $days ?? (int) config('chaperone.dead_letter_queue.retention_period', 30);

        if ($retentionDays === 0) {
            return 0; // Keep indefinitely
        }

        $cutoff = Date::now()->subDays($retentionDays);

        /** @var class-string<DeadLetterJob> $model */
        $model = Config::get('chaperone.models.dead_letter_job', DeadLetterJob::class);

        return $model::query()->where('failed_at', '<', $cutoff)->delete();
    }

    /**
     * Retrieve a specific dead letter queue entry.
     *
     * @param string $deadLetterId The ID of the dead letter entry
     *
     * @return null|array<string, mixed> Dead letter entry data or null if not found
     */
    public function get(string $deadLetterId): ?array
    {
        /** @var class-string<DeadLetterJob> $model */
        $model = Config::get('chaperone.models.dead_letter_job', DeadLetterJob::class);

        $entry = $model::query()->find($deadLetterId);

        if ($entry === null) {
            return null;
        }

        return [
            'id' => $entry->id,
            'supervised_job_id' => $entry->supervised_job_id,
            'job_class' => $entry->job_class,
            'exception' => $entry->exception,
            'message' => $entry->message,
            'trace' => $entry->trace,
            'payload' => $entry->payload,
            'failed_at' => $entry->failed_at,
            'retried_at' => $entry->retried_at,
        ];
    }

    /**
     * Retrieve all dead letter queue entries.
     *
     * Returns entries ordered by most recent failures first, with optional
     * relationships loaded for supervised job details.
     *
     * @return Collection<int, DeadLetterJob> Collection of all dead letter entries
     */
    public function all(): Collection
    {
        /** @var class-string<DeadLetterJob> $model */
        $model = Config::get('chaperone.models.dead_letter_job', DeadLetterJob::class);

        return $model::query()
            ->with('supervisedJob')
            ->latest('failed_at')
            ->get();
    }

    /**
     * Get the total count of entries in the dead letter queue.
     *
     * @return int Number of entries currently in the dead letter queue
     */
    public function count(): int
    {
        /** @var class-string<DeadLetterJob> $model */
        $model = Config::get('chaperone.models.dead_letter_job', DeadLetterJob::class);

        return $model::query()->count();
    }
}
