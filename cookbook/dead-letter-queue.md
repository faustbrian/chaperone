# Dead Letter Queue

The Dead Letter Queue (DLQ) is Chaperone's permanent failure handling system. When supervised jobs exceed their maximum retry attempts or encounter unrecoverable errors, they're automatically moved to the DLQ for inspection, debugging, and manual recovery. This guide covers DLQ integration, operations, monitoring, and best practices for handling permanently failed jobs.

## Understanding the Dead Letter Queue

### What is a Dead Letter Queue?

A dead letter queue is a holding area for jobs that cannot be successfully processed. Unlike transient failures that can be retried automatically, jobs in the DLQ require manual intervention. Chaperone's DLQ:

- **Preserves failure context** - Complete exception details, stack traces, and job payloads
- **Enables forensic analysis** - Full supervision history and error progression
- **Supports manual recovery** - Retry jobs after fixing underlying issues
- **Prevents data loss** - Failed jobs aren't silently discarded
- **Facilitates monitoring** - Track permanent failures and patterns

### When Jobs Move to the DLQ

Jobs are automatically moved to the DLQ when:

```php
// Automatic DLQ triggers
$errorCount = $job->errors()->count();
$maxRetries = config('chaperone.supervision.max_retries', 3);

if ($errorCount >= $maxRetries) {
    // Job moved to dead letter queue
}
```

This happens automatically through the `ChaperoneObserver` when a supervised job fails and has exhausted all retry attempts.

### DLQ Table Structure

The `dead_letter_queue` table stores comprehensive failure information:

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint/ulid/uuid | Primary key (configurable) |
| `supervised_job_id` | bigint/ulid/uuid | Foreign key to supervised job (nullable) |
| `job_class` | string | Fully qualified job class name |
| `exception` | string | Exception class name that caused failure |
| `message` | text | Exception message |
| `trace` | longtext | Complete stack trace |
| `payload` | json/jsonb | Job payload for retry attempts |
| `failed_at` | timestamp | When job was moved to DLQ |
| `retried_at` | timestamp | When job was retried from DLQ (nullable) |

## Configuration

Configure dead letter queue behavior in `config/chaperone.php`:

```php
return [
    'dead_letter_queue' => [
        // Enable/disable DLQ functionality
        'enabled' => env('CHAPERONE_DLQ_ENABLED', true),

        // Number of days to retain DLQ entries (0 = indefinite)
        'retention_period' => env('CHAPERONE_DLQ_RETENTION_DAYS', 30),
    ],

    'supervision' => [
        // Maximum retry attempts before moving to DLQ
        'max_retries' => env('CHAPERONE_MAX_RETRIES', 3),
    ],

    'table_names' => [
        'dead_letter_queue' => env('CHAPERONE_DLQ_TABLE', 'dead_letter_queue'),
    ],

    'models' => [
        'dead_letter_job' => \Cline\Chaperone\Database\Models\DeadLetterJob::class,
    ],
];
```

### Environment Variables

```env
# Enable dead letter queue
CHAPERONE_DLQ_ENABLED=true

# Retain DLQ entries for 30 days
CHAPERONE_DLQ_RETENTION_DAYS=30

# Maximum retries before DLQ (default: 3)
CHAPERONE_MAX_RETRIES=3

# Custom table name
CHAPERONE_DLQ_TABLE=dead_letter_queue
```

## Automatic DLQ Integration

Chaperone automatically manages the DLQ through the `ChaperoneObserver`. No manual intervention is required for standard job failures.

### How Automatic DLQ Works

```php
// When a supervised job is updated
public function updated(SupervisedJob $job): void
{
    // Check if job failed and status changed
    if ($job->failed_at && $job->wasChanged('failed_at')) {
        $this->handleJobFailure($job);
    }
}

// Handle the failure and check retry count
private function handleJobFailure(SupervisedJob $job): void
{
    // Count errors for this job
    $errorCount = $job->errors()->count();
    $maxRetries = config('chaperone.supervision.max_retries', 3);

    // Move to DLQ if exceeded max retries
    if ($errorCount >= $maxRetries) {
        $lastError = $job->errors()->latest('created_at')->first();

        $exception = new \RuntimeException($lastError->message);
        $this->deadLetterQueueManager->moveToDeadLetterQueue($job, $exception);
    }
}
```

