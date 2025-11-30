# Queue Management

This guide covers Chaperone's queue filtering system, which allows you to control which queues are supervised, configure automatic supervision via middleware, and implement sophisticated queue architectures for production systems.

## Queue Filtering Concepts

Chaperone provides flexible queue filtering through two complementary mechanisms:

- **Supervised Queues (Allowlist)** - Explicitly specify which queues to supervise
- **Excluded Queues (Denylist)** - Explicitly specify which queues to never supervise

### Filtering Logic

The queue filter applies a precedence-based decision model:

1. **Excluded queues always take precedence** - If a queue is in the excluded list, it will never be supervised
2. **Supervised queues define inclusion** - If the supervised list is populated, only those queues are supervised
3. **Empty supervised list means all queues** - When no specific queues are configured, all queues are supervised (except excluded ones)

```php
use Cline\Chaperone\Queue\QueueFilter;

$filter = app(QueueFilter::class);

// Check if a queue should be supervised
if ($filter->shouldSupervise('high-priority')) {
    // Queue will be supervised
}

// Get configured supervised queues
$supervised = $filter->getSupervisedQueues();

// Get configured excluded queues
$excluded = $filter->getExcludedQueues();
```

## Configuration

Configure queue supervision in `config/chaperone.php`:

```php
return [
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
```

### Environment Configuration

Configure queues via environment variables:

```env
# Supervise specific queues only
CHAPERONE_SUPERVISED_QUEUES=default,high,critical

# Exclude specific queues from supervision
CHAPERONE_EXCLUDED_QUEUES=notifications,emails

# Use specific queue connection
CHAPERONE_QUEUE_CONNECTION=redis
```

## Supervision Patterns

### Pattern 1: Supervise All Queues

The default behavior - supervise everything:

```env
# Leave both empty
CHAPERONE_SUPERVISED_QUEUES=
CHAPERONE_EXCLUDED_QUEUES=
```

```php
// All queues will be supervised
$filter->shouldSupervise('default');    // true
$filter->shouldSupervise('high');       // true
$filter->shouldSupervise('low');        // true
$filter->shouldSupervise('batch');      // true
```

### Pattern 2: Supervise All Except Specific Queues

Exclude lightweight or non-critical queues:

```env
# Supervise all except notifications and emails
CHAPERONE_SUPERVISED_QUEUES=
CHAPERONE_EXCLUDED_QUEUES=notifications,emails,logs
```

```php
$filter->shouldSupervise('default');        // true
$filter->shouldSupervise('high');           // true
$filter->shouldSupervise('notifications');  // false - excluded
$filter->shouldSupervise('emails');         // false - excluded
$filter->shouldSupervise('logs');           // false - excluded
```

Use this pattern when:
- Most queues require supervision
- Only a few lightweight queues don't need monitoring
- You want to reduce overhead for high-volume, low-risk queues

### Pattern 3: Supervise Specific Queues Only

Allowlist only critical queues:

```env
# Only supervise high-priority and critical queues
CHAPERONE_SUPERVISED_QUEUES=high,critical,financial
CHAPERONE_EXCLUDED_QUEUES=
```

```php
$filter->shouldSupervise('high');           // true - in supervised list
$filter->shouldSupervise('critical');       // true - in supervised list
$filter->shouldSupervise('financial');      // true - in supervised list
$filter->shouldSupervise('default');        // false - not in supervised list
$filter->shouldSupervise('notifications');  // false - not in supervised list
```

Use this pattern when:
- Only specific queues process mission-critical jobs
- You want explicit control over what gets supervised
- Resource usage needs to be carefully managed

### Pattern 4: Combined Allowlist and Denylist

Exclude queues even if they're in the supervised list:

```env
# Supervise high and critical, but never emails
CHAPERONE_SUPERVISED_QUEUES=high,critical,emails
CHAPERONE_EXCLUDED_QUEUES=emails
```

