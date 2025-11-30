# Basic Supervision

This guide covers the core functionality of Chaperone's job supervision, including the Supervised contract, heartbeat mechanisms, progress tracking, and common supervision patterns.

## The Supervised Contract

All supervised jobs must implement the `Supervised` contract:

```php
namespace Cline\Chaperone\Contracts;

interface Supervised
{
    public function heartbeat(array $metadata = []): void;

    public function reportProgress(int $current, int $total, array $metadata = []): void;

    public function getSupervisionId(): string;
}
```

### Basic Implementation

```php
<?php

namespace App\Jobs;

use Cline\Chaperone\Contracts\Supervised;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ProcessDataset implements ShouldQueue, Supervised
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $supervisionId;

    public function __construct()
    {
        $this->supervisionId = (string) Str::uuid();
    }

    public function handle(): void
    {
        // Your job logic
    }

    public function heartbeat(array $metadata = []): void
    {
        // Automatically handled by Chaperone
    }

    public function reportProgress(int $current, int $total, array $metadata = []): void
    {
        // Automatically handled by Chaperone
    }

    public function getSupervisionId(): string
    {
        return $this->supervisionId;
    }
}
```

## Heartbeat Mechanism

Heartbeats are periodic signals that indicate your job is alive and healthy. Chaperone uses heartbeats to detect stuck or hung jobs.

### Manual Heartbeats

Send heartbeats at key points in your job:

```php
public function handle(): void
{
    $this->heartbeat(['status' => 'started']);

    $records = $this->fetchRecords();
    $this->heartbeat(['status' => 'fetched', 'count' => $records->count()]);

    foreach ($records as $record) {
        $this->processRecord($record);
        $this->heartbeat(['status' => 'processing', 'id' => $record->id]);
    }

    $this->heartbeat(['status' => 'completed']);
}
```

### Heartbeat Intervals

Configure heartbeat frequency based on job duration:

```php
class ShortLivedJob implements ShouldQueue, Supervised
{
    // Default: 60 seconds
    public int $heartbeatInterval = 30; // Send every 30 seconds
}

class LongRunningJob implements ShouldQueue, Supervised
{
    public int $heartbeatInterval = 300; // Send every 5 minutes
}
```

### Heartbeat Metadata

Include useful diagnostic information in heartbeats:

```php
$this->heartbeat([
    'memory' => memory_get_usage(true),
    'cpu' => sys_getloadavg()[0],
    'records_processed' => $this->recordsProcessed,
    'phase' => 'processing',
    'batch_id' => $this->currentBatch,
    'errors_encountered' => $this->errorCount,
]);
```

## Progress Tracking

Progress tracking helps monitor long-running jobs and provides visibility into completion estimates.

### Basic Progress

Report progress as you process items:

```php
public function handle(): void
{
    $records = Record::all();
    $total = $records->count();

    foreach ($records as $index => $record) {
        $this->processRecord($record);
        $this->reportProgress($index + 1, $total);
    }
}
```

### Progress with Metadata

Add context to progress reports:

```php
$this->reportProgress($current, $total, [
    'phase' => 'data_validation',
    'errors' => $this->validationErrors,
    'warnings' => $this->validationWarnings,
]);
```

### Multi-Phase Progress

Track progress across different job phases:

```php
public function handle(): void
{
    // Phase 1: Fetch data (0-25%)
    $records = $this->fetchRecords();
    $this->reportProgress(25, 100, ['phase' => 'fetch']);

    // Phase 2: Validate (25-50%)
    $validated = $this->validateRecords($records);
    $this->reportProgress(50, 100, ['phase' => 'validate']);

    // Phase 3: Process (50-90%)
    foreach ($validated as $index => $record) {
        $this->processRecord($record);
        $progress = 50 + (($index + 1) / count($validated) * 40);
        $this->reportProgress((int) $progress, 100, ['phase' => 'process']);
    }

    // Phase 4: Cleanup (90-100%)
    $this->cleanup();
    $this->reportProgress(100, 100, ['phase' => 'cleanup']);
}
```

