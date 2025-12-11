<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Console\Commands;

use Cline\Chaperone\Database\Models\SupervisedJob;
use Cline\Chaperone\Enums\SupervisedJobStatus;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;

use function sprintf;

/**
 * Artisan command to find and manage stuck jobs.
 *
 * Identifies supervised jobs that have stopped responding (no heartbeats within
 * the configured timeout period) and provides options to kill or requeue them.
 * Essential for maintaining system health and recovering from stalled jobs.
 *
 * ```bash
 * # List all stuck jobs without taking action
 * php artisan chaperone:stuck
 *
 * # Kill all stuck jobs
 * php artisan chaperone:stuck --kill
 *
 * # Requeue all stuck jobs for retry
 * php artisan chaperone:stuck --requeue
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class StuckJobsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Supports two optional flags for managing stuck jobs:
     * - kill: Mark stuck jobs as failed and prevent further execution
     * - requeue: Attempt to requeue stuck jobs for another execution attempt
     *
     * @var string
     */
    protected $signature = 'chaperone:stuck
                            {--kill : Kill stuck jobs}
                            {--requeue : Requeue stuck jobs}';

    /**
     * The console command description shown in artisan list output.
     *
     * @var string
     */
    protected $description = 'Find and manage stuck jobs that have stopped responding';

    /**
     * Execute the console command to find and manage stuck jobs.
     *
     * Identifies jobs that haven't sent heartbeats within the configured timeout
     * period. Routes to appropriate action method based on flags, or displays
     * stuck jobs information if no action is specified.
     *
     * @return int Command exit code: self::SUCCESS (0) if operation completes
     *             successfully, self::FAILURE (1) if validation fails
     */
    public function handle(): int
    {
        $kill = (bool) $this->option('kill');
        $requeue = (bool) $this->option('requeue');

        if ($kill && $requeue) {
            $this->components->error('Cannot use both --kill and --requeue flags. Choose one.');

            return self::FAILURE;
        }

        $stuckJobs = $this->getStuckJobs();

        if ($stuckJobs->isEmpty()) {
            $this->components->success('No stuck jobs found.');

            return self::SUCCESS;
        }

        if ($kill) {
            return $this->killStuckJobs($stuckJobs);
        }

        if ($requeue) {
            return $this->requeueStuckJobs($stuckJobs);
        }

        // Default: just display stuck jobs
        return $this->displayStuckJobs($stuckJobs);
    }

    /**
     * Get all stuck jobs based on heartbeat timeout.
     *
     * Queries for jobs in Running status that either have no heartbeat or their
     * last heartbeat is older than the configured timeout period.
     *
     * @return Collection<int, SupervisedJob> Collection of stuck jobs
     */
    private function getStuckJobs(): Collection
    {
        $heartbeatTimeout = (int) Config::get('chaperone.heartbeat.timeout', 300);
        $cutoff = Date::now()->subSeconds($heartbeatTimeout);

        /** @var Collection<int, SupervisedJob> */
        return SupervisedJob::query()
            ->where('status', SupervisedJobStatus::Running)
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('last_heartbeat_at')
                    ->orWhere('last_heartbeat_at', '<', $cutoff);
            })
            ->latest('started_at')
            ->get();
    }

    /**
     * Display stuck jobs without taking action.
     *
     * Shows a table of all stuck jobs with their supervision ID, job class,
     * how long they've been stuck, and when their last heartbeat was received.
     *
     * @param Collection<int, SupervisedJob> $stuckJobs Collection of stuck jobs
     *
     * @return int Command exit code: always returns self::SUCCESS (0)
     */
    private function displayStuckJobs(Collection $stuckJobs): int
    {
        $heartbeatTimeout = (int) Config::get('chaperone.heartbeat.timeout', 300);

        $this->components->warn('Stuck Jobs Detected');
        $this->newLine();

        $this->components->info(sprintf(
            'Jobs without heartbeat for more than %d seconds are considered stuck.',
            $heartbeatTimeout,
        ));

        $this->newLine();

        $this->table(
            ['Supervision ID', 'Job Class', 'Stuck Duration', 'Last Heartbeat', 'Started At'],
            $stuckJobs->map(fn (SupervisedJob $job): array => [
                $job->id,
                $job->job_class,
                $this->formatDuration($job->last_heartbeat_at ?? $job->started_at),
                $job->last_heartbeat_at?->format('Y-m-d H:i:s') ?? '<fg=red>Never</>',
                $job->started_at->format('Y-m-d H:i:s'),
            ])->all(),
        );

        $this->newLine();
        $this->components->info(sprintf('Total: %d stuck job(s)', $stuckJobs->count()));

        $this->newLine();
        $this->components->info('Use --kill to mark these jobs as failed or --requeue to retry them.');

        return self::SUCCESS;
    }

    /**
     * Kill all stuck jobs.
     *
     * Marks stuck jobs as failed and records the failure timestamp. This prevents
     * them from being considered active while acknowledging they did not complete
     * successfully.
     *
     * @param Collection<int, SupervisedJob> $stuckJobs Collection of stuck jobs
     *
     * @return int Command exit code: always returns self::SUCCESS (0)
     */
    private function killStuckJobs(Collection $stuckJobs): int
    {
        $this->components->warn(sprintf('Killing %d stuck job(s)...', $stuckJobs->count()));

        $now = Date::now();
        $killed = 0;

        foreach ($stuckJobs as $job) {
            $job->update([
                'status' => SupervisedJobStatus::Failed,
                'failed_at' => $now,
            ]);

            $this->components->twoColumnDetail(
                sprintf('Killed job #%s', $job->id),
                sprintf('<fg=red>%s</>', $job->job_class),
            );

            ++$killed;
        }

        $this->newLine();
        $this->components->success(sprintf('Successfully killed %d stuck job(s).', $killed));

        return self::SUCCESS;
    }

    /**
     * Requeue all stuck jobs.
     *
     * Attempts to requeue stuck jobs by marking them as stalled and creating new
     * job instances for retry. This is useful for jobs that may have encountered
     * temporary issues and can be safely retried.
     *
     * Note: This is a placeholder implementation. Actual requeueing logic would
     * depend on your queue implementation and job structure.
     *
     * @param Collection<int, SupervisedJob> $stuckJobs Collection of stuck jobs
     *
     * @return int Command exit code: always returns self::SUCCESS (0)
     */
    private function requeueStuckJobs(Collection $stuckJobs): int
    {
        $this->components->warn(sprintf('Requeueing %d stuck job(s)...', $stuckJobs->count()));

        $requeued = 0;

        foreach ($stuckJobs as $job) {
            // Mark the current job as stalled
            $job->update([
                'status' => SupervisedJobStatus::Stalled,
            ]);

            // TODO: Implement actual requeueing logic based on job type
            // This would typically involve:
            // 1. Extracting job payload from metadata
            // 2. Dispatching a new instance of the job
            // 3. Creating a new SupervisedJob record for the new instance

            $this->components->twoColumnDetail(
                sprintf('Marked job #%s as stalled', $job->id),
                sprintf('<fg=yellow>%s</>', $job->job_class),
            );

            ++$requeued;
        }

        $this->newLine();
        $this->components->warn(sprintf('Marked %d job(s) as stalled.', $requeued));
        $this->newLine();
        $this->components->info('Note: Automatic requeueing requires additional implementation.');
        $this->components->info('Jobs have been marked as stalled. Manual intervention may be required.');

        return self::SUCCESS;
    }

    /**
     * Format duration since timestamp in human-readable format.
     *
     * Converts the time difference between now and the given timestamp into
     * a human-readable string showing seconds, minutes, hours, or days.
     *
     * @param Carbon $timestamp The timestamp to calculate duration from
     *
     * @return string Human-readable duration with color coding for severity
     */
    private function formatDuration(Carbon $timestamp): string
    {
        $seconds = Date::now()->diffInSeconds($timestamp);

        $formatted = match (true) {
            $seconds < 60 => sprintf('%d second(s)', $seconds),
            $seconds < 3_600 => sprintf('%d minute(s)', (int) ($seconds / 60)),
            $seconds < 86_400 => sprintf('%d hour(s)', (int) ($seconds / 3_600)),
            default => sprintf('%d day(s)', (int) ($seconds / 86_400)),
        };

        // Color code based on duration severity
        $color = match (true) {
            $seconds < 300 => 'yellow',    // < 5 minutes: warning
            $seconds < 3_600 => 'red',      // < 1 hour: error
            default => 'magenta',          // >= 1 hour: critical
        };

        return sprintf('<fg=%s>%s</>', $color, $formatted);
    }
}