```php
$filter->shouldSupervise('high');       // true - in supervised list
$filter->shouldSupervise('critical');   // true - in supervised list
$filter->shouldSupervise('emails');     // false - excluded takes precedence
$filter->shouldSupervise('default');    // false - not in supervised list
```

Use this pattern when:
- You need fine-grained control over queue supervision
- Certain queues must never be supervised regardless of configuration
- Testing or migration scenarios require temporary exclusions

## Automatic Supervision via Middleware

Chaperone includes queue middleware that automatically starts supervision for jobs on configured queues.

### Registering the Middleware

Add the supervision middleware globally in `app/Providers/AppServiceProvider.php`:

```php
use Cline\Chaperone\Queue\SupervisionMiddleware;
use Illuminate\Support\Facades\Queue;

public function boot(): void
{
    Queue::before(function (JobProcessing $event) {
        // Middleware is automatically applied via service provider
    });
}
```

The middleware is automatically registered by Chaperone's service provider. It checks each job's queue and starts supervision if the queue filter allows it.

### How the Middleware Works

```php
namespace Cline\Chaperone\Queue;

final class SupervisionMiddleware
{
    public function __construct(
        private readonly QueueFilter $queueFilter,
    ) {}

    public function handle(mixed $job, callable $next): mixed
    {
        // Get the queue name from the job
        $queueName = $this->getQueueName($job);

        // Check if this queue should be supervised
        if ($this->queueFilter->shouldSupervise($queueName)) {
            $this->startSupervision($job, $queueName);
        } else {
            Log::debug('Skipping supervision for job', [
                'job_class' => get_class($job),
                'queue' => $queueName,
                'reason' => 'Queue not configured for supervision',
            ]);
        }

        return $next($job);
    }
}
```

The middleware:
1. Extracts the queue name from the job
2. Checks if the queue should be supervised using `QueueFilter`
3. Starts supervision if allowed
4. Logs a debug message if supervision is skipped
5. Never blocks job execution - supervision errors are caught and logged

### Per-Job Middleware

Apply supervision middleware to specific jobs:

```php
use Cline\Chaperone\Queue\SupervisionMiddleware;

class ProcessPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function middleware(): array
    {
        return [
            new SupervisionMiddleware(app(QueueFilter::class)),
        ];
    }

    public function handle(): void
    {
        // Process payment
    }
}
```

## Manual Supervision Control

For jobs that need explicit supervision control regardless of queue configuration:

### Force Supervision

Always supervise a job, even if its queue is excluded:

```php
use Cline\Chaperone\Contracts\Supervised;
use Cline\Chaperone\Supervisors\JobSupervisor;
use Illuminate\Support\Str;

class CriticalJob implements ShouldQueue, Supervised
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $supervisionId;

    public function __construct()
    {
        $this->supervisionId = (string) Str::uuid();
    }

    public function handle(JobSupervisor $supervisor): void
    {
        // Force supervision regardless of queue configuration
        $supervisor->supervise(static::class);

        // Job logic
        $this->processData();
    }

    public function heartbeat(array $metadata = []): void {}
    public function reportProgress(int $current, int $total, array $metadata = []): void {}
    public function getSupervisionId(): string { return $this->supervisionId; }
}
```

### Disable Supervision

Skip supervision for specific job instances:

```php
class OptionalSupervisionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private bool $enableSupervision = true,
    ) {}

    public function middleware(): array
    {
        if (! $this->enableSupervision) {
            return []; // No supervision middleware
        }

        return [
            new SupervisionMiddleware(app(QueueFilter::class)),
        ];
    }

    public function handle(): void
    {
        // Job logic
    }
}

// Dispatch with supervision
OptionalSupervisionJob::dispatch(enableSupervision: true);

// Dispatch without supervision
OptionalSupervisionJob::dispatch(enableSupervision: false);
```

## Multi-Tenant Queue Strategies

### Tenant-Specific Queues