### Example Job Flow

```php
use Cline\Chaperone\Contracts\Supervised;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ProcessPayment implements ShouldQueue, Supervised
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $supervisionId;

    // Configure retry behavior
    public int $tries = 3; // Laravel's built-in tries
    public int $backoff = 60; // Wait 60 seconds between retries

    public function __construct(
        private int $paymentId,
        private float $amount,
    ) {
        $this->supervisionId = (string) Str::uuid();
    }

    public function handle(): void
    {
        $this->heartbeat(['status' => 'processing_payment']);

        // Process payment (may throw exceptions)
        $payment = Payment::find($this->paymentId);
        $gateway = PaymentGateway::charge($payment, $this->amount);

        $this->heartbeat(['status' => 'completed', 'transaction_id' => $gateway->id]);
    }

    public function failed(\Throwable $exception): void
    {
        // After 3 failed attempts, this job will automatically
        // be moved to the dead letter queue by ChaperoneObserver
        \Log::error('Payment processing failed', [
            'payment_id' => $this->paymentId,
            'exception' => $exception->getMessage(),
        ]);
    }

    public function heartbeat(array $metadata = []): void {}
    public function reportProgress(int $current, int $total, array $metadata = []): void {}
    public function getSupervisionId(): string { return $this->supervisionId; }
}

// Dispatch the job
ProcessPayment::dispatch($paymentId, $amount);

// If it fails 3 times, it automatically moves to DLQ
// No manual intervention required
```

## Inspecting the Dead Letter Queue

### Query All DLQ Entries

```php
use Cline\Chaperone\Database\Models\DeadLetterJob;

// Get all dead letter jobs
$deadJobs = DeadLetterJob::all();

// Get recent failures (ordered by most recent first)
$recentFailures = DeadLetterJob::latest('failed_at')->get();

// Get DLQ entries with supervised job details
$deadJobs = DeadLetterJob::with('supervisedJob')->get();

foreach ($deadJobs as $deadJob) {
    echo "Job: {$deadJob->job_class}\n";
    echo "Failed at: {$deadJob->failed_at}\n";
    echo "Exception: {$deadJob->exception}\n";
    echo "Message: {$deadJob->message}\n";
}
```

### Filter DLQ Entries

```php
// Get entries by job class
$failedPayments = DeadLetterJob::where('job_class', ProcessPayment::class)->get();

// Get entries by exception type
$timeoutErrors = DeadLetterJob::where('exception', 'like', '%Timeout%')->get();

// Get entries from date range
$lastWeek = DeadLetterJob::where('failed_at', '>=', now()->subWeek())->get();

// Get entries that haven't been retried
$notRetried = DeadLetterJob::whereNull('retried_at')->get();

// Get entries that were retried
$retried = DeadLetterJob::whereNotNull('retried_at')->get();
```

### Access DLQ Details

```php
$deadJob = DeadLetterJob::find($id);

// Basic information
echo $deadJob->job_class;        // "App\Jobs\ProcessPayment"
echo $deadJob->exception;         // "RuntimeException"
echo $deadJob->message;          // "Payment gateway timeout"

// Stack trace for debugging
echo $deadJob->trace;            // Full stack trace

// Job payload for retry
$payload = $deadJob->payload;    // ['payment_id' => 123, 'amount' => 99.99]

// Timestamps
echo $deadJob->failed_at;        // Carbon instance
echo $deadJob->retried_at;       // Null or Carbon instance

// Associated supervised job (if still exists)
if ($deadJob->supervisedJob) {
    echo $deadJob->supervisedJob->status;
    echo $deadJob->supervisedJob->started_at;
    $errors = $deadJob->supervisedJob->errors;
    $heartbeats = $deadJob->supervisedJob->heartbeats;
}
```

## Using DeadLetterQueueManager

The `DeadLetterQueueManager` provides programmatic access to DLQ operations:

```php
use Cline\Chaperone\DeadLetterQueue\DeadLetterQueueManager;

// Inject via constructor or resolve from container
$dlqManager = app(DeadLetterQueueManager::class);

// Get all DLQ entries
$entries = $dlqManager->all();

// Get count of DLQ entries
$count = $dlqManager->count();

// Get specific entry
$entry = $dlqManager->get($deadLetterId);

// Retry a dead letter job
$dlqManager->retry($deadLetterId);

// Prune old entries
$deletedCount = $dlqManager->prune(30); // Delete entries older than 30 days
```

### Get Specific Entry

```php
$entry = $dlqManager->get($deadLetterId);

if ($entry) {
    echo $entry['id'];
    echo $entry['supervised_job_id'];
    echo $entry['job_class'];
    echo $entry['exception'];
    echo $entry['message'];
    echo $entry['trace'];
    print_r($entry['payload']);
    echo $entry['failed_at'];
    echo $entry['retried_at'];
}
```

## Retrying Failed Jobs

### Basic Retry

```php
use Cline\Chaperone\DeadLetterQueue\DeadLetterQueueManager;

$dlqManager = app(DeadLetterQueueManager::class);

// Retry a specific dead letter job
$dlqManager->retry($deadLetterId);

// The job is re-dispatched with its original payload
// The retried_at timestamp is updated
```

### Retry with Model

```php
use Cline\Chaperone\Database\Models\DeadLetterJob;

$deadJob = DeadLetterJob::find($id);

// Check if already retried
if ($deadJob->retried_at) {
    echo "Job was already retried at {$deadJob->retried_at}";
} else {
    // Retry the job
    $dlqManager->retry($deadJob->id);
}
```

### Batch Retry

```php
// Retry all failed payments
$failedPayments = DeadLetterJob::where('job_class', ProcessPayment::class)
    ->whereNull('retried_at')
    ->get();

foreach ($failedPayments as $deadJob) {
    try {
        $dlqManager->retry($deadJob->id);
        echo "Retried: {$deadJob->id}\n";
    } catch (\RuntimeException $e) {
        echo "Failed to retry: {$e->getMessage()}\n";
    }
}
```

### Conditional Retry

```php
// Retry only specific error types
$deadJobs = DeadLetterJob::where('exception', 'like', '%Timeout%')
    ->whereNull('retried_at')
    ->get();

foreach ($deadJobs as $deadJob) {
    // Only retry if error was more than 1 hour ago
    if ($deadJob->failed_at->diffInHours(now()) >= 1) {
        $dlqManager->retry($deadJob->id);
    }
}
```

### Manual Job Recreation

If automatic retry doesn't work, manually recreate the job:

```php
$deadJob = DeadLetterJob::find($id);

// Extract payload
$payload = $deadJob->payload;

// Manually dispatch new job instance
$jobClass = $deadJob->job_class;

if (class_exists($jobClass)) {
    // Create new job with original payload
    $job = new $jobClass(...$payload);

    // Dispatch to queue
    dispatch($job);

    // Mark as retried
    $deadJob->update(['retried_at' => now()]);
}
```

## Pruning Old Entries

### Using Artisan Command

Prune dead letter queue entries using the dedicated command:

```bash
# Prune using configured retention period (default: 30 days)
php artisan chaperone:prune-dead-letters

# Prune entries older than 60 days
php artisan chaperone:prune-dead-letters --days=60

# Weekly cleanup (7 days)
php artisan chaperone:prune-dead-letters --days=7
```

### Schedule Automatic Pruning

Add to your `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule): void
{
    // Daily cleanup at 2 AM
    $schedule->command('chaperone:prune-dead-letters')
        ->daily()
        ->at('02:00');

    // Weekly cleanup with custom retention
    $schedule->command('chaperone:prune-dead-letters --days=60')
        ->weekly()
        ->sundays()
        ->at('03:00');

    // Monthly deep cleanup
    $schedule->command('chaperone:prune-dead-letters --days=90')
        ->monthly();
}
```

### Programmatic Pruning

