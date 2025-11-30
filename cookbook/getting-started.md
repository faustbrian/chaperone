# Getting Started

Welcome to Chaperone, a Laravel package that supervises long-running queue jobs with health monitoring, circuit breakers, and resource limits. This guide will help you install, configure, and create your first supervised job.

## Installation

Install Chaperone via Composer:

```bash
composer require cline/chaperone
```

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=chaperone-config
```

This creates `config/chaperone.php` with the following structure:

```php
return [
    // Primary key type for database tables
    'primary_key_type' => env('CHAPERONE_PRIMARY_KEY_TYPE', 'id'),

    // Polymorphic relationship type
    'morph_type' => env('CHAPERONE_MORPH_TYPE', 'morph'),

    'supervision' => [
        // Maximum execution time before job considered stuck
        'timeout' => env('CHAPERONE_TIMEOUT', 3600),

        // Maximum memory usage in megabytes
        'memory_limit' => env('CHAPERONE_MEMORY_LIMIT', 512),

        // Maximum CPU percentage (0-100)
        'cpu_limit' => env('CHAPERONE_CPU_LIMIT', 80),

        // Heartbeat interval in seconds
        'heartbeat_interval' => env('CHAPERONE_HEARTBEAT_INTERVAL', 60),

        // Maximum retry attempts
        'max_retries' => env('CHAPERONE_MAX_RETRIES', 3),

        // Retry delay in seconds
        'retry_delay' => env('CHAPERONE_RETRY_DELAY', 60),
    ],

    'circuit_breaker' => [
        'enabled' => env('CHAPERONE_CIRCUIT_BREAKER_ENABLED', true),
        'failure_threshold' => env('CHAPERONE_CIRCUIT_BREAKER_THRESHOLD', 5),
        'success_threshold' => env('CHAPERONE_CIRCUIT_BREAKER_SUCCESS_THRESHOLD', 2),
        'timeout' => env('CHAPERONE_CIRCUIT_BREAKER_TIMEOUT', 300),
    ],

    'monitoring' => [
        'pulse' => env('CHAPERONE_PULSE_ENABLED', false),
        'telescope' => env('CHAPERONE_TELESCOPE_ENABLED', false),
        'horizon' => env('CHAPERONE_HORIZON_ENABLED', false),
    ],
];
```

### Primary Key Configuration

Chaperone supports three primary key types:

- **`id`** (default) - Auto-incrementing integers
- **`ulid`** - Universally Unique Lexicographically Sortable Identifier
- **`uuid`** - Universally Unique Identifier

Set your preferred type in `.env`:

```env
CHAPERONE_PRIMARY_KEY_TYPE=ulid
```

### Morph Type Configuration

Configure polymorphic relationship types:

- **`morph`** (default) - Standard Laravel morphs
- **`uuidMorph`** - UUID-based polymorphic relationships
- **`ulidMorph`** - ULID-based polymorphic relationships
- **`numericMorph`** - Numeric polymorphic relationships

## Database Setup

Publish and run the migrations:

```bash
php artisan vendor:publish --tag=chaperone-migrations
php artisan migrate
```

This creates six tables:

### supervised_jobs

Tracks all supervised jobs with their health status and execution metrics:

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint/ulid/uuid | Primary key (configurable) |
| `job_id` | string | Unique job instance identifier |
| `job_class` | string | Fully qualified job class name |
| `queue` | string | Queue name |
| `status` | enum | Job status (pending, running, completed, failed) |
| `started_at` | timestamp | When job execution started |
| `completed_at` | timestamp | When job execution finished |
| `failed_at` | timestamp | When job failed |
| `timeout_at` | timestamp | When job will timeout |
| `memory_usage` | integer | Current memory usage in bytes |
| `cpu_usage` | decimal | Current CPU usage percentage |
| `progress` | integer | Job progress (0-100) |

### heartbeats

Stores periodic health signals from running jobs:

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint/ulid/uuid | Primary key (configurable) |
| `supervised_job_id` | bigint/ulid/uuid | Foreign key to supervised_jobs |
| `heartbeat_id` | string | Unique heartbeat identifier |
| `metadata` | json | Optional metadata from job |
| `created_at` | timestamp | When heartbeat was received |

### circuit_breakers

Manages circuit breaker state for protected services:

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint/ulid/uuid | Primary key (configurable) |
| `service` | string | Protected service identifier |
| `state` | enum | Circuit state (closed, open, half_open) |
| `failure_count` | integer | Consecutive failure count |
| `success_count` | integer | Consecutive success count |
| `last_failure_at` | timestamp | Last failure timestamp |
| `opened_at` | timestamp | When circuit opened |

### resource_violations

Logs when jobs exceed configured resource limits:

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint/ulid/uuid | Primary key (configurable) |
| `supervised_job_id` | bigint/ulid/uuid | Foreign key to supervised_jobs |
| `type` | enum | Violation type (memory, cpu, timeout) |
| `limit` | decimal | Configured limit |
| `actual` | decimal | Actual value that exceeded limit |
| `created_at` | timestamp | When violation was detected |

### job_health_checks

Stores health check results for supervised jobs:

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint/ulid/uuid | Primary key (configurable) |
| `supervised_job_id` | bigint/ulid/uuid | Foreign key to supervised_jobs |
| `status` | enum | Health status (healthy, degraded, unhealthy) |
| `metadata` | json | Health check metadata |
| `created_at` | timestamp | When health check was performed |

### supervised_job_errors

Stores detailed error information for failed jobs:

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint/ulid/uuid | Primary key (configurable) |
| `supervised_job_id` | bigint/ulid/uuid | Foreign key to supervised_jobs |
| `exception` | string | Exception class name |
| `message` | text | Error message |
| `trace` | text | Full stack trace |
| `context` | json | Error context (file, line, code) |
| `created_at` | timestamp | When error was recorded |

## Your First Supervised Job

Let's create a supervised job that processes large datasets with health monitoring.

### 1. Create the Job Class

Generate a new job:

```bash
php artisan make:job ProcessLargeDataset
```

### 2. Implement the Supervised Contract

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

class ProcessLargeDataset implements ShouldQueue, Supervised
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $supervisionId;

    public function __construct(
        private int $datasetId,
    ) {
        $this->supervisionId = (string) Str::uuid();
    }

    public function handle(): void
    {
        $records = Dataset::find($this->datasetId)->records;
        $total = $records->count();

        foreach ($records as $index => $record) {
            // Process each record
            $this->processRecord($record);

            // Report progress
            $this->reportProgress($index + 1, $total);

            // Send heartbeat every 100 records
            if (($index + 1) % 100 === 0) {
                $this->heartbeat([
                    'memory' => memory_get_usage(true),
                    'processed' => $index + 1,
                ]);
            }
        }
    }

    public function heartbeat(array $metadata = []): void
    {
        // Heartbeat is automatically handled by Chaperone
    }

    public function reportProgress(int $current, int $total, array $metadata = []): void
    {
        // Progress is automatically tracked by Chaperone
    }

    public function getSupervisionId(): string
    {
        return $this->supervisionId;
    }

    private function processRecord($record): void
    {
        // Your processing logic here
    }
}
```

