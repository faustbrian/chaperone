# Resource Limits

Chaperone enforces resource limits to prevent jobs from consuming excessive system resources. This guide covers configuring memory, CPU, timeout, and disk limits, along with violation handling strategies.

## Understanding Resource Limits

Resource limits protect your application from:

- **Memory leaks** - Jobs consuming unbounded memory
- **CPU hogging** - Jobs monopolizing CPU resources
- **Hung processes** - Jobs running indefinitely
- **Disk exhaustion** - Jobs filling up disk space

When a job exceeds a resource limit, Chaperone:

1. Records a resource violation
2. Dispatches a violation event
3. Optionally terminates the job
4. Moves the job to failed state or dead letter queue

## Memory Limits

### Global Configuration

Set default memory limits in `config/chaperone.php`:

```php
'supervision' => [
    'memory_limit' => env('CHAPERONE_MEMORY_LIMIT', 512), // MB
],
```

Or in `.env`:

```env
CHAPERONE_MEMORY_LIMIT=512
```

### Per-Job Configuration

Override memory limits for specific jobs:

```php
class ProcessLargeDataset implements ShouldQueue, Supervised
{
    public int $memoryLimit = 1024; // 1GB

    public function handle(): void
    {
        // This job can use up to 1GB of memory
        $data = $this->loadLargeDataset();
        $this->processData($data);
    }
}
```

### Dynamic Memory Limits

Set memory limits at dispatch time:

```php
ProcessLargeDataset::dispatch($datasetId)
    ->setMemoryLimit(2048); // 2GB for this specific job
```

### Monitoring Memory Usage

Track memory consumption in heartbeats:

```php
public function handle(): void
{
    $records = $this->getRecords();

    foreach ($records as $index => $record) {
        $this->processRecord($record);

        if ($index % 100 === 0) {
            $this->heartbeat([
                'memory_mb' => memory_get_usage(true) / 1024 / 1024,
                'memory_peak_mb' => memory_get_peak_usage(true) / 1024 / 1024,
                'processed' => $index,
            ]);
        }
    }
}
```

### Memory Management Strategies

Free memory during processing:

```php
public function handle(): void
{
    User::chunk(100, function ($users) {
        foreach ($users as $user) {
            $this->processUser($user);
        }

        // Free memory after each chunk
        unset($users);
        gc_collect_cycles();

        $this->heartbeat([
            'memory_mb' => memory_get_usage(true) / 1024 / 1024,
        ]);
    });
}
```

## CPU Limits

### Global Configuration

Set default CPU limits in `config/chaperone.php`:

```php
'supervision' => [
    'cpu_limit' => env('CHAPERONE_CPU_LIMIT', 80), // 80%
],
```

### Per-Job Configuration

```php
class CpuIntensiveJob implements ShouldQueue, Supervised
{
    public int $cpuLimit = 90; // Allow up to 90% CPU

    public function handle(): void
    {
        $this->performCpuIntensiveWork();
    }
}
```

### CPU Throttling

Implement CPU throttling to stay within limits:

```php
public function handle(): void
{
    $records = $this->getRecords();

    foreach ($records as $record) {
        $this->processCpuIntensiveRecord($record);

        // Check CPU usage and throttle if needed
        if ($this->getCpuUsage() > 70) {
            usleep(100000); // Sleep 100ms to reduce CPU
        }

        $this->heartbeat([
            'cpu_usage' => $this->getCpuUsage(),
        ]);
    }
}

private function getCpuUsage(): float
{
    $load = sys_getloadavg();
    return $load[0] ?? 0;
}
```

### Parallel Processing Limits

Limit concurrent operations to control CPU:

```php
use Illuminate\Support\Facades\Bus;

class ProcessInParallel implements ShouldQueue, Supervised
{
    public function handle(): void
    {
        $batches = $this->getBatches();

        // Limit to 4 concurrent jobs to control CPU
        Bus::batch($batches)
            ->concurrency(4)
            ->dispatch();
    }
}
```

## Timeout Configuration

### Global Timeout

```php
'supervision' => [
    'timeout' => env('CHAPERONE_TIMEOUT', 3600), // 1 hour
],
```

### Per-Job Timeout

```php
class LongRunningJob implements ShouldQueue, Supervised
{
    public int $timeout = 7200; // 2 hours

    public function handle(): void
    {
        $this->heartbeat(['status' => 'started']);

        // Long-running work
        for ($i = 0; $i < 10000; $i++) {
            $this->processItem($i);

            // Send heartbeat to prevent timeout detection
            if ($i % 100 === 0) {
                $this->heartbeat(['progress' => $i]);
            }
        }
    }
}
```