```php
use Cline\Chaperone\DeadLetterQueue\DeadLetterQueueManager;

$dlqManager = app(DeadLetterQueueManager::class);

// Prune using configured retention period
$deletedCount = $dlqManager->prune();

// Prune with custom retention period
$deletedCount = $dlqManager->prune(30); // Delete entries older than 30 days

echo "Deleted {$deletedCount} dead letter queue entries";
```

### Selective Pruning

```php
// Prune only retried entries
$deleted = DeadLetterJob::whereNotNull('retried_at')
    ->where('failed_at', '<', now()->subDays(7))
    ->delete();

// Prune specific job classes
$deleted = DeadLetterJob::where('job_class', ProcessPayment::class)
    ->where('failed_at', '<', now()->subDays(30))
    ->delete();

// Prune by exception type
$deleted = DeadLetterJob::where('exception', 'like', '%NetworkException%')
    ->where('failed_at', '<', now()->subDays(14))
    ->delete();
```

## Listening to DLQ Events

Chaperone fires the `JobMovedToDeadLetterQueue` event when jobs enter the DLQ:

```php
use Cline\Chaperone\Events\JobMovedToDeadLetterQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

// Register event listener in EventServiceProvider
Event::listen(JobMovedToDeadLetterQueue::class, function ($event) {
    Log::critical('Job moved to dead letter queue', [
        'supervision_id' => $event->supervisionId,
        'job_class' => $event->jobClass,
        'exception' => $event->exception->getMessage(),
        'failed_at' => $event->failedAt,
    ]);
});
```

### Send Alerts on DLQ Entry

```php
use App\Notifications\JobPermanentlyFailedNotification;
use App\Models\User;

Event::listen(JobMovedToDeadLetterQueue::class, function ($event) {
    // Send email to operations team
    $opsTeam = User::where('role', 'operations')->get();

    Notification::send(
        $opsTeam,
        new JobPermanentlyFailedNotification($event)
    );
});
```

### Trigger Incident Management

```php
Event::listen(JobMovedToDeadLetterQueue::class, function ($event) {
    // Create incident in tracking system
    Incident::create([
        'title' => "Job permanently failed: {$event->jobClass}",
        'description' => $event->exception->getMessage(),
        'severity' => 'high',
        'metadata' => [
            'supervision_id' => $event->supervisionId,
            'job_class' => $event->jobClass,
            'failed_at' => $event->failedAt,
            'stack_trace' => $event->exception->getTraceAsString(),
        ],
    ]);
});
```

### Integration with Monitoring Tools

```php
Event::listen(JobMovedToDeadLetterQueue::class, function ($event) {
    // Send to Sentry
    if (app()->bound('sentry')) {
        app('sentry')->captureException($event->exception, [
            'tags' => [
                'job_class' => $event->jobClass,
                'supervision_id' => $event->supervisionId,
            ],
        ]);
    }

    // Send to DataDog
    if (class_exists(\DataDog\Client::class)) {
        \DataDog\Client::increment('chaperone.dlq.entries', 1, [
            'job_class' => $event->jobClass,
        ]);
    }

    // Send to CloudWatch
    if (class_exists(\Aws\CloudWatch\CloudWatchClient::class)) {
        // Log metric to CloudWatch
    }
});
```

## DLQ Analysis and Patterns

### Identify Common Failures

```php
use Illuminate\Support\Facades\DB;

// Most common failure types
$failureTypes = DeadLetterJob::select('exception', DB::raw('count(*) as count'))
    ->groupBy('exception')
    ->orderByDesc('count')
    ->get();

foreach ($failureTypes as $failure) {
    echo "{$failure->exception}: {$failure->count} failures\n";
}

// Most problematic jobs
$problematicJobs = DeadLetterJob::select('job_class', DB::raw('count(*) as count'))
    ->groupBy('job_class')
    ->orderByDesc('count')
    ->get();
```

### Analyze Failure Trends