### 3. Dispatch the Job

```php
use App\Jobs\ProcessLargeDataset;

// Dispatch to queue
ProcessLargeDataset::dispatch($datasetId);
```

### 4. Monitor Execution

Chaperone automatically tracks the job execution. Query supervision status:

```php
use Cline\Chaperone\Database\Models\SupervisedJob;

// Get all running jobs
$running = SupervisedJob::running()->get();

// Get job by supervision ID
$job = SupervisedJob::where('job_id', $supervisionId)->first();

// Check health status
if ($job->isHealthy()) {
    echo "Job is healthy";
}

// View heartbeats
foreach ($job->heartbeats as $heartbeat) {
    echo $heartbeat->metadata['processed'] ?? 0;
}

// Check for resource violations
$violations = $job->resourceViolations;
```

## Health Monitoring

Chaperone monitors job health based on heartbeats and resource usage:

```php
use Cline\Chaperone\Events\HeartbeatMissed;
use Illuminate\Support\Facades\Event;

// Listen for missed heartbeats
Event::listen(HeartbeatMissed::class, function ($event) {
    Log::warning('Job missed heartbeat', [
        'supervision_id' => $event->supervisionId,
        'last_heartbeat' => $event->lastHeartbeatAt,
    ]);
});
```

## Resource Limits

