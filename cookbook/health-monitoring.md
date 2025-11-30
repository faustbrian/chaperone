# Health Monitoring

Chaperone provides comprehensive health monitoring for supervised jobs, detecting unhealthy states and enabling automated recovery. This guide covers health check mechanisms, status detection, and recovery strategies.

## Health Status Levels

Jobs can be in one of three health states:

- **Healthy** - Job is executing normally, sending heartbeats, within resource limits
- **Degraded** - Job is running but experiencing issues (slow heartbeats, approaching limits)
- **Unhealthy** - Job has failed, stopped sending heartbeats, or exceeded limits

## Health Check Mechanisms

### Heartbeat Monitoring

The primary health indicator is heartbeat regularity:

```php
class MonitoredJob implements ShouldQueue, Supervised
{
    public int $heartbeatInterval = 60; // Expected every 60 seconds

    public function handle(): void
    {
        $records = $this->getRecords();

        foreach ($records as $index => $record) {
            $this->processRecord($record);

            // Send regular heartbeats
            if ($index % 50 === 0) {
                $this->heartbeat([
                    'processed' => $index,
                    'health' => 'good',
                ]);
            }
        }
    }
}
```

### Missed Heartbeat Detection

Chaperone detects when heartbeats are missed:

```php
use Cline\Chaperone\Events\HeartbeatMissed;
use Illuminate\Support\Facades\Event;

Event::listen(HeartbeatMissed::class, function ($event) {
    Log::warning('Heartbeat missed', [
        'supervision_id' => $event->supervisionId,
        'last_heartbeat' => $event->lastHeartbeatAt,
        'expected_interval' => $event->expectedInterval,
    ]);

    // Alert operations team
    Alert::send("Job {$event->supervisionId} missed heartbeat");

    // Attempt recovery
    $this->attemptRecovery($event->supervisionId);
});
```

### Health Check Metadata

Include health indicators in heartbeats:

```php
public function handle(): void
{
    foreach ($records as $record) {
        $this->processRecord($record);

        $this->heartbeat([
            'health_indicators' => [
                'error_rate' => $this->calculateErrorRate(),
                'processing_speed' => $this->calculateSpeed(),
                'queue_size' => $this->getRemainingCount(),
                'memory_trend' => $this->getMemoryTrend(),
            ],
        ]);
    }
}

private function calculateErrorRate(): float
{
    return $this->errors / max(1, $this->processed);
}

private function getMemoryTrend(): string
{
    $current = memory_get_usage(true);

    if ($current > $this->previousMemory * 1.1) {
        return 'increasing';
    } elseif ($current < $this->previousMemory * 0.9) {
        return 'decreasing';
    }

    return 'stable';
}
```

## Automated Health Checks

### Periodic Health Checks

Implement scheduled health verification:

```php
use Cline\Chaperone\Database\Models\SupervisedJob;
use Illuminate\Console\Scheduling\Schedule;

// In App\Console\Kernel
protected function schedule(Schedule $schedule)
{
    $schedule->call(function () {
        $jobs = SupervisedJob::running()->get();

        foreach ($jobs as $job) {
            $this->performHealthCheck($job);
        }
    })->everyMinute();
}

private function performHealthCheck(SupervisedJob $job): void
{
    $isHealthy = true;
    $issues = [];

    // Check heartbeat freshness
    $lastHeartbeat = $job->heartbeats()->latest()->first();
    if ($lastHeartbeat && $lastHeartbeat->created_at->diffInSeconds(now()) > 300) {
        $isHealthy = false;
        $issues[] = 'stale_heartbeat';
    }

    // Check resource usage
    if ($job->memory_usage > $job->memoryLimit * 1024 * 1024 * 0.9) {
        $isHealthy = false;
        $issues[] = 'high_memory';
    }

    if ($job->cpu_usage > 90) {
        $isHealthy = false;
        $issues[] = 'high_cpu';
    }

    // Record health check result
    $job->healthChecks()->create([
        'status' => $isHealthy ? 'healthy' : 'unhealthy',
        'metadata' => ['issues' => $issues],
    ]);

    if (!$isHealthy) {
        event(new HealthStatusChanged($job->job_id, 'unhealthy', $issues));
    }
}
```

### Custom Health Checks

Implement job-specific health logic:

```php
class CustomHealthCheckJob implements ShouldQueue, Supervised
{
    private int $errorCount = 0;
    private int $successCount = 0;

    public function handle(): void
    {
        foreach ($records as $record) {
            try {
                $this->processRecord($record);
                $this->successCount++;
            } catch (\Exception $e) {
                $this->errorCount++;
            }

            // Custom health check
            if ($this->shouldReportHealth()) {
                $this->reportHealth();
            }
        }
    }

    private function shouldReportHealth(): bool
    {
        $total = $this->errorCount + $this->successCount;

        // Report every 100 records
        return $total > 0 && $total % 100 === 0;
    }

    private function reportHealth(): void
    {
        $total = $this->errorCount + $this->successCount;
        $errorRate = $this->errorCount / $total;

        $status = match (true) {
            $errorRate < 0.01 => 'healthy',
            $errorRate < 0.05 => 'degraded',
            default => 'unhealthy',
        };

        $this->heartbeat([
            'health_status' => $status,
            'error_rate' => $errorRate,
            'errors' => $this->errorCount,
            'successes' => $this->successCount,
        ]);

        if ($status === 'unhealthy') {
            throw new UnhealthyJobException("Error rate too high: {$errorRate}");
        }
    }
}
```

## Health Status Detection

### Query Health Status

```php
use Cline\Chaperone\Database\Models\SupervisedJob;

// Get healthy jobs
$healthy = SupervisedJob::healthy()->get();

// Get degraded jobs
$degraded = SupervisedJob::degraded()->get();

// Get unhealthy jobs
$unhealthy = SupervisedJob::unhealthy()->get();

// Get jobs by specific health status
$job = SupervisedJob::where('job_id', $supervisionId)->first();

if ($job->isHealthy()) {
    echo "Job is healthy";
} elseif ($job->isDegraded()) {
    echo "Job is degraded";
} elseif ($job->isUnhealthy()) {
    echo "Job is unhealthy";
}
```

### Health Check History

```php
use Cline\Chaperone\Database\Models\JobHealthCheck;

$job = SupervisedJob::where('job_id', $supervisionId)->first();

// Get latest health check
$latestCheck = $job->healthChecks()->latest()->first();

// Get health check history
$history = $job->healthChecks()
    ->latest()
    ->take(100)
    ->get();

// Analyze health trends
$recentChecks = $job->healthChecks()
    ->where('created_at', '>', now()->subHour())
    ->get();

$healthyCount = $recentChecks->where('status', 'healthy')->count();
$unhealthyCount = $recentChecks->where('status', 'unhealthy')->count();

$healthPercentage = $healthyCount / $recentChecks->count() * 100;
```

## Recovery Strategies

### Automatic Restart

Restart unhealthy jobs automatically:

```php
use Cline\Chaperone\Events\HealthStatusChanged;

Event::listen(HealthStatusChanged::class, function ($event) {
    if ($event->status === 'unhealthy') {
        Log::warning("Job unhealthy, attempting restart", [
            'supervision_id' => $event->supervisionId,
            'issues' => $event->issues,
        ]);

        $job = SupervisedJob::where('job_id', $event->supervisionId)->first();

        // Mark as failed to trigger retry
        $job->update(['failed_at' => now()]);

        // Dispatch new instance
        $jobClass = $job->job_class;
        dispatch(new $jobClass(...$job->payload));
    }
});
```

### Graceful Degradation

Reduce workload when degraded:

```php
class AdaptiveJob implements ShouldQueue, Supervised
{
    private int $batchSize = 100;

    public function handle(): void
    {
        $records = $this->getRecords();

        foreach ($records->chunk($this->batchSize) as $chunk) {
            $this->processChunk($chunk);

            // Check health after each chunk
            if ($this->isDegraded()) {
                $this->reduceBatchSize();
            } elseif ($this->isHealthy()) {
                $this->increaseBatchSize();
            }
        }
    }

    private function isDegraded(): bool
    {
        $memoryUsage = memory_get_usage(true) / 1024 / 1024;
        $cpuLoad = sys_getloadavg()[0];

        return $memoryUsage > $this->memoryLimit * 0.8
            || $cpuLoad > $this->cpuLimit * 0.8;
    }

    private function reduceBatchSize(): void
    {
        $this->batchSize = max(10, (int) ($this->batchSize * 0.5));

        $this->heartbeat([
            'health_action' => 'reduced_batch_size',
            'new_batch_size' => $this->batchSize,
        ]);

        // Give system time to recover
        sleep(5);
    }

    private function increaseBatchSize(): void
    {
        $this->batchSize = min(1000, (int) ($this->batchSize * 1.2));

        $this->heartbeat([
            'health_action' => 'increased_batch_size',
            'new_batch_size' => $this->batchSize,
        ]);
    }
}
```

### Circuit Breaker Integration

Combine health monitoring with circuit breakers:

```php
class HealthAwareJob implements ShouldQueue, Supervised
{
    public function handle(): void
    {
        foreach ($records as $record) {
            // Use circuit breaker for external calls
            $result = CircuitBreaker::for('external-api')
                ->execute(
                    fn() => $this->callExternalApi($record),
                    fallback: fn() => $this->useFallback($record)
                );

            // Track health based on circuit state
            if (CircuitBreaker::isOpen('external-api')) {
                $this->heartbeat([
                    'health_status' => 'degraded',
                    'reason' => 'circuit_open',
                ]);
            }

            $this->processResult($result);
        }
    }
}
```

### Self-Healing Jobs

Implement self-healing mechanisms:

```php
class SelfHealingJob implements ShouldQueue, Supervised
{
    private int $consecutiveErrors = 0;
    private const MAX_ERRORS = 10;

    public function handle(): void
    {
        foreach ($records as $record) {
            try {
                $this->processRecord($record);
                $this->consecutiveErrors = 0; // Reset on success
            } catch (\Exception $e) {
                $this->consecutiveErrors++;

                $this->heartbeat([
                    'health_status' => 'degraded',
                    'consecutive_errors' => $this->consecutiveErrors,
                ]);

                if ($this->consecutiveErrors >= self::MAX_ERRORS) {
                    $this->attemptSelfHeal();
                }
            }
        }
    }

    private function attemptSelfHeal(): void
    {
        Log::info('Attempting self-heal');

        // Try recovery strategies
        $this->clearCaches();
        $this->reconnectServices();
        $this->reduceWorkload();

        // Reset error counter
        $this->consecutiveErrors = 0;

        $this->heartbeat([
            'health_action' => 'self_heal_attempted',
        ]);
    }

    private function clearCaches(): void
    {
        Cache::flush();
    }

    private function reconnectServices(): void
    {
        DB::reconnect();
    }

    private function reduceWorkload(): void
    {
        // Process in smaller batches
        sleep(10); // Back off
    }
}
```

## Health Monitoring Dashboard

### Real-Time Health Status

Create a health monitoring dashboard:

```php
class HealthDashboardController extends Controller
{
    public function index()
    {
        $jobs = SupervisedJob::running()->get();

        $stats = [
            'total' => $jobs->count(),
            'healthy' => $jobs->filter->isHealthy()->count(),
            'degraded' => $jobs->filter->isDegraded()->count(),
            'unhealthy' => $jobs->filter->isUnhealthy()->count(),
        ];

        return view('health.dashboard', [
            'jobs' => $jobs,
            'stats' => $stats,
        ]);
    }

    public function show($id)
    {
        $job = SupervisedJob::findOrFail($id);

        $healthChecks = $job->healthChecks()
            ->latest()
            ->take(100)
            ->get();

        $heartbeats = $job->heartbeats()
            ->latest()
            ->take(50)
            ->get();

        return view('health.show', [
            'job' => $job,
            'health_checks' => $healthChecks,
            'heartbeats' => $heartbeats,
            'health_trend' => $this->calculateHealthTrend($healthChecks),
        ]);
    }

    private function calculateHealthTrend($checks): array
    {
        $trend = [];

        foreach ($checks as $check) {
            $trend[] = [
                'timestamp' => $check->created_at,
                'status' => $check->status,
                'issues' => $check->metadata['issues'] ?? [],
            ];
        }

        return $trend;
    }
}
```

### Health Metrics

Track health metrics over time:

```php
use Cline\Chaperone\Events\HealthStatusChanged;

Event::listen(HealthStatusChanged::class, function ($event) {
    Metrics::gauge('job.health.status', [
        'supervision_id' => $event->supervisionId,
        'status' => $event->status,
    ]);

    if ($event->status === 'unhealthy') {
        Metrics::increment('job.health.unhealthy_count', [
            'issues' => implode(',', $event->issues),
        ]);
    }
});
```

## Alerting Configuration

### Alert on Health Changes

```php
use Cline\Chaperone\Events\HealthStatusChanged;

Event::listen(HealthStatusChanged::class, function ($event) {
    if ($event->status === 'unhealthy') {
        $job = SupervisedJob::where('job_id', $event->supervisionId)->first();

        Notification::send(
            User::admins(),
            new JobUnhealthyNotification($job, $event->issues)
        );
    }
});
```

### Slack Integration

```php
use Cline\Chaperone\Events\HealthStatusChanged;
use Illuminate\Support\Facades\Http;

Event::listen(HealthStatusChanged::class, function ($event) {
    if ($event->status === 'unhealthy') {
        $job = SupervisedJob::where('job_id', $event->supervisionId)->first();

        Http::post(config('chaperone.alerting.slack_webhook_url'), [
            'text' => "Job unhealthy: {$job->job_class}",
            'attachments' => [
                [
                    'color' => 'danger',
                    'fields' => [
                        ['title' => 'Supervision ID', 'value' => $job->job_id],
                        ['title' => 'Issues', 'value' => implode(', ', $event->issues)],
                        ['title' => 'Started', 'value' => $job->started_at->diffForHumans()],
                    ],
                ],
            ],
        ]);
    }
});
```