```php
// Failures by day
$dailyFailures = DeadLetterJob::select(
    DB::raw('DATE(failed_at) as date'),
    DB::raw('count(*) as count')
)
    ->where('failed_at', '>=', now()->subDays(30))
    ->groupBy('date')
    ->orderBy('date')
    ->get();

// Failures by hour (identify peak failure times)
$hourlyPattern = DeadLetterJob::select(
    DB::raw('HOUR(failed_at) as hour'),
    DB::raw('count(*) as count')
)
    ->where('failed_at', '>=', now()->subDays(7))
    ->groupBy('hour')
    ->orderBy('hour')
    ->get();
```

### Correlation with Supervised Jobs

```php
// Analyze supervision history for DLQ entries
$deadJob = DeadLetterJob::with([
    'supervisedJob.errors',
    'supervisedJob.heartbeats',
    'supervisedJob.resourceViolations'
])->find($id);

if ($deadJob->supervisedJob) {
    echo "Supervision started: {$deadJob->supervisedJob->started_at}\n";
    echo "Total errors: {$deadJob->supervisedJob->errors->count()}\n";
    echo "Total heartbeats: {$deadJob->supervisedJob->heartbeats->count()}\n";

    // Analyze error progression
    foreach ($deadJob->supervisedJob->errors as $error) {
        echo "[{$error->created_at}] {$error->exception}: {$error->message}\n";
    }

    // Check for resource violations
    foreach ($deadJob->supervisedJob->resourceViolations as $violation) {
        echo "Violated {$violation->type}: {$violation->actual} > {$violation->limit}\n";
    }
}
```

## Debugging with DLQ Data

### Extract Full Context

```php
$deadJob = DeadLetterJob::with('supervisedJob')->find($id);

// Complete failure context
$context = [
    'job' => [
        'class' => $deadJob->job_class,
        'payload' => $deadJob->payload,
    ],
    'failure' => [
        'exception' => $deadJob->exception,
        'message' => $deadJob->message,
        'trace' => $deadJob->trace,
        'failed_at' => $deadJob->failed_at,
    ],
    'supervision' => $deadJob->supervisedJob ? [
        'id' => $deadJob->supervisedJob->job_id,
        'started_at' => $deadJob->supervisedJob->started_at,
        'duration' => $deadJob->supervisedJob->started_at->diffInSeconds($deadJob->failed_at),
        'memory_usage' => $deadJob->supervisedJob->memory_usage,
        'cpu_usage' => $deadJob->supervisedJob->cpu_usage,
        'heartbeat_count' => $deadJob->supervisedJob->heartbeats->count(),
        'error_count' => $deadJob->supervisedJob->errors->count(),
    ] : null,
];

// Export for debugging
file_put_contents("debug-{$deadJob->id}.json", json_encode($context, JSON_PRETTY_PRINT));
```

### Reproduce Failures Locally

```php
// Extract job and payload from DLQ
$deadJob = DeadLetterJob::find($id);

// Recreate in local environment for debugging
$jobClass = $deadJob->job_class;
$payload = $deadJob->payload;

// Run synchronously for debugging
$job = new $jobClass(...$payload);

try {
    $job->handle();
} catch (\Throwable $e) {
    // Debug exception
    dd($e);
}
```

### Compare Multiple Failures

```php
// Compare similar failures
$similarFailures = DeadLetterJob::where('job_class', ProcessPayment::class)
    ->where('exception', 'RuntimeException')
    ->limit(10)
    ->get();

foreach ($similarFailures as $failure) {
    echo "ID: {$failure->id}\n";
    echo "Message: {$failure->message}\n";
    echo "Payload: " . json_encode($failure->payload) . "\n";
    echo "Failed at: {$failure->failed_at}\n\n";
}
```

## Production DLQ Monitoring

### Build DLQ Dashboard

```php
use Cline\Chaperone\Database\Models\DeadLetterJob;

class DlqDashboardController extends Controller
{
    public function index()
    {
        return view('dlq.dashboard', [
            'total' => DeadLetterJob::count(),
            'today' => DeadLetterJob::whereDate('failed_at', today())->count(),
            'this_week' => DeadLetterJob::where('failed_at', '>=', now()->subWeek())->count(),
            'not_retried' => DeadLetterJob::whereNull('retried_at')->count(),
            'recent' => DeadLetterJob::latest('failed_at')->limit(10)->get(),
            'by_class' => DeadLetterJob::select('job_class', DB::raw('count(*) as count'))
                ->groupBy('job_class')
                ->orderByDesc('count')
                ->get(),
            'by_exception' => DeadLetterJob::select('exception', DB::raw('count(*) as count'))
                ->groupBy('exception')
                ->orderByDesc('count')
                ->get(),
        ]);
    }
}
```