### Timeout Strategies

Handle approaching timeouts gracefully:

```php
public function handle(): void
{
    $startTime = now();
    $maxDuration = $this->timeout - 300; // Stop 5 minutes before timeout

    $records = $this->getRecords();

    foreach ($records as $record) {
        // Check if approaching timeout
        if (now()->diffInSeconds($startTime) >= $maxDuration) {
            Log::warning('Approaching timeout, stopping early');
            $this->saveProgress($record->id);
            break;
        }

        $this->processRecord($record);
    }
}
```

### Resumable Jobs

Implement checkpoint/resume for long jobs:

```php
class ResumableJob implements ShouldQueue, Supervised
{
    private int $lastProcessedId = 0;

    public function handle(): void
    {
        // Load last checkpoint
        $checkpoint = Cache::get("job:{$this->getSupervisionId()}:checkpoint");
        $this->lastProcessedId = $checkpoint['last_id'] ?? 0;

        $records = Record::where('id', '>', $this->lastProcessedId)
            ->orderBy('id')
            ->cursor();

        foreach ($records as $record) {
            try {
                $this->processRecord($record);
                $this->lastProcessedId = $record->id;

                // Save checkpoint every 100 records
                if ($this->lastProcessedId % 100 === 0) {
                    $this->saveCheckpoint();
                }
            } catch (TimeoutException $e) {
                $this->saveCheckpoint();
                throw $e; // Will be retried from checkpoint
            }
        }
    }

    private function saveCheckpoint(): void
    {
        Cache::put(
            "job:{$this->getSupervisionId()}:checkpoint",
            ['last_id' => $this->lastProcessedId],
            3600
        );
    }
}
```

## Disk Space Limits

### Global Configuration

```php
'resource_limits' => [
    'disk_space_threshold' => env('CHAPERONE_DISK_SPACE_THRESHOLD', 1024), // 1GB minimum
],
```

### Checking Disk Space

Monitor available disk space before operations:

```php
class ProcessFiles implements ShouldQueue, Supervised
{
    public function handle(): void
    {
        $files = $this->getFiles();

        foreach ($files as $file) {
            // Check disk space before processing
            $freeSpace = disk_free_space('/');
            $requiredSpace = $file->size * 2; // Need 2x file size for processing

            if ($freeSpace < $requiredSpace) {
                throw new InsufficientDiskSpaceException(
                    "Need {$requiredSpace} bytes, only {$freeSpace} available"
                );
            }

            $this->processFile($file);

            // Clean up to free disk space
            $this->cleanupTempFiles();
        }
    }

    private function cleanupTempFiles(): void
    {
        $tempPath = storage_path('temp');
        $files = glob("{$tempPath}/*");

        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < time() - 3600) {
                unlink($file);
            }
        }
    }
}
```

### Disk Cleanup Strategies

Implement automatic cleanup:

```php
class ProcessWithCleanup implements ShouldQueue, Supervised
{
    private array $tempFiles = [];

    public function handle(): void
    {
        try {
            $data = $this->processData();
            $this->storeResults($data);
        } finally {
            // Always cleanup temporary files
            $this->cleanupTempFiles();
        }
    }

    private function processData(): array
    {
        $tempFile = storage_path('temp/' . Str::uuid());
        $this->tempFiles[] = $tempFile;

        // Process and return data
        return [];
    }

    private function cleanupTempFiles(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        $this->heartbeat(['cleanup' => 'completed']);
    }
}
```

## Connection Pool Limits

### Global Configuration

```php
'resource_limits' => [
    'connection_pool_limit' => env('CHAPERONE_CONNECTION_POOL_LIMIT', 10),
],
```

### Managing Database Connections

Properly manage connections in long-running jobs:

```php
class ProcessWithConnections implements ShouldQueue, Supervised
{
    public function handle(): void
    {
        $records = Record::all();

        foreach ($records as $index => $record) {
            $this->processRecord($record);

            // Reconnect every 1000 records to avoid stale connections
            if ($index % 1000 === 0) {
                DB::reconnect();
                $this->heartbeat(['reconnected' => true]);
            }
        }
    }
}
```

### Connection Pooling

Use connection pooling for external services:

```php
class BatchApiProcessor implements ShouldQueue, Supervised
{
    private array $connectionPool = [];
    private int $maxConnections = 5;

    public function handle(): void
    {
        $batches = $this->getBatches();

        foreach ($batches as $batch) {
            $connection = $this->getConnection();

            try {
                $this->processBatch($batch, $connection);
            } finally {
                $this->releaseConnection($connection);
            }
        }
    }

    private function getConnection()
    {
        if (count($this->connectionPool) < $this->maxConnections) {
            return $this->createNewConnection();
        }

        return array_shift($this->connectionPool);
    }

    private function releaseConnection($connection): void
    {
        $this->connectionPool[] = $connection;
    }
}
```

## File Descriptor Limits

### Global Configuration

```php
'resource_limits' => [
    'file_descriptor_limit' => env('CHAPERONE_FILE_DESCRIPTOR_LIMIT', 1024),
],
```

### Managing File Handles

Properly close file handles:

```php
class ProcessMultipleFiles implements ShouldQueue, Supervised
{
    public function handle(): void
    {
        $files = Storage::files('uploads');

        foreach ($files as $file) {
            $handle = fopen(Storage::path($file), 'r');

            try {
                while (($line = fgets($handle)) !== false) {
                    $this->processLine($line);
                }
            } finally {
                // Always close file handle
                fclose($handle);
            }

            $this->heartbeat([
                'processed_file' => $file,
                'open_files' => count(get_resources('stream')),
            ]);
        }
    }
}
```

## Resource Violation Handling

### Listening to Violation Events

```php
use Cline\Chaperone\Events\ResourceViolationDetected;
use Cline\Chaperone\Events\JobMemoryExceeded;
use Cline\Chaperone\Events\JobCpuExceeded;
use Cline\Chaperone\Events\JobTimeout;
use Illuminate\Support\Facades\Event;

Event::listen(ResourceViolationDetected::class, function ($event) {
    Log::warning('Resource violation detected', [
        'job_id' => $event->supervisionId,
        'type' => $event->type,
        'limit' => $event->limit,
        'actual' => $event->actual,
    ]);
});

Event::listen(JobMemoryExceeded::class, function ($event) {
    Log::critical('Job exceeded memory limit', [
        'job_id' => $event->supervisionId,
        'limit_mb' => $event->limit,
        'usage_mb' => $event->actual,
    ]);

    // Alert operations team
    Alert::send("Memory limit exceeded: {$event->supervisionId}");
});

Event::listen(JobCpuExceeded::class, function ($event) {
    Log::warning('Job exceeded CPU limit', [
        'job_id' => $event->supervisionId,
        'limit_percent' => $event->limit,
        'usage_percent' => $event->actual,
    ]);
});

Event::listen(JobTimeout::class, function ($event) {
    Log::error('Job timed out', [
        'job_id' => $event->supervisionId,
        'timeout_seconds' => $event->timeout,
        'duration_seconds' => $event->duration,
    ]);
});
```

### Querying Violations

```php
use Cline\Chaperone\Database\Models\ResourceViolation;
use Cline\Chaperone\Database\Models\SupervisedJob;

// Get all violations
$violations = ResourceViolation::all();

// Get violations by type
$memoryViolations = ResourceViolation::where('type', 'memory')->get();
$cpuViolations = ResourceViolation::where('type', 'cpu')->get();
$timeoutViolations = ResourceViolation::where('type', 'timeout')->get();

// Get violations for specific job
$job = SupervisedJob::where('job_id', $supervisionId)->first();
$jobViolations = $job->resourceViolations;

// Get recent violations
$recentViolations = ResourceViolation::where('created_at', '>', now()->subHour())
    ->get();

// Get jobs with violations
$jobsWithViolations = SupervisedJob::has('resourceViolations')->get();
```

### Violation Dashboards

Create monitoring dashboards:

```php
class ResourceViolationController extends Controller
{
    public function index()
    {
        $violations = ResourceViolation::with('supervisedJob')
            ->latest()
            ->paginate(50);

        $stats = [
            'total' => ResourceViolation::count(),
            'memory' => ResourceViolation::where('type', 'memory')->count(),
            'cpu' => ResourceViolation::where('type', 'cpu')->count(),
            'timeout' => ResourceViolation::where('type', 'timeout')->count(),
        ];

        return view('violations.index', [
            'violations' => $violations,
            'stats' => $stats,
        ]);
    }

    public function show($id)
    {
        $violation = ResourceViolation::with('supervisedJob')->findOrFail($id);

        return view('violations.show', [
            'violation' => $violation,
            'job' => $violation->supervisedJob,
        ]);
    }
}
```