### Chunked Processing

Report progress when processing in chunks:

```php
public function handle(): void
{
    $total = User::count();
    $processed = 0;

    User::chunk(100, function ($users) use ($total, &$processed) {
        foreach ($users as $user) {
            $this->processUser($user);
            $processed++;

            // Report progress every 10 users
            if ($processed % 10 === 0) {
                $this->reportProgress($processed, $total);
            }
        }
    });
}
```

## Common Patterns

### Batch Processing

Process large datasets in batches with supervision:

```php
use App\Models\Transaction;

class ProcessTransactions implements ShouldQueue, Supervised
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $supervisionId;

    public function __construct(
        private string $batchId,
        private int $batchSize = 100,
    ) {
        $this->supervisionId = (string) Str::uuid();
    }

    public function handle(): void
    {
        $total = Transaction::pending()->count();
        $processed = 0;

        Transaction::pending()->chunk($this->batchSize, function ($transactions) use ($total, &$processed) {
            foreach ($transactions as $transaction) {
                $this->processTransaction($transaction);
                $processed++;

                // Heartbeat every 50 records
                if ($processed % 50 === 0) {
                    $this->heartbeat([
                        'processed' => $processed,
                        'memory' => memory_get_usage(true),
                    ]);
                }

                // Report progress
                $this->reportProgress($processed, $total);
            }
        });
    }

    private function processTransaction($transaction): void
    {
        // Process logic
    }

    public function heartbeat(array $metadata = []): void {}
    public function reportProgress(int $current, int $total, array $metadata = []): void {}
    public function getSupervisionId(): string { return $this->supervisionId; }
}
```

### File Processing

Process large files with progress tracking:

```php
use Illuminate\Support\Facades\Storage;

class ProcessCsvFile implements ShouldQueue, Supervised
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $supervisionId;

    public function __construct(
        private string $filePath,
    ) {
        $this->supervisionId = (string) Str::uuid();
    }

    public function handle(): void
    {
        $this->heartbeat(['status' => 'started', 'file' => $this->filePath]);

        // Count total lines
        $total = $this->countLines($this->filePath);
        $this->heartbeat(['status' => 'counted', 'total' => $total]);

        $handle = fopen(Storage::path($this->filePath), 'r');
        $current = 0;

        while (($line = fgets($handle)) !== false) {
            $this->processLine($line);
            $current++;

            // Report progress every 100 lines
            if ($current % 100 === 0) {
                $this->reportProgress($current, $total);
                $this->heartbeat([
                    'processed' => $current,
                    'memory' => memory_get_usage(true),
                ]);
            }
        }

        fclose($handle);
        $this->heartbeat(['status' => 'completed']);
    }

    private function countLines(string $path): int
    {
        $lineCount = 0;
        $handle = fopen(Storage::path($path), 'r');
        while (fgets($handle) !== false) {
            $lineCount++;
        }
        fclose($handle);
        return $lineCount;
    }

    private function processLine(string $line): void
    {
        // Process logic
    }

    public function heartbeat(array $metadata = []): void {}
    public function reportProgress(int $current, int $total, array $metadata = []): void {}
    public function getSupervisionId(): string { return $this->supervisionId; }
}
```

### API Integration

Supervise jobs that integrate with external APIs:

```php
use Illuminate\Support\Facades\Http;

class SyncExternalData implements ShouldQueue, Supervised
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $supervisionId;

    public function __construct()
    {
        $this->supervisionId = (string) Str::uuid();
    }

    public function handle(): void
    {
        $this->heartbeat(['status' => 'fetching']);

        // Fetch data from external API
        $response = Http::retry(3, 100)
            ->get('https://api.example.com/data');

        $items = $response->json('data');
        $total = count($items);

        $this->heartbeat([
            'status' => 'fetched',
            'count' => $total,
        ]);

        foreach ($items as $index => $item) {
            $this->syncItem($item);

            $this->reportProgress($index + 1, $total);

            // Heartbeat every 50 items
            if (($index + 1) % 50 === 0) {
                $this->heartbeat([
                    'synced' => $index + 1,
                    'memory' => memory_get_usage(true),
                ]);
            }

            // Rate limiting
            usleep(100000); // 100ms delay
        }

        $this->heartbeat(['status' => 'completed']);
    }

    private function syncItem(array $item): void
    {
        // Sync logic
    }

    public function heartbeat(array $metadata = []): void {}
    public function reportProgress(int $current, int $total, array $metadata = []): void {}
    public function getSupervisionId(): string { return $this->supervisionId; }
}
```

### Database Operations

Supervise bulk database operations:

```php
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BulkUpdateUsers implements ShouldQueue, Supervised
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $supervisionId;

    public function __construct(
        private array $criteria,
        private array $updates,
    ) {
        $this->supervisionId = (string) Str::uuid();
    }

    public function handle(): void
    {
        $this->heartbeat(['status' => 'started']);

        $total = User::where($this->criteria)->count();
        $updated = 0;

        User::where($this->criteria)->chunkById(100, function ($users) use ($total, &$updated) {
            DB::transaction(function () use ($users, $total, &$updated) {
                foreach ($users as $user) {
                    $user->update($this->updates);
                    $updated++;

                    if ($updated % 10 === 0) {
                        $this->reportProgress($updated, $total);
                    }
                }
            });

            $this->heartbeat([
                'updated' => $updated,
                'memory' => memory_get_usage(true),
            ]);
        });

        $this->heartbeat(['status' => 'completed', 'total_updated' => $updated]);
    }

    public function heartbeat(array $metadata = []): void {}
    public function reportProgress(int $current, int $total, array $metadata = []): void {}
    public function getSupervisionId(): string { return $this->supervisionId; }
}
```

## Querying Supervised Jobs

Use the `SupervisedJob` model to query job status:

```php
use Cline\Chaperone\Database\Models\SupervisedJob;

// Get all running jobs
$running = SupervisedJob::running()->get();

// Get completed jobs
$completed = SupervisedJob::completed()->get();

// Get failed jobs
$failed = SupervisedJob::failed()->get();

// Get jobs by class
$processingJobs = SupervisedJob::where('job_class', ProcessDataset::class)->get();

// Get jobs by queue
$highPriorityJobs = SupervisedJob::where('queue', 'high')->get();

// Get stuck jobs (timeout exceeded)
$stuck = SupervisedJob::where('timeout_at', '<', now())
    ->running()
    ->get();

// Get jobs with high memory usage
$memoryHogs = SupervisedJob::where('memory_usage', '>', 1024 * 1024 * 500)
    ->running()
    ->get();
```

### Available Query Scopes

```php
// Status scopes
SupervisedJob::pending()->get();
SupervisedJob::running()->get();
SupervisedJob::completed()->get();
SupervisedJob::failed()->get();

// Time-based scopes
SupervisedJob::today()->get();
SupervisedJob::whereBetween('started_at', [$start, $end])->get();

// Health scopes
SupervisedJob::healthy()->get();
SupervisedJob::unhealthy()->get();
SupervisedJob::degraded()->get();

// Resource scopes
SupervisedJob::where('cpu_usage', '>', 80)->get();
SupervisedJob::where('memory_usage', '>', $limit)->get();
```

## Accessing Job Details