Supervise queues on a per-tenant basis:

```php
use Cline\Chaperone\Queue\QueueFilter;

class TenantJobDispatcher
{
    public function __construct(
        private QueueFilter $filter,
    ) {}

    public function dispatch(ShouldQueue $job, Tenant $tenant): void
    {
        $queueName = "tenant-{$tenant->id}";

        // Only dispatch if queue is supervised
        if ($this->filter->shouldSupervise($queueName)) {
            $job->onQueue($queueName);
            dispatch($job);
        } else {
            // Handle unsupervised tenant queues differently
            $job->onQueue('default');
            dispatch($job);
        }
    }
}
```

Configuration for tenant queues:

```env
# Supervise only premium tenant queues
CHAPERONE_SUPERVISED_QUEUES=tenant-1,tenant-5,tenant-12
CHAPERONE_EXCLUDED_QUEUES=tenant-trial-*
```

### Tenant Priority Queues

Different supervision for different tenant tiers:

```php
class TenantAwareJob implements ShouldQueue, Supervised
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $supervisionId;

    public function __construct(
        private Tenant $tenant,
    ) {
        $this->supervisionId = (string) Str::uuid();

        // Set queue based on tenant tier
        $this->onQueue($this->getTenantQueue());
    }

    private function getTenantQueue(): string
    {
        return match ($this->tenant->tier) {
            'enterprise' => 'tenant-enterprise',
            'premium' => 'tenant-premium',
            'standard' => 'tenant-standard',
            default => 'tenant-basic',
        };
    }

    public function handle(): void
    {
        // Process job with tenant context
    }

    public function heartbeat(array $metadata = []): void {}
    public function reportProgress(int $current, int $total, array $metadata = []): void {}
    public function getSupervisionId(): string { return $this->supervisionId; }
}
```

Configuration:

```env
# Only supervise enterprise and premium tenants
CHAPERONE_SUPERVISED_QUEUES=tenant-enterprise,tenant-premium
CHAPERONE_EXCLUDED_QUEUES=tenant-basic
```

## Priority Queue Handling

### Dedicated Priority Queues

Configure supervision for different priority levels:

```env
CHAPERONE_SUPERVISED_QUEUES=critical,high,medium
CHAPERONE_EXCLUDED_QUEUES=low,background
```

```php
class PriorityJobDispatcher
{
    public function dispatch(ShouldQueue $job, string $priority): void
    {
        $queueName = match ($priority) {
            'critical' => 'critical',   // Always supervised
            'high' => 'high',           // Always supervised
            'medium' => 'medium',       // Always supervised
            'low' => 'low',             // Not supervised
            'background' => 'background', // Not supervised
            default => 'default',
        };

        $job->onQueue($queueName);
        dispatch($job);
    }
}

// Usage
$dispatcher = app(PriorityJobDispatcher::class);
$dispatcher->dispatch(new ProcessPayment(), 'critical');
$dispatcher->dispatch(new SendEmail(), 'low');
```

### Dynamic Priority Assignment

Adjust queue priority based on job characteristics:

```php
class SmartPriorityJob implements ShouldQueue, Supervised
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $supervisionId;

    public function __construct(
        private array $data,
    ) {
        $this->supervisionId = (string) Str::uuid();
        $this->assignPriority();
    }

    private function assignPriority(): void
    {
        $priority = 'default';

        // Critical financial transactions
        if (isset($this->data['type']) && $this->data['type'] === 'payment') {
            $priority = 'critical';
        }
        // Large data processing
        elseif (isset($this->data['size']) && $this->data['size'] > 10000) {
            $priority = 'high';
        }
        // Bulk operations
        elseif (isset($this->data['batch']) && $this->data['batch'] === true) {
            $priority = 'medium';
        }

        $this->onQueue($priority);
    }

    public function handle(): void
    {
        // Process based on data
    }

    public function heartbeat(array $metadata = []): void {}
    public function reportProgress(int $current, int $total, array $metadata = []): void {}
    public function getSupervisionId(): string { return $this->supervisionId; }
}
```

