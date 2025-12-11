<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Console\Commands;

use Cline\Chaperone\Database\Models\CircuitBreaker;
use Cline\Chaperone\Database\Models\ResourceViolation;
use Cline\Chaperone\Database\Models\SupervisedJob;
use Cline\Chaperone\Enums\SupervisedJobStatus;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Sleep;

use const JSON_PRETTY_PRINT;

use function function_exists;
use function in_array;
use function json_encode;
use function sprintf;
use function system;

/**
 * Artisan command to monitor supervised jobs in real-time.
 *
 * Provides a comprehensive real-time dashboard displaying active supervised jobs,
 * stuck jobs, resource violations, and circuit breaker statuses. Supports multiple
 * output formats and auto-refresh for continuous monitoring.
 *
 * ```bash
 * # Show real-time dashboard (default table format, refreshes every 5 seconds)
 * php artisan chaperone:monitor
 *
 * # Refresh every 10 seconds
 * php artisan chaperone:monitor --refresh=10
 *
 * # Output in JSON format
 * php artisan chaperone:monitor --format=json
 *
 * # Use compact table format
 * php artisan chaperone:monitor --format=compact
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class MonitorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Supports two optional flags for controlling refresh rate and output format:
     * - refresh: Auto-refresh interval in seconds (default: 5)
     * - format: Output format (table, json, compact)
     *
     * @var string
     */
    protected $signature = 'chaperone:monitor
                            {--refresh=5 : Auto-refresh interval in seconds}
                            {--format=table : Output format (table, json, compact)}';

    /**
     * The console command description shown in artisan list output.
     *
     * @var string
     */
    protected $description = 'Display real-time dashboard of supervised jobs, stuck jobs, violations, and circuit breakers';

    /**
     * Execute the console command to display monitoring dashboard.
     *
     * Continuously displays the monitoring dashboard with auto-refresh based on the
     * configured interval. Routes to appropriate output format method based on the
     * format option. Press Ctrl+C to exit the monitoring loop.
     *
     * @return int Command exit code: always returns self::SUCCESS (0) as monitoring
     *             is an ongoing process that runs until interrupted
     */
    public function handle(): int
    {
        $refresh = (int) $this->option('refresh');
        $format = (string) $this->option('format');

        if (!in_array($format, ['table', 'json', 'compact'], true)) {
            $this->components->error('Invalid format. Use table, json, or compact.');

            return self::FAILURE;
        }

        if ($format === 'json') {
            // For JSON format, output once and exit
            $this->displayJson();

            return self::SUCCESS;
        }

        // For table/compact formats, loop with refresh
        while (true) {
            // Clear screen for refresh
            if (function_exists('system')) {
                /** @phpstan-ignore-next-line */
                system('clear');
            }

            $this->components->info(sprintf(
                'Chaperone Monitoring Dashboard (Press Ctrl+C to exit) - Last update: %s',
                Date::now()->format('Y-m-d H:i:s'),
            ));
            $this->newLine();

            if ($format === 'compact') {
                $this->displayCompact();
            } else {
                $this->displayTable();
            }

            // Sleep for refresh interval
            Sleep::usleep($refresh * 1_000_000);
        }
    }

    /**
     * Display monitoring dashboard in table format.
     *
     * Shows comprehensive information about active jobs, stuck jobs, resource
     * violations, and circuit breakers in separate tables with color-coded status.
     */
    private function displayTable(): void
    {
        $this->displayActiveJobs();
        $this->newLine();

        $this->displayStuckJobs();
        $this->newLine();

        $this->displayResourceViolations();
        $this->newLine();

        $this->displayCircuitBreakers();
    }

    /**
     * Display active supervised jobs.
     *
     * Shows all jobs currently in Running status with their ID, class name,
     * start time, last heartbeat, and status with color coding.
     */
    private function displayActiveJobs(): void
    {
        /** @var Collection<int, SupervisedJob> $activeJobs */
        $activeJobs = SupervisedJob::query()
            ->where('status', SupervisedJobStatus::Running)
            ->latest('started_at')
            ->get();

        $this->components->info('Active Supervised Jobs');

        if ($activeJobs->isEmpty()) {
            $this->components->warn('No active jobs.');

            return;
        }

        $this->table(
            ['ID', 'Job Class', 'Started', 'Last Heartbeat', 'Status'],
            $activeJobs->map(fn (SupervisedJob $job): array => [
                $job->id,
                $job->job_class,
                $job->started_at->format('Y-m-d H:i:s'),
                $job->last_heartbeat_at?->format('Y-m-d H:i:s') ?? '<fg=red>None</>',
                $this->formatStatus($job->status),
            ])->all(),
        );

        $this->components->info(sprintf('Total: %d active job(s)', $activeJobs->count()));
    }

    /**
     * Display stuck jobs.
     *
     * Shows jobs that have not sent heartbeats within the expected interval,
     * calculating how long they've been stuck based on heartbeat configuration.
     */
    private function displayStuckJobs(): void
    {
        $heartbeatTimeout = (int) Config::get('chaperone.heartbeat.timeout', 300);
        $cutoff = Date::now()->subSeconds($heartbeatTimeout);

        /** @var Collection<int, SupervisedJob> $stuckJobs */
        $stuckJobs = SupervisedJob::query()
            ->where('status', SupervisedJobStatus::Running)
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('last_heartbeat_at')
                    ->orWhere('last_heartbeat_at', '<', $cutoff);
            })
            ->latest('started_at')
            ->get();

        $this->components->info('Stuck Jobs');

        if ($stuckJobs->isEmpty()) {
            $this->components->warn('No stuck jobs found.');

            return;
        }

        $this->table(
            ['ID', 'Job Class', 'Stuck Duration', 'Last Heartbeat'],
            $stuckJobs->map(fn (SupervisedJob $job): array => [
                $job->id,
                $job->job_class,
                $this->formatDuration($job->last_heartbeat_at ?? $job->started_at),
                $job->last_heartbeat_at?->format('Y-m-d H:i:s') ?? '<fg=red>None</>',
            ])->all(),
        );

        $this->components->error(sprintf('Total: %d stuck job(s)', $stuckJobs->count()));
    }

    /**
     * Display resource violations.
     *
     * Shows recent resource violations with job ID, violation type, configured
     * limit, actual value that exceeded the limit, and when it was recorded.
     */
    private function displayResourceViolations(): void
    {
        /** @var Collection<int, ResourceViolation> $violations */
        $violations = ResourceViolation::with('supervisedJob')
            ->latest('recorded_at')
            ->limit(50)
            ->get();

        $this->components->info('Recent Resource Violations');

        if ($violations->isEmpty()) {
            $this->components->warn('No resource violations found.');

            return;
        }

        $this->table(
            ['Job ID', 'Type', 'Limit', 'Actual', 'Recorded At'],
            $violations->map(fn (ResourceViolation $violation): array => [
                $violation->supervised_job_id,
                $violation->violation_type->value,
                $violation->limit_value,
                sprintf('<fg=red>%s</>', $violation->actual_value),
                $violation->recorded_at->format('Y-m-d H:i:s'),
            ])->all(),
        );

        $this->components->info(sprintf('Showing last 50 violation(s) (Total in DB: %d)', $violations->count()));
    }

    /**
     * Display circuit breaker statuses.
     *
     * Shows all circuit breakers with their service name, current state, failure
     * count, last failure time, and when they were opened (if applicable).
     */
    private function displayCircuitBreakers(): void
    {
        /** @var Collection<int, CircuitBreaker> $circuitBreakers */
        $circuitBreakers = CircuitBreaker::query()
            ->latest('updated_at')
            ->get();

        $this->components->info('Circuit Breaker Status');

        if ($circuitBreakers->isEmpty()) {
            $this->components->warn('No circuit breakers found.');

            return;
        }

        $this->table(
            ['Service', 'State', 'Failures', 'Last Failure', 'Opened At'],
            $circuitBreakers->map(fn (CircuitBreaker $breaker): array => [
                $breaker->service_name,
                $this->formatCircuitState($breaker->state->value),
                $breaker->failure_count,
                $breaker->last_failure_at?->format('Y-m-d H:i:s') ?? 'N/A',
                $breaker->opened_at?->format('Y-m-d H:i:s') ?? 'N/A',
            ])->all(),
        );

        $this->components->info(sprintf('Total: %d circuit breaker(s)', $circuitBreakers->count()));
    }

    /**
     * Display monitoring dashboard in compact format.
     *
     * Shows summary statistics with minimal details for quick overview.
     */
    private function displayCompact(): void
    {
        $activeCount = SupervisedJob::query()->where('status', SupervisedJobStatus::Running)->count();

        $heartbeatTimeout = (int) Config::get('chaperone.heartbeat.timeout', 300);
        $cutoff = Date::now()->subSeconds($heartbeatTimeout);

        $stuckCount = SupervisedJob::query()
            ->where('status', SupervisedJobStatus::Running)
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('last_heartbeat_at')
                    ->orWhere('last_heartbeat_at', '<', $cutoff);
            })
            ->count();

        $violationCount = ResourceViolation::query()
            ->where('recorded_at', '>', Date::now()->subHour())
            ->count();

        $openCircuits = CircuitBreaker::query()->where('state', 'open')->count();

        $this->components->twoColumnDetail(
            'Active Jobs',
            sprintf('<fg=%s>%d</>', $activeCount > 0 ? 'green' : 'gray', $activeCount),
        );

        $this->components->twoColumnDetail(
            'Stuck Jobs',
            sprintf('<fg=%s>%d</>', $stuckCount > 0 ? 'red' : 'green', $stuckCount),
        );

        $this->components->twoColumnDetail(
            'Violations (Last Hour)',
            sprintf('<fg=%s>%d</>', $violationCount > 0 ? 'yellow' : 'green', $violationCount),
        );

        $this->components->twoColumnDetail(
            'Open Circuit Breakers',
            sprintf('<fg=%s>%d</>', $openCircuits > 0 ? 'red' : 'green', $openCircuits),
        );
    }

    /**
     * Display monitoring dashboard in JSON format.
     *
     * Outputs all monitoring data as formatted JSON for machine consumption
     * or integration with external monitoring systems.
     */
    private function displayJson(): void
    {
        $activeJobs = SupervisedJob::query()->where('status', SupervisedJobStatus::Running)
            ->latest('started_at')
            ->get();

        $heartbeatTimeout = (int) Config::get('chaperone.heartbeat.timeout', 300);
        $cutoff = Date::now()->subSeconds($heartbeatTimeout);

        $stuckJobs = SupervisedJob::query()
            ->where('status', SupervisedJobStatus::Running)
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('last_heartbeat_at')
                    ->orWhere('last_heartbeat_at', '<', $cutoff);
            })
            ->latest('started_at')
            ->get();

        $violations = ResourceViolation::with('supervisedJob')
            ->latest('recorded_at')
            ->limit(50)
            ->get();

        $circuitBreakers = CircuitBreaker::query()->latest('updated_at')->get();

        $data = [
            'timestamp' => Date::now()->toIso8601String(),
            'active_jobs' => $activeJobs->map(fn (SupervisedJob $job): array => [
                'id' => $job->id,
                'job_class' => $job->job_class,
                'started_at' => $job->started_at->toIso8601String(),
                'last_heartbeat_at' => $job->last_heartbeat_at?->toIso8601String(),
                'status' => $job->status->value,
            ])->all(),
            'stuck_jobs' => $stuckJobs->map(fn (SupervisedJob $job): array => [
                'id' => $job->id,
                'job_class' => $job->job_class,
                'stuck_duration_seconds' => Date::now()->diffInSeconds($job->last_heartbeat_at ?? $job->started_at),
                'last_heartbeat_at' => $job->last_heartbeat_at?->toIso8601String(),
            ])->all(),
            'resource_violations' => $violations->map(fn (ResourceViolation $violation): array => [
                'supervised_job_id' => $violation->supervised_job_id,
                'type' => $violation->violation_type->value,
                'limit' => $violation->limit_value,
                'actual' => $violation->actual_value,
                'recorded_at' => $violation->recorded_at->toIso8601String(),
            ])->all(),
            'circuit_breakers' => $circuitBreakers->map(fn (CircuitBreaker $breaker): array => [
                'service' => $breaker->service_name,
                'state' => $breaker->state->value,
                'failures' => $breaker->failure_count,
                'last_failure_at' => $breaker->last_failure_at?->toIso8601String(),
                'opened_at' => $breaker->opened_at?->toIso8601String(),
            ])->all(),
        ];

        $this->line(json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Format job status with color coding.
     *
     * @param SupervisedJobStatus $status The job status to format
     *
     * @return string Formatted status with color tags
     */
    private function formatStatus(SupervisedJobStatus $status): string
    {
        return match ($status) {
            SupervisedJobStatus::Running => '<fg=green>Running</>',
            SupervisedJobStatus::Completed => '<fg=blue>Completed</>',
            SupervisedJobStatus::Failed => '<fg=red>Failed</>',
            SupervisedJobStatus::Stalled => '<fg=yellow>Stalled</>',
        };
    }

    /**
     * Format circuit breaker state with color coding.
     *
     * @param string $state The circuit state to format
     *
     * @return string Formatted state with color tags
     */
    private function formatCircuitState(string $state): string
    {
        return match ($state) {
            'closed' => '<fg=green>Closed</>',
            'open' => '<fg=red>Open</>',
            'half_open' => '<fg=yellow>Half Open</>',
            default => $state,
        };
    }

    /**
     * Format duration since timestamp in human-readable format.
     *
     * @param Carbon $timestamp The timestamp to calculate duration from
     *
     * @return string Human-readable duration
     */
    private function formatDuration(Carbon $timestamp): string
    {
        $seconds = Date::now()->diffInSeconds($timestamp);

        if ($seconds < 60) {
            return sprintf('%d second(s)', $seconds);
        }

        if ($seconds < 3_600) {
            return sprintf('%d minute(s)', (int) ($seconds / 60));
        }

        if ($seconds < 86_400) {
            return sprintf('%d hour(s)', (int) ($seconds / 3_600));
        }

        return sprintf('%d day(s)', (int) ($seconds / 86_400));
    }
}
