<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Console\Commands;

use Cline\Chaperone\Database\Models\SupervisedJob;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

use function sprintf;

/**
 * Artisan command to check health status of supervised jobs.
 *
 * Provides health monitoring capabilities for supervised jobs, allowing inspection
 * of health check results for specific jobs or all jobs. Supports filtering to
 * show only unhealthy jobs for quick troubleshooting.
 *
 * ```bash
 * # Show health status for a specific supervision ID
 * php artisan chaperone:health 12345
 *
 * # Show all jobs with their health status
 * php artisan chaperone:health --all
 *
 * # Show only unhealthy jobs
 * php artisan chaperone:health --unhealthy
 *
 * # Combine filters: show all jobs but only unhealthy ones
 * php artisan chaperone:health --all --unhealthy
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class HealthCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Accepts an optional job argument for checking specific supervision IDs,
     * and two optional flags for filtering results:
     * - all: Show all jobs instead of just one
     * - unhealthy: Show only unhealthy jobs
     *
     * @var string
     */
    protected $signature = 'chaperone:health
                            {job? : Specific supervision ID to check}
                            {--all : Show all jobs}
                            {--unhealthy : Show only unhealthy jobs}';

    /**
     * The console command description shown in artisan list output.
     *
     * @var string
     */
    protected $description = 'Check health status of supervised jobs';

    /**
     * Execute the console command to check job health.
     *
     * Routes to appropriate health check method based on provided arguments and
     * options. When a specific job ID is provided, displays detailed health
     * information for that job. Otherwise displays health status for all jobs
     * or only unhealthy jobs based on flags.
     *
     * @return int Command exit code: self::SUCCESS (0) if health checks are displayed
     *             successfully, self::FAILURE (1) if the specified job is not found
     */
    public function handle(): int
    {
        $jobId = $this->argument('job');
        $showAll = (bool) $this->option('all');
        $showUnhealthy = (bool) $this->option('unhealthy');

        if ($jobId !== null) {
            return $this->displayJobHealth($jobId);
        }

        if ($showAll || $showUnhealthy) {
            return $this->displayAllJobsHealth($showUnhealthy);
        }

        $this->components->error('Please specify a job ID or use --all or --unhealthy flags.');

        return self::FAILURE;
    }

    /**
     * Display health status for a specific supervised job.
     *
     * Shows the job details along with its most recent health check result,
     * including health status, check timestamp, and reason for any failures.
     *
     * @param mixed $jobId The supervision ID to check
     *
     * @return int Command exit code: self::SUCCESS (0) if job is found,
     *             self::FAILURE (1) if job doesn't exist
     */
    private function displayJobHealth(mixed $jobId): int
    {
        /** @var null|SupervisedJob $job */
        $job = SupervisedJob::with(['healthChecks' => function ($query): void {
            $query->latest('checked_at')->limit(1);
        }])->find($jobId);

        if ($job === null) {
            $this->components->error(sprintf('Job with ID %s not found.', $jobId));

            return self::FAILURE;
        }

        $this->components->info(sprintf('Health Status for Job #%s', $job->id));
        $this->newLine();

        $this->components->twoColumnDetail('Job Class', $job->job_class);
        $this->components->twoColumnDetail('Status', $this->formatJobStatus($job->status->value));
        $this->components->twoColumnDetail('Started At', $job->started_at->format('Y-m-d H:i:s'));
        $this->components->twoColumnDetail(
            'Last Heartbeat',
            $job->last_heartbeat_at?->format('Y-m-d H:i:s') ?? '<fg=red>None</>',
        );

        $this->newLine();

        $latestHealthCheck = $job->healthChecks->first();

        if ($latestHealthCheck === null) {
            $this->components->warn('No health checks recorded for this job.');

            return self::SUCCESS;
        }

        $this->components->info('Latest Health Check');
        $this->newLine();

        $healthStatus = $latestHealthCheck->is_healthy
            ? '<fg=green>Healthy</>'
            : '<fg=red>Unhealthy</>';

        $this->components->twoColumnDetail('Status', $healthStatus);
        $this->components->twoColumnDetail('Checked At', $latestHealthCheck->checked_at->format('Y-m-d H:i:s'));

        if ($latestHealthCheck->reason !== null) {
            $this->components->twoColumnDetail('Reason', $latestHealthCheck->reason);
        }

        if ($latestHealthCheck->metadata !== null && $latestHealthCheck->metadata !== []) {
            $this->newLine();
            $this->components->info('Metadata');
            $this->table(
                ['Key', 'Value'],
                collect($latestHealthCheck->metadata)->map(fn (mixed $value, string $key): array => [
                    $key,
                    \is_array($value) ? json_encode($value) : (string) $value,
                ])->all(),
            );
        }

        return self::SUCCESS;
    }

    /**
     * Display health status for all supervised jobs.
     *
     * Shows a table of all jobs with their latest health check results. When
     * the unhealthy flag is set, filters to show only jobs that failed their
     * most recent health check.
     *
     * @param bool $unhealthyOnly Whether to show only unhealthy jobs
     *
     * @return int Command exit code: always returns self::SUCCESS (0)
     */
    private function displayAllJobsHealth(bool $unhealthyOnly): int
    {
        /** @var Collection<int, SupervisedJob> $jobs */
        $jobs = SupervisedJob::with(['healthChecks' => function ($query): void {
            $query->latest('checked_at')->limit(1);
        }])
            ->latest('started_at')
            ->get();

        if ($unhealthyOnly) {
            $jobs = $jobs->filter(function (SupervisedJob $job): bool {
                $latestCheck = $job->healthChecks->first();

                return $latestCheck !== null && !$latestCheck->is_healthy;
            });
        }

        $title = $unhealthyOnly ? 'Unhealthy Jobs' : 'All Jobs Health Status';
        $this->components->info($title);

        if ($jobs->isEmpty()) {
            $message = $unhealthyOnly
                ? 'No unhealthy jobs found.'
                : 'No supervised jobs found.';
            $this->components->warn($message);

            return self::SUCCESS;
        }

        $this->table(
            ['Job ID', 'Job Class', 'Status', 'Last Check', 'Health', 'Reason'],
            $jobs->map(function (SupervisedJob $job): array {
                $latestCheck = $job->healthChecks->first();

                if ($latestCheck === null) {
                    return [
                        $job->id,
                        $job->job_class,
                        $this->formatJobStatus($job->status->value),
                        '<fg=gray>Never checked</>',
                        '<fg=gray>Unknown</>',
                        'N/A',
                    ];
                }

                $healthStatus = $latestCheck->is_healthy
                    ? '<fg=green>Healthy</>'
                    : '<fg=red>Unhealthy</>';

                return [
                    $job->id,
                    $job->job_class,
                    $this->formatJobStatus($job->status->value),
                    $latestCheck->checked_at->format('Y-m-d H:i:s'),
                    $healthStatus,
                    $latestCheck->reason ?? 'N/A',
                ];
            })->all(),
        );

        $this->newLine();
        $this->components->info(sprintf('Total: %d job(s)', $jobs->count()));

        if ($unhealthyOnly) {
            $totalJobs = SupervisedJob::query()->count();
            $healthyJobs = $totalJobs - $jobs->count();
            $this->components->info(sprintf('Healthy jobs: %d', $healthyJobs));
        }

        return self::SUCCESS;
    }

    /**
     * Format job status with color coding.
     *
     * @param string $status The job status to format
     *
     * @return string Formatted status with color tags
     */
    private function formatJobStatus(string $status): string
    {
        return match ($status) {
            'running' => '<fg=green>Running</>',
            'completed' => '<fg=blue>Completed</>',
            'failed' => '<fg=red>Failed</>',
            'stalled' => '<fg=yellow>Stalled</>',
            default => $status,
        };
    }
}