Configure resource limits per job by adding properties:

```php
class ProcessLargeDataset implements ShouldQueue, Supervised
{
    public int $timeout = 7200; // 2 hours
    public int $memoryLimit = 1024; // 1GB
    public int $cpuLimit = 90; // 90%

    // ... rest of job
}
```

Or use job-level configuration:

```php
ProcessLargeDataset::dispatch($datasetId)
    ->setTimeout(7200)
    ->setMemoryLimit(1024)
    ->setCpuLimit(90);
```

## Circuit Breakers

Protect external services with circuit breakers:

```php
use Cline\Chaperone\Facades\CircuitBreaker;

class ProcessLargeDataset implements ShouldQueue, Supervised
{
    public function handle(): void
    {
        CircuitBreaker::for('external-api')
            ->execute(function () {
                // Call external API
                Http::post('https://api.example.com/process', $data);
            }, fallback: function () {
                // Fallback logic when circuit is open
                Log::warning('Circuit open, using fallback');
            });
    }
}
```

## Observability Integration

Enable integration with Laravel's observability tools in `.env`:

```env
CHAPERONE_PULSE_ENABLED=true
CHAPERONE_TELESCOPE_ENABLED=true
CHAPERONE_HORIZON_ENABLED=true
```

This automatically records job metrics in:
- **Laravel Pulse** - Real-time monitoring and metrics
- **Laravel Telescope** - Detailed request inspection and debugging
- **Laravel Horizon** - Queue monitoring and management

## Error Handling

Failed jobs are automatically recorded with full context:

```php
use Cline\Chaperone\Database\Models\SupervisedJob;

$job = SupervisedJob::where('job_id', $supervisionId)->first();

if ($job->failed()) {
    foreach ($job->errors as $error) {
        echo $error->exception; // Exception class
        echo $error->message;   // Error message
        echo $error->trace;     // Stack trace
        echo $error->context;   // File, line, code
    }
}
```

## Dead Letter Queue

Jobs that exceed retry limits are moved to the dead letter queue:

```php
use Cline\Chaperone\Database\Models\DeadLetterJob;

// View dead letter queue
$deadJobs = DeadLetterJob::all();

// Retry a dead letter job
$deadJob = DeadLetterJob::find($id);
$deadJob->retry();

// Purge old dead letter entries
DeadLetterJob::where('created_at', '<', now()->subDays(30))->delete();
```

## Best Practices

### 1. Always Send Heartbeats

Send heartbeats regularly to indicate job health:

```php
public function handle(): void
{
    foreach ($records as $index => $record) {
        $this->processRecord($record);

        // Heartbeat every 100 records
        if ($index % 100 === 0) {
            $this->heartbeat(['processed' => $index]);
        }
    }
}
```

### 2. Report Progress

Keep progress updated for monitoring:

```php
$this->reportProgress($current, $total, [
    'phase' => 'processing',
    'batch' => $batchNumber,
]);
```

### 3. Set Appropriate Timeouts

Configure realistic timeouts based on job complexity:

```php
// Short-lived job
public int $timeout = 300; // 5 minutes

// Long-running job
public int $timeout = 7200; // 2 hours
```

### 4. Use Circuit Breakers for External APIs

Protect against cascading failures:

```php
CircuitBreaker::for('payment-gateway')
    ->withThreshold(5)
    ->withTimeout(300)
    ->execute(fn() => PaymentGateway::charge($amount));
```

### 5. Monitor Resource Usage

Include resource metrics in heartbeats:

```php
$this->heartbeat([
    'memory' => memory_get_usage(true),
    'cpu' => sys_getloadavg()[0],
    'records_processed' => $count,
]);
```

## Next Steps

Now that you've created your first supervised job, explore:

- **[Basic Supervision](basic-supervision.md)** - Learn about supervision features and patterns
- **[Circuit Breakers](circuit-breakers.md)** - Protect external services from cascading failures
- **[Resource Limits](resource-limits.md)** - Configure and enforce resource constraints
- **[Health Monitoring](health-monitoring.md)** - Advanced health checks and recovery
- **[Events](events.md)** - Listen to supervision lifecycle events
- **[Advanced Usage](advanced-usage.md)** - Worker pools, deployment coordination, and more