### Health Check Endpoint

```php
Route::get('/health/dlq', function () {
    $count = DeadLetterJob::whereNull('retried_at')
        ->where('failed_at', '>=', now()->subHour())
        ->count();

    $status = match (true) {
        $count === 0 => 'healthy',
        $count < 10 => 'degraded',
        default => 'unhealthy',
    };

    return response()->json([
        'status' => $status,
        'dlq_count' => $count,
        'timestamp' => now(),
    ], $status === 'unhealthy' ? 500 : 200);
});
```

### Automated Recovery

```php
use Illuminate\Console\Command;

class AutoRecoverDlqCommand extends Command
{
    protected $signature = 'dlq:auto-recover';
    protected $description = 'Automatically retry recoverable DLQ entries';

    public function handle(DeadLetterQueueManager $dlqManager): int
    {
        // Identify recoverable failures (e.g., timeouts, network errors)
        $recoverable = DeadLetterJob::whereNull('retried_at')
            ->where('failed_at', '>=', now()->subHours(24))
            ->where(function ($query) {
                $query->where('exception', 'like', '%Timeout%')
                    ->orWhere('exception', 'like', '%Connection%')
                    ->orWhere('exception', 'like', '%Network%');
            })
            ->get();

        $recovered = 0;

        foreach ($recoverable as $deadJob) {
            try {
                $dlqManager->retry($deadJob->id);
                $recovered++;
                $this->info("Recovered: {$deadJob->job_class}");
            } catch (\Throwable $e) {
                $this->error("Failed to recover: {$e->getMessage()}");
            }
        }

        $this->info("Auto-recovered {$recovered} jobs");

        return self::SUCCESS;
    }
}
```

## Best Practices

### 1. Configure Appropriate Retention

```php
// Short retention for high-volume, low-impact jobs
'retention_period' => env('CHAPERONE_DLQ_RETENTION_DAYS', 7),

// Long retention for critical jobs requiring audit trails
'retention_period' => env('CHAPERONE_DLQ_RETENTION_DAYS', 90),

// Indefinite retention for compliance
'retention_period' => env('CHAPERONE_DLQ_RETENTION_DAYS', 0),
```

### 2. Set Realistic Max Retries

```php
// Quick-failing jobs (API calls, external services)
'max_retries' => 3,

// Jobs with transient failures
'max_retries' => 5,

// Critical jobs requiring more attempts
'max_retries' => 10,
```

### 3. Monitor DLQ Growth

```php
// Alert if DLQ grows rapidly
Event::listen(JobMovedToDeadLetterQueue::class, function ($event) {
    $recentCount = DeadLetterJob::where('failed_at', '>=', now()->subHour())->count();

    if ($recentCount > 50) {
        // Alert operations team
        Alert::send('DLQ threshold exceeded', [
            'count' => $recentCount,
            'job_class' => $event->jobClass,
        ]);
    }
});
```

### 4. Preserve Job Context

```php
class ProcessPayment implements ShouldQueue, Supervised
{
    public function __construct(
        private int $paymentId,
        private float $amount,
        private array $metadata = [],
    ) {
        $this->supervisionId = (string) Str::uuid();

        // Store context for DLQ debugging
        $this->metadata = array_merge($metadata, [
            'user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'timestamp' => now(),
        ]);
    }
}
```

### 5. Regular DLQ Review

```php
// Schedule weekly DLQ review
$schedule->call(function () {
    $unretried = DeadLetterJob::whereNull('retried_at')
        ->where('failed_at', '>=', now()->subWeek())
        ->get();

    if ($unretried->isNotEmpty()) {
        // Send summary to ops team
        Mail::to('ops@example.com')->send(
            new WeeklyDlqReportMail($unretried)
        );
    }
})->weekly()->mondays()->at('09:00');
```