## Best Practices

### 1. Set Realistic Limits

```php
// Short-lived jobs
class QuickJob implements ShouldQueue, Supervised
{
    public int $timeout = 300;      // 5 minutes
    public int $memoryLimit = 128;  // 128MB
    public int $cpuLimit = 50;      // 50%
}

// Long-running jobs
class LongJob implements ShouldQueue, Supervised
{
    public int $timeout = 7200;     // 2 hours
    public int $memoryLimit = 1024; // 1GB
    public int $cpuLimit = 80;      // 80%
}

// Resource-intensive jobs
class IntensiveJob implements ShouldQueue, Supervised
{
    public int $timeout = 3600;     // 1 hour
    public int $memoryLimit = 2048; // 2GB
    public int $cpuLimit = 90;      // 90%
}
```

### 2. Monitor Resource Usage

```php
public function handle(): void
{
    $records = $this->getRecords();

    foreach ($records as $index => $record) {
        $this->processRecord($record);

        // Regular resource monitoring
        if ($index % 100 === 0) {
            $this->heartbeat([
                'memory_mb' => memory_get_usage(true) / 1024 / 1024,
                'peak_memory_mb' => memory_get_peak_usage(true) / 1024 / 1024,
                'cpu_load' => sys_getloadavg()[0],
                'processed' => $index,
            ]);
        }
    }
}
```

### 3. Implement Graceful Degradation

```php
public function handle(): void
{
    $batchSize = 100;
    $records = $this->getRecords();

    foreach ($records->chunk($batchSize) as $chunk) {
        // Check memory before processing
        $memoryMb = memory_get_usage(true) / 1024 / 1024;

        if ($memoryMb > $this->memoryLimit * 0.8) {
            // Approaching limit, reduce batch size
            $batchSize = max(10, $batchSize / 2);
            gc_collect_cycles();

            $this->heartbeat([
                'warning' => 'reduced_batch_size',
                'new_batch_size' => $batchSize,
            ]);
        }

        $this->processChunk($chunk);
    }
}
```

### 4. Clean Up Resources

```php
public function handle(): void
{
    try {
        $this->processData();
    } finally {
        // Always cleanup
        $this->cleanupTempFiles();
        $this->closeConnections();
        gc_collect_cycles();

        $this->heartbeat(['cleanup' => 'completed']);
    }
}
```

### 5. Use Checkpoints for Long Jobs

```php
public function handle(): void
{
    $checkpoint = $this->loadCheckpoint();
    $records = Record::where('id', '>', $checkpoint)->cursor();

    foreach ($records as $record) {
        $this->processRecord($record);

        // Save checkpoint every 100 records
        if ($record->id % 100 === 0) {
            $this->saveCheckpoint($record->id);
        }
    }
}
```

## Testing Resource Limits

Test resource limit enforcement:

```php
use Tests\TestCase;
use App\Jobs\ResourceIntensiveJob;
use Cline\Chaperone\Database\Models\SupervisedJob;
use Cline\Chaperone\Database\Models\ResourceViolation;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ResourceLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_records_memory_violation(): void
    {
        $job = new ResourceIntensiveJob();
        $job->memoryLimit = 1; // Very low limit

        try {
            $job->handle();
        } catch (\Exception $e) {
            // Expected
        }

        $supervisedJob = SupervisedJob::where('job_id', $job->getSupervisionId())->first();
        $violations = $supervisedJob->resourceViolations;

        $this->assertTrue($violations->contains('type', 'memory'));
    }

    public function test_job_stops_before_timeout(): void
    {
        $job = new ResourceIntensiveJob();
        $job->timeout = 5; // 5 seconds

        $startTime = now();
        $job->handle();
        $duration = now()->diffInSeconds($startTime);

        $this->assertLessThan(5, $duration);
    }
}
```

## Next Steps

- **[Health Monitoring](health-monitoring.md)** - Advanced health checks and recovery
- **[Events](events.md)** - Complete event reference and listeners
- **[Advanced Usage](advanced-usage.md)** - Worker pools and deployment coordination
- **[Configuration](configuration.md)** - Complete configuration reference
- **[Artisan Commands](artisan-commands.md)** - Available commands and usage