## CLI Tools for Queue Visibility

### Show Supervised Queues Command

View current queue supervision configuration:

```bash
php artisan chaperone:queues
```

Output:

```
Supervised Queues Configuration

Mode: Allowlist

Supervised Queues:
  • default
  • high
  • critical

Excluded Queues:
  • notifications
  • emails

Example usage:
  $filter->shouldSupervise("default") => true
```

### JSON Output Format

Get machine-readable queue configuration:

```bash
php artisan chaperone:queues --format=json
```

Output:

```json
{
    "supervised": [
        "default",
        "high",
        "critical"
    ],
    "excluded": [
        "notifications",
        "emails"
    ],
    "mode": "allowlist"
}
```

### List Format

Get a simple list of supervised queues:

```bash
php artisan chaperone:queues --format=list
```

Output:

```
default
high
critical
```

### Integration with CI/CD

Validate queue configuration in deployment pipelines:

```bash
#!/bin/bash

# Get supervised queues
SUPERVISED=$(php artisan chaperone:queues --format=json | jq -r '.supervised[]')

# Verify critical queue is supervised
if ! echo "$SUPERVISED" | grep -q "critical"; then
    echo "ERROR: Critical queue is not supervised!"
    exit 1
fi

echo "Queue configuration validated successfully"
```

## Production Queue Architecture Patterns

### Pattern 1: Tiered Supervision Architecture

Supervise queues based on business impact:

```env
# Tier 1: Mission-critical (full supervision)
CHAPERONE_SUPERVISED_QUEUES=payments,orders,financial

# Tier 2: Important but not critical (excluded)
CHAPERONE_EXCLUDED_QUEUES=notifications,emails,reports

# Tier 3: Background jobs (excluded)
# CHAPERONE_EXCLUDED_QUEUES also includes: cleanup,analytics,cache
```

Worker configuration:

```bash
# Critical queue - high priority, supervised
php artisan queue:work redis --queue=payments,orders,financial --tries=3 --timeout=3600

# Standard queues - supervised
php artisan queue:work redis --queue=default --tries=3 --timeout=1800

# Background queues - not supervised
php artisan queue:work redis --queue=notifications,emails,reports --tries=1 --timeout=300
```

### Pattern 2: Resource-Based Queue Segregation

Separate queues by resource requirements:

```php
// Heavy computation - supervised with high resource limits
class HeavyComputationJob implements ShouldQueue, Supervised
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $supervisionId;

    public int $timeout = 7200;        // 2 hours
    public int $memoryLimit = 2048;    // 2GB
    public int $cpuLimit = 90;         // 90%

    public function __construct()
    {
        $this->supervisionId = (string) Str::uuid();
        $this->onQueue('heavy-compute');
    }

    public function heartbeat(array $metadata = []): void {}
    public function reportProgress(int $current, int $total, array $metadata = []): void {}
    public function getSupervisionId(): string { return $this->supervisionId; }
}

// Quick tasks - not supervised
class QuickNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;  // 30 seconds

    public function __construct()
    {
        $this->onQueue('quick');
    }
}
```

Configuration:

```env
# Supervise resource-intensive queues
CHAPERONE_SUPERVISED_QUEUES=heavy-compute,batch-processing,data-import

# Exclude quick, lightweight queues
CHAPERONE_EXCLUDED_QUEUES=quick,notifications,cache-warming
```

### Pattern 3: Environment-Based Supervision

Different supervision strategies per environment:

```env
# .env.production
CHAPERONE_SUPERVISED_QUEUES=
CHAPERONE_EXCLUDED_QUEUES=dev-testing,debug

# .env.staging
CHAPERONE_SUPERVISED_QUEUES=payments,orders
CHAPERONE_EXCLUDED_QUEUES=

# .env.development
CHAPERONE_SUPERVISED_QUEUES=
CHAPERONE_EXCLUDED_QUEUES=
```