### 6. Categorize Failures

```php
// Add failure categories to DLQ entries
Event::listen(JobMovedToDeadLetterQueue::class, function ($event) {
    $category = match (true) {
        str_contains($event->exception::class, 'Timeout') => 'timeout',
        str_contains($event->exception::class, 'Connection') => 'network',
        str_contains($event->exception::class, 'Memory') => 'resource',
        str_contains($event->exception::class, 'Validation') => 'data',
        default => 'unknown',
    };

    // Store category in separate tracking system
    FailureCategory::create([
        'dead_letter_id' => $event->supervisionId,
        'category' => $category,
    ]);
});
```

### 7. Implement Circuit Breakers

Prevent cascading DLQ entries by using circuit breakers:

```php
use Cline\Chaperone\Facades\CircuitBreaker;

class ProcessPayment implements ShouldQueue, Supervised
{
    public function handle(): void
    {
        CircuitBreaker::for('payment-gateway')
            ->execute(function () {
                // Process payment
            }, fallback: function () {
                // Circuit open - fail fast instead of retrying
                throw new \RuntimeException('Payment gateway circuit open');
            });
    }
}
```

### 8. Export DLQ for Analysis

```php
// Export DLQ entries for external analysis
$entries = DeadLetterJob::with('supervisedJob')
    ->where('failed_at', '>=', now()->subMonth())
    ->get()
    ->map(fn ($entry) => [
        'id' => $entry->id,
        'job_class' => $entry->job_class,
        'exception' => $entry->exception,
        'message' => $entry->message,
        'failed_at' => $entry->failed_at,
        'supervision_duration' => $entry->supervisedJob?->started_at->diffInSeconds($entry->failed_at),
    ]);

// Export to CSV
$csv = fopen('dlq-export.csv', 'w');
fputcsv($csv, array_keys($entries->first()));
foreach ($entries as $entry) {
    fputcsv($csv, $entry);
}
fclose($csv);
```

## Common Patterns

### Pattern 1: Temporary Service Outage Recovery

```php
// Automatically retry after service restoration
class RetryServiceOutagesCommand extends Command
{
    protected $signature = 'dlq:retry-service-outages';

    public function handle(DeadLetterQueueManager $dlqManager): int
    {
        $serviceOutages = DeadLetterJob::whereNull('retried_at')
            ->where('exception', 'like', '%ServiceUnavailable%')
            ->where('failed_at', '>=', now()->subHours(4))
            ->get();

        foreach ($serviceOutages as $deadJob) {
            $dlqManager->retry($deadJob->id);
        }

        return self::SUCCESS;
    }
}
```

### Pattern 2: Manual Job Inspection and Fix

```php
// Inspect DLQ entry
$deadJob = DeadLetterJob::find($id);

// Fix the underlying issue (e.g., update database, fix configuration)
$payment = Payment::find($deadJob->payload['payment_id']);
$payment->update(['gateway_status' => 'ready']);

// Retry the job
$dlqManager->retry($deadJob->id);
```

### Pattern 3: Conditional Retry Based on Context

```php
// Only retry if conditions are met
$deadJobs = DeadLetterJob::whereNull('retried_at')->get();

foreach ($deadJobs as $deadJob) {
    $shouldRetry = match ($deadJob->job_class) {
        ProcessPayment::class => $this->isPaymentGatewayHealthy(),
        SendNotification::class => $this->isNotificationServiceHealthy(),
        SyncData::class => $this->isExternalApiHealthy(),
        default => false,
    };

    if ($shouldRetry) {
        $dlqManager->retry($deadJob->id);
    }
}
```

## Next Steps

- **[Health Monitoring](health-monitoring.md)** - Advanced health checks and recovery strategies
- **[Circuit Breakers](circuit-breakers.md)** - Prevent cascading failures that lead to DLQ entries
- **[Resource Limits](resource-limits.md)** - Configure constraints to prevent resource-related DLQ entries
- **[Events](events.md)** - Listen to all supervision lifecycle events
- **[Artisan Commands](artisan-commands.md)** - Complete reference for DLQ management commands