```php
$job = SupervisedJob::where('job_id', $supervisionId)->first();

// Basic info
echo $job->job_class;
echo $job->queue;
echo $job->status;

// Timing
echo $job->started_at;
echo $job->completed_at;
echo $job->duration; // Calculated in seconds

// Resources
echo $job->memory_usage; // In bytes
echo $job->cpu_usage; // Percentage

// Progress
echo $job->progress; // 0-100

// Health status
if ($job->isHealthy()) {
    echo "Job is healthy";
}

// Heartbeats
$latestHeartbeat = $job->heartbeats()->latest()->first();
$allHeartbeats = $job->heartbeats()->get();

// Errors
if ($job->failed()) {
    foreach ($job->errors as $error) {
        echo $error->message;
        echo $error->trace;
    }
}

// Resource violations
$violations = $job->resourceViolations;
foreach ($violations as $violation) {
    echo "Type: {$violation->type}";
    echo "Limit: {$violation->limit}";
    echo "Actual: {$violation->actual}";
}
```

## Best Practices

### 1. Choose Appropriate Heartbeat Intervals

```php
// Quick jobs (< 5 minutes)
public int $heartbeatInterval = 10; // Every 10 seconds

// Medium jobs (5-30 minutes)
public int $heartbeatInterval = 60; // Every minute

// Long jobs (> 30 minutes)
public int $heartbeatInterval = 300; // Every 5 minutes
```

### 2. Include Meaningful Metadata

```php
// Good - provides diagnostic value
$this->heartbeat([
    'memory' => memory_get_usage(true),
    'cpu' => sys_getloadavg()[0],
    'records_processed' => $count,
    'errors_encountered' => $errors,
    'current_phase' => 'validation',
]);

// Bad - not useful
$this->heartbeat(['status' => 'ok']);
```

### 3. Report Progress Accurately

```php
// Good - tracks actual progress
$this->reportProgress($processedCount, $totalCount);

// Bad - fake progress
$this->reportProgress(50, 100); // Always 50%
```

### 4. Handle Cleanup in Finally Blocks

```php
public function handle(): void
{
    try {
        $this->heartbeat(['status' => 'started']);

        // Process data

        $this->heartbeat(['status' => 'completed']);
    } finally {
        // Always cleanup, even on failure
        $this->cleanup();
    }
}
```

### 5. Use Unique Supervision IDs

```php
// Good - unique per job instance
$this->supervisionId = (string) Str::uuid();

// Bad - same for all instances
$this->supervisionId = 'my-job';
```

## Testing Supervised Jobs

Test your supervised jobs with PHPUnit:

```php
use Tests\TestCase;
use App\Jobs\ProcessDataset;
use Cline\Chaperone\Database\Models\SupervisedJob;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProcessDatasetTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_sends_heartbeats(): void
    {
        $job = new ProcessDataset();
        $supervisionId = $job->getSupervisionId();

        $job->handle();

        $supervisedJob = SupervisedJob::where('job_id', $supervisionId)->first();

        $this->assertNotNull($supervisedJob);
        $this->assertTrue($supervisedJob->heartbeats()->exists());
    }

    public function test_job_reports_progress(): void
    {
        $job = new ProcessDataset();
        $supervisionId = $job->getSupervisionId();

        $job->handle();

        $supervisedJob = SupervisedJob::where('job_id', $supervisionId)->first();

        $this->assertEquals(100, $supervisedJob->progress);
    }

    public function test_job_completes_successfully(): void
    {
        $job = new ProcessDataset();
        $supervisionId = $job->getSupervisionId();

        $job->handle();

        $supervisedJob = SupervisedJob::where('job_id', $supervisionId)->first();

        $this->assertTrue($supervisedJob->completed());
        $this->assertNotNull($supervisedJob->completed_at);
    }
}
```

## Next Steps

- **[Circuit Breakers](circuit-breakers.md)** - Protect external services from cascading failures
- **[Resource Limits](resource-limits.md)** - Configure and enforce resource constraints
- **[Health Monitoring](health-monitoring.md)** - Advanced health checks and recovery strategies
- **[Events](events.md)** - Listen to supervision lifecycle events
- **[Advanced Usage](advanced-usage.md)** - Worker pools, deployment coordination, and more