Environment-aware job configuration:

```php
class EnvironmentAwareJob implements ShouldQueue
{
    public function __construct()
    {
        // Use different queues per environment
        $queue = match (app()->environment()) {
            'production' => 'production-critical',
            'staging' => 'staging-test',
            'development' => 'dev-testing',
            default => 'default',
        };

        $this->onQueue($queue);
    }
}
```

### Pattern 4: Rate-Limited Queue Supervision

Supervise queues that interact with rate-limited APIs:

```php
class RateLimitedApiJob implements ShouldQueue, Supervised
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use RateLimited; // Laravel's rate limiting

    private string $supervisionId;

    public function __construct()
    {
        $this->supervisionId = (string) Str::uuid();
        $this->onQueue('api-calls');
    }

    public function middleware(): array
    {
        return [
            new RateLimited('api-calls'),
            new SupervisionMiddleware(app(QueueFilter::class)),
        ];
    }

    public function handle(): void
    {
        $this->heartbeat(['status' => 'calling_api']);

        // Make API call with circuit breaker protection
        CircuitBreaker::for('external-api')
            ->execute(function () {
                Http::post('https://api.example.com/endpoint', $this->data);
            });

        $this->heartbeat(['status' => 'completed']);
    }

    public function heartbeat(array $metadata = []): void {}
    public function reportProgress(int $current, int $total, array $metadata = []): void {}
    public function getSupervisionId(): string { return $this->supervisionId; }
}
```

Configuration:

```env
# Supervise API interaction queues
CHAPERONE_SUPERVISED_QUEUES=api-calls,webhooks,third-party

# Use circuit breaker for all supervised API queues
CHAPERONE_CIRCUIT_BREAKER_ENABLED=true
CHAPERONE_CIRCUIT_BREAKER_THRESHOLD=5
CHAPERONE_CIRCUIT_BREAKER_TIMEOUT=300
```

### Pattern 5: Hybrid Supervision Strategy

Combine automatic and manual supervision:

```php
class HybridSupervisionJob implements ShouldQueue, Supervised
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $supervisionId;

    public function __construct(
        private bool $forceSupervision = false,
    ) {
        $this->supervisionId = (string) Str::uuid();

        // Use supervised queue if forced, otherwise use default queue
        if ($this->forceSupervision) {
            $this->onQueue('critical');
        } else {
            $this->onQueue('default');
        }
    }

    public function middleware(): array
    {
        // Force supervision even if queue is excluded
        if ($this->forceSupervision) {
            return [
                new SupervisionMiddleware(app(QueueFilter::class)),
            ];
        }

        // Let automatic supervision handle it
        return [];
    }

    public function handle(): void
    {
        if ($this->forceSupervision) {
            $this->heartbeat(['forced' => true]);
        }

        // Process job
    }

    public function heartbeat(array $metadata = []): void {}
    public function reportProgress(int $current, int $total, array $metadata = []): void {}
    public function getSupervisionId(): string { return $this->supervisionId; }
}

// Usage
HybridSupervisionJob::dispatch(forceSupervision: true);  // Always supervised
HybridSupervisionJob::dispatch(forceSupervision: false); // Depends on queue config
```

## Monitoring Queue Health

### Check Queue Supervision Status

Query which queues are actively supervised:

```php
use Cline\Chaperone\Database\Models\SupervisedJob;
use Illuminate\Support\Facades\DB;

// Get active queues with supervision
$activeQueues = SupervisedJob::select('queue')
    ->distinct()
    ->running()
    ->pluck('queue');

// Count jobs per queue
$queueStats = SupervisedJob::select('queue', DB::raw('COUNT(*) as count'))
    ->running()
    ->groupBy('queue')
    ->get();

foreach ($queueStats as $stat) {
    echo "Queue: {$stat->queue} - Running Jobs: {$stat->count}\n";
}
```