### Email Alerts

```php
use Cline\Chaperone\Events\HealthStatusChanged;
use Illuminate\Support\Facades\Mail;

Event::listen(HealthStatusChanged::class, function ($event) {
    if ($event->status === 'unhealthy') {
        $recipients = config('chaperone.alerting.recipients');

        Mail::to($recipients)->send(
            new JobHealthAlert($event->supervisionId, $event->issues)
        );
    }
});
```

## Best Practices

### 1. Set Appropriate Heartbeat Intervals

```php
// Fast-changing jobs - frequent heartbeats
class RealtimeProcessor implements ShouldQueue, Supervised
{
    public int $heartbeatInterval = 10; // Every 10 seconds
}

// Stable long-running jobs - less frequent
class DataMigration implements ShouldQueue, Supervised
{
    public int $heartbeatInterval = 300; // Every 5 minutes
}
```

### 2. Include Rich Health Metadata

```php
$this->heartbeat([
    'health_status' => 'healthy',
    'metrics' => [
        'error_rate' => $this->calculateErrorRate(),
        'processing_speed' => $this->calculateSpeed(),
        'memory_mb' => memory_get_usage(true) / 1024 / 1024,
        'cpu_percent' => sys_getloadavg()[0],
    ],
    'indicators' => [
        'external_api_latency' => $this->getApiLatency(),
        'database_connection' => $this->checkDatabaseHealth(),
        'queue_backlog' => $this->getQueueSize(),
    ],
]);
```

### 3. Implement Progressive Degradation

```php
public function handle(): void
{
    $workload = 100; // Start with full capacity

    foreach ($batches as $batch) {
        // Adjust workload based on health
        if ($this->isUnhealthy()) {
            $workload = 10; // Minimal processing
        } elseif ($this->isDegraded()) {
            $workload = 50; // Half capacity
        } else {
            $workload = min(100, $workload + 10); // Gradually increase
        }

        $this->processBatch($batch, $workload);
    }
}
```

### 4. Monitor Health Trends

```php
class HealthTrendAnalyzer
{
    public function analyze(SupervisedJob $job): array
    {
        $checks = $job->healthChecks()
            ->where('created_at', '>', now()->subHour())
            ->get();

        $healthyCount = $checks->where('status', 'healthy')->count();
        $degradedCount = $checks->where('status', 'degraded')->count();
        $unhealthyCount = $checks->where('status', 'unhealthy')->count();

        return [
            'trend' => $this->calculateTrend($checks),
            'health_percentage' => $healthyCount / max(1, $checks->count()) * 100,
            'stability' => $this->calculateStability($checks),
        ];
    }

    private function calculateTrend($checks): string
    {
        // Implement trend analysis logic
        return 'improving'; // or 'declining', 'stable'
    }
}
```

### 5. Test Health Monitoring

```php
use Tests\TestCase;
use App\Jobs\MonitoredJob;
use Cline\Chaperone\Database\Models\SupervisedJob;
use Cline\Chaperone\Events\HealthStatusChanged;
use Illuminate\Support\Facades\Event;

class HealthMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_reports_healthy_status(): void
    {
        $job = new MonitoredJob();
        $job->handle();

        $supervisedJob = SupervisedJob::where('job_id', $job->getSupervisionId())->first();

        $this->assertTrue($supervisedJob->isHealthy());
    }

    public function test_unhealthy_job_triggers_event(): void
    {
        Event::fake([HealthStatusChanged::class]);

        $job = new UnhealthyJob();
        $job->handle();

        Event::assertDispatched(HealthStatusChanged::class, function ($event) {
            return $event->status === 'unhealthy';
        });
    }

    public function test_health_checks_are_recorded(): void
    {
        $job = new MonitoredJob();
        $job->handle();

        $supervisedJob = SupervisedJob::where('job_id', $job->getSupervisionId())->first();
        $healthChecks = $supervisedJob->healthChecks;

        $this->assertTrue($healthChecks->isNotEmpty());
    }
}
```

## Next Steps

- **[Events](events.md)** - Complete event reference and listeners
- **[Artisan Commands](artisan-commands.md)** - Available commands and usage
- **[Advanced Usage](advanced-usage.md)** - Worker pools and deployment coordination
- **[Configuration](configuration.md)** - Complete configuration reference
- **[Basic Supervision](basic-supervision.md)** - Core supervision features