### Queue Health Metrics

Track health metrics per queue:

```php
use Cline\Chaperone\Database\Models\SupervisedJob;

class QueueHealthMonitor
{
    public function getQueueHealth(string $queueName): array
    {
        $jobs = SupervisedJob::where('queue', $queueName)->get();

        return [
            'queue' => $queueName,
            'total_jobs' => $jobs->count(),
            'running' => $jobs->where('status', 'running')->count(),
            'completed' => $jobs->where('status', 'completed')->count(),
            'failed' => $jobs->where('status', 'failed')->count(),
            'average_duration' => $jobs->where('status', 'completed')
                ->avg('duration'),
            'average_memory' => $jobs->avg('memory_usage'),
            'average_cpu' => $jobs->avg('cpu_usage'),
            'healthy_jobs' => $jobs->filter->isHealthy()->count(),
            'unhealthy_jobs' => $jobs->reject->isHealthy()->count(),
        ];
    }

    public function getAllQueuesHealth(): array
    {
        $queues = SupervisedJob::select('queue')
            ->distinct()
            ->pluck('queue');

        return $queues->mapWithKeys(function ($queue) {
            return [$queue => $this->getQueueHealth($queue)];
        })->toArray();
    }
}

// Usage
$monitor = new QueueHealthMonitor();
$health = $monitor->getAllQueuesHealth();

foreach ($health as $queueName => $metrics) {
    echo "Queue: {$queueName}\n";
    echo "  Running: {$metrics['running']}\n";
    echo "  Failed: {$metrics['failed']}\n";
    echo "  Avg Duration: {$metrics['average_duration']}s\n";
}
```

### Queue Performance Dashboard

Create a real-time queue dashboard:

```php
use Cline\Chaperone\Database\Models\SupervisedJob;
use Illuminate\Support\Facades\Cache;

class QueueDashboard
{
    public function getDashboardData(): array
    {
        return Cache::remember('queue-dashboard', 60, function () {
            $filter = app(QueueFilter::class);
            $supervisedQueues = $filter->getSupervisedQueues();
            $excludedQueues = $filter->getExcludedQueues();

            $data = [
                'configuration' => [
                    'supervised_queues' => $supervisedQueues,
                    'excluded_queues' => $excludedQueues,
                    'mode' => empty($supervisedQueues) ? 'all_except_excluded' : 'allowlist',
                ],
                'queues' => [],
            ];

            // Get metrics for each active queue
            $activeQueues = SupervisedJob::select('queue')
                ->distinct()
                ->pluck('queue');

            foreach ($activeQueues as $queue) {
                $is_supervised = $filter->shouldSupervise($queue);

                $data['queues'][$queue] = [
                    'name' => $queue,
                    'supervised' => $is_supervised,
                    'running_jobs' => SupervisedJob::where('queue', $queue)
                        ->running()
                        ->count(),
                    'failed_jobs' => SupervisedJob::where('queue', $queue)
                        ->failed()
                        ->whereDate('failed_at', today())
                        ->count(),
                    'completed_today' => SupervisedJob::where('queue', $queue)
                        ->completed()
                        ->whereDate('completed_at', today())
                        ->count(),
                    'average_duration' => SupervisedJob::where('queue', $queue)
                        ->completed()
                        ->whereDate('completed_at', today())
                        ->avg('duration'),
                ];
            }

            return $data;
        });
    }
}
```

## Testing Queue Configuration

### Unit Testing Queue Filters

Test your queue filtering logic:

```php
use Tests\TestCase;
use Cline\Chaperone\Queue\QueueFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;

class QueueFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervises_all_queues_when_no_config(): void
    {
        config(['chaperone.queue.supervised_queues' => []]);
        config(['chaperone.queue.excluded_queues' => []]);

        $filter = app(QueueFilter::class);

        $this->assertTrue($filter->shouldSupervise('default'));
        $this->assertTrue($filter->shouldSupervise('high'));
        $this->assertTrue($filter->shouldSupervise('low'));
    }

    public function test_excludes_queues_from_supervision(): void
    {
        config(['chaperone.queue.supervised_queues' => []]);
        config(['chaperone.queue.excluded_queues' => ['notifications', 'emails']]);

        $filter = app(QueueFilter::class);

        $this->assertTrue($filter->shouldSupervise('default'));
        $this->assertFalse($filter->shouldSupervise('notifications'));
        $this->assertFalse($filter->shouldSupervise('emails'));
    }

    public function test_only_supervises_allowlisted_queues(): void
    {
        config(['chaperone.queue.supervised_queues' => ['high', 'critical']]);
        config(['chaperone.queue.excluded_queues' => []]);

        $filter = app(QueueFilter::class);

        $this->assertTrue($filter->shouldSupervise('high'));
        $this->assertTrue($filter->shouldSupervise('critical'));
        $this->assertFalse($filter->shouldSupervise('default'));
        $this->assertFalse($filter->shouldSupervise('low'));
    }

    public function test_excluded_takes_precedence_over_supervised(): void
    {
        config(['chaperone.queue.supervised_queues' => ['high', 'critical', 'emails']]);
        config(['chaperone.queue.excluded_queues' => ['emails']]);

        $filter = app(QueueFilter::class);

        $this->assertTrue($filter->shouldSupervise('high'));
        $this->assertTrue($filter->shouldSupervise('critical'));
        $this->assertFalse($filter->shouldSupervise('emails'));
    }
}
```

### Integration Testing Queue Supervision

Test automatic supervision via middleware:

```php
use Tests\TestCase;
use App\Jobs\ProcessPayment;
use Cline\Chaperone\Database\Models\SupervisedJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

class QueueSupervisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_is_supervised_on_allowed_queue(): void
    {
        config(['chaperone.queue.supervised_queues' => ['payments']]);

        Queue::fake();

        ProcessPayment::dispatch()->onQueue('payments');

        Queue::assertPushed(ProcessPayment::class, function ($job) {
            return $job->queue === 'payments';
        });

        // In real test, would verify SupervisedJob record created
    }

    public function test_job_is_not_supervised_on_excluded_queue(): void
    {
        config(['chaperone.queue.excluded_queues' => ['emails']]);

        Queue::fake();

        SendEmail::dispatch()->onQueue('emails');

        Queue::assertPushed(SendEmail::class);

        // In real test, would verify no SupervisedJob record created
    }
}
```

## Best Practices

### 1. Start with Broad Supervision, Then Narrow

```php
// Phase 1: Supervise everything to gather data
CHAPERONE_SUPERVISED_QUEUES=
CHAPERONE_EXCLUDED_QUEUES=

// Phase 2: Exclude obvious non-critical queues
CHAPERONE_SUPERVISED_QUEUES=
CHAPERONE_EXCLUDED_QUEUES=notifications,cache,temp

// Phase 3: Move to allowlist for production
CHAPERONE_SUPERVISED_QUEUES=payments,orders,critical
CHAPERONE_EXCLUDED_QUEUES=notifications,cache,temp
```

### 2. Use Descriptive Queue Names

```php
// Good - clearly indicates purpose and priority
'queue-payment-processing'
'queue-order-fulfillment'
'queue-critical-alerts'

// Bad - ambiguous purpose
'queue1'
'high'
'background'
```

### 3. Align Queue Configuration with SLAs

```php
// Critical operations (99.99% uptime SLA)
CHAPERONE_SUPERVISED_QUEUES=payment-processing,order-creation,user-authentication

// Important operations (99.9% uptime SLA)
CHAPERONE_SUPERVISED_QUEUES=...previous...,data-sync,report-generation

// Best-effort operations (no SLA)
CHAPERONE_EXCLUDED_QUEUES=email-delivery,notification-sending,cache-warming
```

### 4. Monitor Queue Configuration Changes

```php
use Cline\Chaperone\Queue\QueueFilter;
use Illuminate\Support\Facades\Log;

class QueueConfigurationMonitor
{
    public function logConfigurationChanges(): void
    {
        $filter = app(QueueFilter::class);
        $supervised = $filter->getSupervisedQueues();
        $excluded = $filter->getExcludedQueues();

        Log::info('Queue configuration loaded', [
            'supervised_queues' => $supervised,
            'excluded_queues' => $excluded,
            'mode' => empty($supervised) ? 'all_except_excluded' : 'allowlist',
        ]);
    }
}
```

### 5. Document Queue Architecture

Create a queue map for your team:

```php
/**
 * Queue Architecture Documentation
 *
 * TIER 1 - Mission Critical (Always Supervised)
 * - payments: Payment processing, refunds, chargebacks
 * - orders: Order creation, fulfillment, shipping
 * - financial: Invoice generation, accounting sync
 *
 * TIER 2 - Important (Supervised in Production)
 * - data-sync: Third-party data synchronization
 * - reports: Business intelligence, analytics
 * - exports: Large data exports
 *
 * TIER 3 - Best Effort (Not Supervised)
 * - notifications: Email, SMS, push notifications
 * - cache: Cache warming, precomputation
 * - cleanup: Data cleanup, log rotation
 */
```

### 6. Use Environment-Specific Configuration

```php
// Base configuration in config/chaperone.php
'queue' => [
    'supervised_queues' => explode(',', env('CHAPERONE_SUPERVISED_QUEUES', '')),
    'excluded_queues' => explode(',', env('CHAPERONE_EXCLUDED_QUEUES', '')),
],

// .env.production
CHAPERONE_SUPERVISED_QUEUES=payments,orders,financial
CHAPERONE_EXCLUDED_QUEUES=notifications,cache

// .env.staging
CHAPERONE_SUPERVISED_QUEUES=
CHAPERONE_EXCLUDED_QUEUES=

// .env.local
CHAPERONE_SUPERVISED_QUEUES=
CHAPERONE_EXCLUDED_QUEUES=
```

## Troubleshooting

### Queue Not Being Supervised

**Problem:** Jobs on a queue are not being supervised

**Diagnosis:**

```bash
# Check configuration
php artisan chaperone:queues

# Verify queue name matches
php artisan queue:work --queue=your-queue-name --once -vvv
```

**Solutions:**

1. Verify queue name spelling matches configuration
2. Check that queue is not in excluded list
3. If using allowlist, ensure queue is in supervised list
4. Verify middleware is registered

### Supervision Overhead Too High

**Problem:** Supervision is consuming too many resources

**Diagnosis:**

```php
// Check supervised job count
SupervisedJob::running()->count();

// Check heartbeat frequency
SupervisedJob::with('heartbeats')->find($id)->heartbeats->count();
```

**Solutions:**

1. Exclude high-volume, low-risk queues
2. Increase heartbeat intervals
3. Use allowlist instead of supervising all queues
4. Implement queue-specific resource limits

### Middleware Not Applied

**Problem:** Supervision middleware not being applied to jobs

**Diagnosis:**

```php
// Check if middleware is registered
dd(app('queue')->getConnection()->pushed());
```

**Solutions:**

1. Verify service provider is loaded
2. Check job middleware configuration
3. Ensure queue worker is restarted after config changes

## Next Steps

- **[Circuit Breakers](circuit-breakers.md)** - Protect external services from cascading failures
- **[Resource Limits](resource-limits.md)** - Configure and enforce resource constraints
- **[Health Monitoring](health-monitoring.md)** - Advanced health checks and recovery strategies
- **[Events](events.md)** - Listen to supervision lifecycle events
- **[Advanced Usage](advanced-usage.md)** - Worker pools, deployment coordination, and more
