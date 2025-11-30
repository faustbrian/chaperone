# Configuration

This guide provides a complete reference for Chaperone's configuration file (`config/chaperone.php`). Every setting is documented with its purpose, default value, environment variable, code examples, and practical use cases.

## Overview

Chaperone's configuration controls:

- **Primary keys and morphs** - Database schema customization
- **Database tables** - Table name customization
- **Eloquent models** - Custom model implementations
- **Supervision settings** - Timeout, resource limits, heartbeat intervals
- **Circuit breakers** - Failure thresholds and recovery timeouts
- **Dead letter queue** - Failed job retention and cleanup
- **Resource limits** - Global resource constraints
- **Monitoring** - Integration with Laravel observability tools
- **Alerting** - Notification channels and thresholds
- **Error recording** - Error logging and payload handling
- **Queue management** - Supervised queue configuration

## Primary Keys

### primary_key_type

The type of primary key used in all Chaperone database tables.

**Default:** `'id'`

**Environment Variable:** `CHAPERONE_PRIMARY_KEY_TYPE`

**Supported Values:**
- `'id'` - Auto-incrementing integers (default)
- `'ulid'` - Universally Unique Lexicographically Sortable Identifier
- `'uuid'` - Universally Unique Identifier

**Configuration:**

```php
'primary_key_type' => env('CHAPERONE_PRIMARY_KEY_TYPE', 'id'),
```

**Environment:**

```env
CHAPERONE_PRIMARY_KEY_TYPE=ulid
```

**Use Cases:**

**Auto-incrementing IDs (Default)**
```env
CHAPERONE_PRIMARY_KEY_TYPE=id
```
- Simple, efficient for single-database applications
- Sequential ordering
- Smallest storage footprint

**ULIDs for Distributed Systems**
```env
CHAPERONE_PRIMARY_KEY_TYPE=ulid
```
- Lexicographically sortable
- Time-based ordering without coordination
- Ideal for distributed/multi-tenant systems
- Better than UUIDs for index performance

**UUIDs for Maximum Compatibility**
```env
CHAPERONE_PRIMARY_KEY_TYPE=uuid
```
- Globally unique without coordination
- Compatible with external systems expecting UUIDs
- Privacy (non-sequential)

**Example:**

```php
// Using ULIDs
use Cline\Chaperone\Database\Models\SupervisedJob;

$job = SupervisedJob::create([...]);
echo $job->id; // "01HQZXYZ123456789ABCDEFGHJ"
```

## Morph Types

### morph_type

The type of polymorphic relationship columns used for tracking job ownership and associations.

**Default:** `'morph'`

**Environment Variable:** `CHAPERONE_MORPH_TYPE`

**Supported Values:**
- `'morph'` - Standard Laravel morphs (string type + integer/string ID)
- `'uuidMorph'` - UUID-based polymorphic relationships
- `'ulidMorph'` - ULID-based polymorphic relationships
- `'numericMorph'` - Numeric polymorphic relationships

**Configuration:**

```php
'morph_type' => env('CHAPERONE_MORPH_TYPE', 'morph'),
```

**Environment:**

```env
CHAPERONE_MORPH_TYPE=ulidMorph
```

**Use Cases:**

**Standard Morphs (Default)**
```env
CHAPERONE_MORPH_TYPE=morph
```
- Works with any primary key type
- Most flexible option

**UUID Morphs**
```env
CHAPERONE_MORPH_TYPE=uuidMorph
```
- When all related models use UUID primary keys
- Consistent UUID usage across application

**ULID Morphs**
```env
CHAPERONE_MORPH_TYPE=ulidMorph
```
- When all related models use ULID primary keys
- Better index performance than UUIDs

**Numeric Morphs**
```env
CHAPERONE_MORPH_TYPE=numericMorph
```
- When related models use integer primary keys
- Optimized storage and index performance

**Example:**

```php
// Job executed by a user
use App\Models\User;
use Cline\Chaperone\Database\Models\SupervisedJob;

$user = User::find(1);
$job = SupervisedJob::where('executed_by_type', User::class)
    ->where('executed_by_id', $user->id)
    ->first();
```

### morphKeyMap

Maps model classes to their primary key column names for polymorphic relationships.

**Default:** `[]`

**Configuration:**

```php
'morphKeyMap' => [
    // App\Models\User::class => 'id',
],
```

**Use Cases:**

**Custom Primary Key Column Names**

```php
'morphKeyMap' => [
    App\Models\User::class => 'user_id',
    App\Models\Organization::class => 'org_id',
    App\Models\Team::class => 'team_id',
],
```

**Mixed Primary Key Types**

```php
'morphKeyMap' => [
    App\Models\User::class => 'uuid',
    App\Models\Organization::class => 'id',
    App\Models\Team::class => 'ulid',
],
```

**Example:**

```php
// User model with custom primary key
class User extends Model
{
    protected $primaryKey = 'user_id';
}

// Config
'morphKeyMap' => [
    App\Models\User::class => 'user_id',
],

// Chaperone will correctly use 'user_id' for polymorphic relationships
```

**Note:** Use either `morphKeyMap` OR `enforceMorphKeyMap`, not both.

### enforceMorphKeyMap

Identical to `morphKeyMap` but enables strict enforcement. Any model referenced without an explicit mapping will throw `MorphKeyViolationException`.

**Default:** `[]`

**Configuration:**

```php
'enforceMorphKeyMap' => [
    // App\Models\User::class => 'id',
],
```

**Use Cases:**

**Strict Type Safety**

```php
'enforceMorphKeyMap' => [
    App\Models\User::class => 'id',
    App\Models\Organization::class => 'id',
],

// Attempting to use unmapped model throws exception
SupervisedJob::create([
    'executed_by_type' => App\Models\Team::class, // L MorphKeyViolationException
    'executed_by_id' => 1,
]);
```

**Controlled Polymorphic Usage**

```php
// Only allow specific models to be supervisors
'enforceMorphKeyMap' => [
    App\Models\User::class => 'id',
    App\Models\System::class => 'id',
],
```

**Migration Safety**

```php
// Prevent accidental usage of unmapped models during migrations
'enforceMorphKeyMap' => [
    App\Models\User::class => 'uuid',
    App\Models\Organization::class => 'uuid',
],
```

## Eloquent Models

Customize the Eloquent models used by Chaperone. All custom models must extend Chaperone's base models.

### models.supervised_job

Model for tracking supervised jobs.

**Default:** `Cline\Chaperone\Database\Models\SupervisedJob::class`

**Configuration:**

```php
'models' => [
    'supervised_job' => SupervisedJob::class,
],
```

**Custom Implementation:**

```php
namespace App\Models\Chaperone;

use Cline\Chaperone\Database\Models\SupervisedJob as BaseModel;

class SupervisedJob extends BaseModel
{
    // Add custom methods
    public function scopeHighPriority($query)
    {
        return $query->where('queue', 'high');
    }

    // Add custom relationships
    public function owner()
    {
        return $this->morphTo('executed_by');
    }

    // Override behavior
    public function isStuck(): bool
    {
        // Custom stuck detection logic
        return $this->last_heartbeat_at < now()->subMinutes(10);
    }
}
```

**Configuration:**

```php
'models' => [
    'supervised_job' => App\Models\Chaperone\SupervisedJob::class,
],
```

### models.supervised_job_error

Model for storing job error details.

**Default:** `Cline\Chaperone\Database\Models\SupervisedJobError::class`

**Custom Implementation:**

```php
namespace App\Models\Chaperone;

use Cline\Chaperone\Database\Models\SupervisedJobError as BaseModel;

class SupervisedJobError extends BaseModel
{
    // Add custom methods
    public function isCritical(): bool
    {
        return in_array($this->exception, [
            'OutOfMemoryError',
            'DatabaseException',
        ]);
    }

    // Add custom scopes
    public function scopeCritical($query)
    {
        return $query->whereIn('exception', [
            'OutOfMemoryError',
            'DatabaseException',
        ]);
    }
}
```

### models.heartbeat

Model for storing job heartbeat signals.

**Default:** `Cline\Chaperone\Database\Models\Heartbeat::class`

### models.circuit_breaker

Model for managing circuit breaker state.

**Default:** `Cline\Chaperone\Database\Models\CircuitBreaker::class`

**Custom Implementation:**

```php
namespace App\Models\Chaperone;

use Cline\Chaperone\Database\Models\CircuitBreaker as BaseModel;

class CircuitBreaker extends BaseModel
{
    // Custom notification on state change
    protected static function booted()
    {
        static::updated(function ($breaker) {
            if ($breaker->wasChanged('state') && $breaker->state === 'open') {
                // Send alert
                Notification::send(
                    User::admins(),
                    new CircuitOpenedNotification($breaker)
                );
            }
        });
    }
}
```

### models.resource_violation

Model for logging resource limit violations.

**Default:** `Cline\Chaperone\Database\Models\ResourceViolation::class`

### models.job_health_check

Model for storing health check results.

**Default:** `Cline\Chaperone\Database\Models\JobHealthCheck::class`

## Database Tables

Customize table names used by Chaperone.

### table_names.supervised_jobs

Table storing supervised job records.

**Default:** `'supervised_jobs'`

**Environment Variable:** `CHAPERONE_JOBS_TABLE`

**Configuration:**

```php
'table_names' => [
    'supervised_jobs' => env('CHAPERONE_JOBS_TABLE', 'supervised_jobs'),
],
```

**Environment:**

```env
CHAPERONE_JOBS_TABLE=job_supervision
```

**Use Cases:**

**Schema Prefix**
```env
CHAPERONE_JOBS_TABLE=chaperone_jobs
```

**Multi-Tenant Prefixing**
```env
CHAPERONE_JOBS_TABLE=tenant_supervised_jobs
```

**Legacy System Integration**
```env
CHAPERONE_JOBS_TABLE=legacy_job_tracking
```

### table_names.supervised_job_errors

Table storing job error details.

**Default:** `'supervised_job_errors'`

**Environment Variable:** `CHAPERONE_ERRORS_TABLE`

### table_names.heartbeats

Table storing heartbeat signals.

**Default:** `'heartbeats'`

**Environment Variable:** `CHAPERONE_HEARTBEATS_TABLE`

### table_names.circuit_breakers

Table storing circuit breaker state.

**Default:** `'circuit_breakers'`

**Environment Variable:** `CHAPERONE_CIRCUIT_BREAKERS_TABLE`

### table_names.resource_violations

Table storing resource violation records.

**Default:** `'resource_violations'`

**Environment Variable:** `CHAPERONE_RESOURCE_VIOLATIONS_TABLE`

### table_names.job_health_checks

Table storing health check results.

**Default:** `'job_health_checks'`

**Environment Variable:** `CHAPERONE_JOB_HEALTH_CHECKS_TABLE`

### table_names.dead_letter_queue

Table storing permanently failed jobs.

**Default:** `'dead_letter_queue'`

**Environment Variable:** `CHAPERONE_DLQ_TABLE`

## Supervision Settings

Configure default supervision behavior for all monitored jobs.

### supervision.timeout

Maximum execution time in seconds before a job is considered stuck.

**Default:** `3600` (1 hour)

**Environment Variable:** `CHAPERONE_TIMEOUT`

**Configuration:**

```php
'supervision' => [
    'timeout' => env('CHAPERONE_TIMEOUT', 3600),
],
```

**Environment:**

```env
CHAPERONE_TIMEOUT=7200
```

**Use Cases:**

**Short-Lived Jobs**
```env
CHAPERONE_TIMEOUT=300  # 5 minutes
```
- Quick processing jobs
- API synchronization
- Email sending

**Long-Running Jobs**
```env
CHAPERONE_TIMEOUT=14400  # 4 hours
```
- Large data migrations
- Report generation
- Batch processing

**Disable Timeout**
```env
CHAPERONE_TIMEOUT=0
```
- Jobs with unpredictable duration
- Interactive jobs

**Per-Job Override:**

```php
class ProcessLargeDataset implements ShouldQueue, Supervised
{
    public int $timeout = 7200; // 2 hours

    // ... rest of job
}
```

### supervision.memory_limit

Maximum memory usage in megabytes before a job is terminated.

**Default:** `512` MB

**Environment Variable:** `CHAPERONE_MEMORY_LIMIT`

**Configuration:**

```php
'supervision' => [
    'memory_limit' => env('CHAPERONE_MEMORY_LIMIT', 512),
],
```

**Environment:**

```env
CHAPERONE_MEMORY_LIMIT=1024
```

**Use Cases:**

**Standard Jobs**
```env
CHAPERONE_MEMORY_LIMIT=256  # 256 MB
```

**Memory-Intensive Jobs**
```env
CHAPERONE_MEMORY_LIMIT=2048  # 2 GB
```
- Image processing
- PDF generation
- Large CSV processing

**Disable Memory Limit**
```env
CHAPERONE_MEMORY_LIMIT=0
```

**Per-Job Override:**

```php
class ProcessImages implements ShouldQueue, Supervised
{
    public int $memoryLimit = 2048; // 2GB

    // ... rest of job
}
```

**Example:**

```php
// Job exceeding memory limit triggers ResourceViolation
use Cline\Chaperone\Database\Models\ResourceViolation;

$violations = ResourceViolation::where('violation_type', 'memory')->get();
foreach ($violations as $violation) {
    echo "Job {$violation->supervised_job_id} exceeded {$violation->limit_value}MB";
    echo "Actual usage: {$violation->actual_value}MB";
}
```

### supervision.cpu_limit

Maximum CPU percentage a job should consume (0-100).

**Default:** `80`

**Environment Variable:** `CHAPERONE_CPU_LIMIT`

**Configuration:**

```php
'supervision' => [
    'cpu_limit' => env('CHAPERONE_CPU_LIMIT', 80),
],
```

**Environment:**

```env
CHAPERONE_CPU_LIMIT=90
```

**Use Cases:**

**Shared Servers**
```env
CHAPERONE_CPU_LIMIT=50  # Limit to 50% CPU
```
- Multi-tenant environments
- Shared hosting

**Dedicated Workers**
```env
CHAPERONE_CPU_LIMIT=95  # Allow up to 95% CPU
```
- Dedicated job servers
- High-performance processing

**Disable CPU Limit**
```env
CHAPERONE_CPU_LIMIT=0
```

**Per-Job Override:**

```php
class CpuIntensiveJob implements ShouldQueue, Supervised
{
    public int $cpuLimit = 95; // Allow up to 95% CPU

    // ... rest of job
}
```

### supervision.heartbeat_interval

Interval in seconds at which jobs should report their health status.

**Default:** `60` seconds

**Environment Variable:** `CHAPERONE_HEARTBEAT_INTERVAL`

**Configuration:**

```php
'supervision' => [
    'heartbeat_interval' => env('CHAPERONE_HEARTBEAT_INTERVAL', 60),
],
```

**Environment:**

```env
CHAPERONE_HEARTBEAT_INTERVAL=30
```

**Use Cases:**

**Quick Jobs (< 5 minutes)**
```env
CHAPERONE_HEARTBEAT_INTERVAL=10  # Every 10 seconds
```

**Medium Jobs (5-30 minutes)**
```env
CHAPERONE_HEARTBEAT_INTERVAL=60  # Every minute
```

**Long Jobs (> 30 minutes)**
```env
CHAPERONE_HEARTBEAT_INTERVAL=300  # Every 5 minutes
```

**Per-Job Override:**

```php
class QuickJob implements ShouldQueue, Supervised
{
    public int $heartbeatInterval = 10; // Heartbeat every 10 seconds

    // ... rest of job
}
```

**Example:**

```php
public function handle(): void
{
    foreach ($records as $index => $record) {
        $this->processRecord($record);

        // Manual heartbeat every 100 records
        if ($index % 100 === 0) {
            $this->heartbeat([
                'processed' => $index,
                'memory' => memory_get_usage(true),
            ]);
        }
    }
}
```

### supervision.max_retries

Maximum number of retry attempts for failed jobs before moving to dead letter queue.

**Default:** `3`

**Environment Variable:** `CHAPERONE_MAX_RETRIES`

**Configuration:**

```php
'supervision' => [
    'max_retries' => env('CHAPERONE_MAX_RETRIES', 3),
],
```

**Environment:**

```env
CHAPERONE_MAX_RETRIES=5
```

**Use Cases:**

**Critical Jobs**
```env
CHAPERONE_MAX_RETRIES=5  # Retry up to 5 times
```
- Payment processing
- Order fulfillment

**Non-Critical Jobs**
```env
CHAPERONE_MAX_RETRIES=1  # Retry once
```
- Cache warming
- Analytics collection

**No Retries**
```env
CHAPERONE_MAX_RETRIES=0
```
- One-time jobs
- Idempotent operations

### supervision.retry_delay

Base delay in seconds before retrying a failed job. Uses exponential backoff: `delay * (2 ^ attempt_number)`.

**Default:** `60` seconds

**Environment Variable:** `CHAPERONE_RETRY_DELAY`

**Configuration:**

```php
'supervision' => [
    'retry_delay' => env('CHAPERONE_RETRY_DELAY', 60),
],
```

**Environment:**

```env
CHAPERONE_RETRY_DELAY=120
```

**Backoff Calculation:**

```
Attempt 1: 60 * (2^0) = 60 seconds (1 minute)
Attempt 2: 60 * (2^1) = 120 seconds (2 minutes)
Attempt 3: 60 * (2^2) = 240 seconds (4 minutes)
Attempt 4: 60 * (2^3) = 480 seconds (8 minutes)
```

**Use Cases:**

**Quick Retry**
```env
CHAPERONE_RETRY_DELAY=30  # 30s, 60s, 120s, 240s...
```
- Transient failures
- Network hiccups

**Gradual Backoff**
```env
CHAPERONE_RETRY_DELAY=300  # 5m, 10m, 20m, 40m...
```
- External API rate limits
- Database connection pools

## Circuit Breaker Settings

Configure circuit breaker behavior for protecting external services.

### circuit_breaker.enabled

Enable or disable circuit breaker functionality globally.

**Default:** `true`

**Environment Variable:** `CHAPERONE_CIRCUIT_BREAKER_ENABLED`

**Configuration:**

```php
'circuit_breaker' => [
    'enabled' => env('CHAPERONE_CIRCUIT_BREAKER_ENABLED', true),
],
```

**Environment:**

```env
CHAPERONE_CIRCUIT_BREAKER_ENABLED=false
```

**Use Cases:**

**Production (Enabled)**
```env
CHAPERONE_CIRCUIT_BREAKER_ENABLED=true
```

**Development (Disabled)**
```env
CHAPERONE_CIRCUIT_BREAKER_ENABLED=false
```
- Testing without circuit protection
- Debugging external services

**Example:**

```php
use Cline\Chaperone\Facades\CircuitBreaker;

CircuitBreaker::for('payment-gateway')
    ->execute(function () {
        // Call payment API
        PaymentGateway::charge($amount);
    }, fallback: function () {
        // Fallback when circuit is open
        Log::warning('Payment circuit open, queuing for later');
    });
```

### circuit_breaker.failure_threshold

Number of consecutive failures before the circuit breaker trips to Open state.

**Default:** `5`

**Environment Variable:** `CHAPERONE_CIRCUIT_BREAKER_THRESHOLD`

**Configuration:**

```php
'circuit_breaker' => [
    'failure_threshold' => env('CHAPERONE_CIRCUIT_BREAKER_THRESHOLD', 5),
],
```

**Environment:**

```env
CHAPERONE_CIRCUIT_BREAKER_THRESHOLD=3
```

**Use Cases:**

**Aggressive Protection**
```env
CHAPERONE_CIRCUIT_BREAKER_THRESHOLD=3  # Open after 3 failures
```
- Critical services
- Low tolerance for failures

**Tolerant Protection**
```env
CHAPERONE_CIRCUIT_BREAKER_THRESHOLD=10  # Open after 10 failures
```
- Services with intermittent issues
- High failure tolerance

**Example:**

```php
// Circuit opens after 5 consecutive failures
for ($i = 0; $i < 5; $i++) {
    CircuitBreaker::for('api')->execute(function () {
        throw new Exception('API failure');
    });
}

// Circuit is now open
$breaker = CircuitBreaker::for('api')->getState();
echo $breaker->state; // "open"
```

### circuit_breaker.success_threshold

Number of consecutive successes in HalfOpen state before closing the circuit.

**Default:** `2`

**Environment Variable:** `CHAPERONE_CIRCUIT_BREAKER_SUCCESS_THRESHOLD`

**Configuration:**

```php
'circuit_breaker' => [
    'success_threshold' => env('CHAPERONE_CIRCUIT_BREAKER_SUCCESS_THRESHOLD', 2),
],
```

**Environment:**

```env
CHAPERONE_CIRCUIT_BREAKER_SUCCESS_THRESHOLD=3
```

**Use Cases:**

**Quick Recovery**
```env
CHAPERONE_CIRCUIT_BREAKER_SUCCESS_THRESHOLD=1
```
- Fast recovery from transient failures

**Cautious Recovery**
```env
CHAPERONE_CIRCUIT_BREAKER_SUCCESS_THRESHOLD=5
```
- Ensure stable recovery
- Critical services

### circuit_breaker.timeout

Seconds the circuit breaker remains Open before transitioning to HalfOpen state.

**Default:** `300` seconds (5 minutes)

**Environment Variable:** `CHAPERONE_CIRCUIT_BREAKER_TIMEOUT`

**Configuration:**

```php
'circuit_breaker' => [
    'timeout' => env('CHAPERONE_CIRCUIT_BREAKER_TIMEOUT', 300),
],
```

**Environment:**

```env
CHAPERONE_CIRCUIT_BREAKER_TIMEOUT=600
```

**Use Cases:**

**Quick Recovery Attempt**
```env
CHAPERONE_CIRCUIT_BREAKER_TIMEOUT=60  # Try recovery after 1 minute
```

**Extended Cooldown**
```env
CHAPERONE_CIRCUIT_BREAKER_TIMEOUT=1800  # Wait 30 minutes before retry
```
- Services with known long outages
- Rate-limited APIs

**Example:**

```php
// Circuit opens at 14:00:00
// With timeout=300, transitions to half_open at 14:05:00
// Next successful request closes the circuit
```

### circuit_breaker.half_open_attempts

Maximum number of jobs allowed to execute in HalfOpen state.

**Default:** `3`

**Environment Variable:** `CHAPERONE_CIRCUIT_BREAKER_HALF_OPEN_ATTEMPTS`

**Configuration:**

```php
'circuit_breaker' => [
    'half_open_attempts' => env('CHAPERONE_CIRCUIT_BREAKER_HALF_OPEN_ATTEMPTS', 3),
],
```

**Environment:**

```env
CHAPERONE_CIRCUIT_BREAKER_HALF_OPEN_ATTEMPTS=5
```

**Use Cases:**

**Conservative Testing**
```env
CHAPERONE_CIRCUIT_BREAKER_HALF_OPEN_ATTEMPTS=1  # Only one test request
```

**Thorough Testing**
```env
CHAPERONE_CIRCUIT_BREAKER_HALF_OPEN_ATTEMPTS=10  # Multiple test requests
```

## Dead Letter Queue

Configure handling of permanently failed jobs.

### dead_letter_queue.enabled

Enable or disable dead letter queue functionality.

**Default:** `true`

**Environment Variable:** `CHAPERONE_DLQ_ENABLED`

**Configuration:**

```php
'dead_letter_queue' => [
    'enabled' => env('CHAPERONE_DLQ_ENABLED', true),
],
```

**Environment:**

```env
CHAPERONE_DLQ_ENABLED=false
```

**Use Cases:**

**Production (Enabled)**
```env
CHAPERONE_DLQ_ENABLED=true
```
- Track permanently failed jobs
- Manual review and retry

**Development (Disabled)**
```env
CHAPERONE_DLQ_ENABLED=false
```
- Skip DLQ during testing

### dead_letter_queue.retention_period

Number of days to retain dead letter queue entries before automatic cleanup.

**Default:** `30` days

**Environment Variable:** `CHAPERONE_DLQ_RETENTION_DAYS`

**Configuration:**

```php
'dead_letter_queue' => [
    'retention_period' => env('CHAPERONE_DLQ_RETENTION_DAYS', 30),
],
```

**Environment:**

```env
CHAPERONE_DLQ_RETENTION_DAYS=90
```

**Use Cases:**

**Short Retention**
```env
CHAPERONE_DLQ_RETENTION_DAYS=7  # Keep for 1 week
```
- High job volume
- Limited storage

**Long Retention**
```env
CHAPERONE_DLQ_RETENTION_DAYS=365  # Keep for 1 year
```
- Compliance requirements
- Audit trails

**Indefinite Retention**
```env
CHAPERONE_DLQ_RETENTION_DAYS=0  # Never delete
```

### dead_letter_queue.cleanup_schedule

Cron expression for automatic cleanup of expired dead letter entries.

**Default:** `'0 2 * * *'` (daily at 2 AM)

**Environment Variable:** `CHAPERONE_DLQ_CLEANUP_SCHEDULE`

**Configuration:**

```php
'dead_letter_queue' => [
    'cleanup_schedule' => env('CHAPERONE_DLQ_CLEANUP_SCHEDULE', '0 2 * * *'),
],
```

**Environment:**

```env
CHAPERONE_DLQ_CLEANUP_SCHEDULE="0 3 * * 0"
```

**Use Cases:**

**Daily Cleanup**
```env
CHAPERONE_DLQ_CLEANUP_SCHEDULE="0 2 * * *"  # 2 AM daily
```

**Weekly Cleanup**
```env
CHAPERONE_DLQ_CLEANUP_SCHEDULE="0 3 * * 0"  # 3 AM on Sundays
```

**Hourly Cleanup**
```env
CHAPERONE_DLQ_CLEANUP_SCHEDULE="0 * * * *"  # Every hour
```

## Resource Limits

Global resource limits that apply to all supervised jobs unless overridden.

### resource_limits.disk_space_threshold

Minimum free disk space in megabytes required for job execution.

**Default:** `1024` MB (1 GB)

**Environment Variable:** `CHAPERONE_DISK_SPACE_THRESHOLD`

**Configuration:**

```php
'resource_limits' => [
    'disk_space_threshold' => env('CHAPERONE_DISK_SPACE_THRESHOLD', 1024),
],
```

**Environment:**

```env
CHAPERONE_DISK_SPACE_THRESHOLD=5120
```

**Use Cases:**

**Conservative Threshold**
```env
CHAPERONE_DISK_SPACE_THRESHOLD=5120  # Require 5 GB free
```
- Large file processing
- Database exports

**Minimal Threshold**
```env
CHAPERONE_DISK_SPACE_THRESHOLD=512  # Require 512 MB free
```
- Small jobs
- Limited storage

**Example:**

```php
// Job won't start if disk space below threshold
use Cline\Chaperone\Exceptions\InsufficientDiskSpaceException;

try {
    ProcessLargeFile::dispatch($file);
} catch (InsufficientDiskSpaceException $e) {
    Log::error('Insufficient disk space', [
        'required' => $e->getRequired(),
        'available' => $e->getAvailable(),
    ]);
}
```

### resource_limits.connection_pool_limit

Maximum number of concurrent database connections allowed.

**Default:** `10`

**Environment Variable:** `CHAPERONE_CONNECTION_POOL_LIMIT`

**Configuration:**

```php
'resource_limits' => [
    'connection_pool_limit' => env('CHAPERONE_CONNECTION_POOL_LIMIT', 10),
],
```

**Environment:**

```env
CHAPERONE_CONNECTION_POOL_LIMIT=25
```

**Use Cases:**

**Small Database Servers**
```env
CHAPERONE_CONNECTION_POOL_LIMIT=5
```

**Large Database Servers**
```env
CHAPERONE_CONNECTION_POOL_LIMIT=50
```

### resource_limits.file_descriptor_limit

Maximum number of file descriptors a job may use.

**Default:** `1024`

**Environment Variable:** `CHAPERONE_FILE_DESCRIPTOR_LIMIT`

**Configuration:**

```php
'resource_limits' => [
    'file_descriptor_limit' => env('CHAPERONE_FILE_DESCRIPTOR_LIMIT', 1024),
],
```

**Environment:**

```env
CHAPERONE_FILE_DESCRIPTOR_LIMIT=2048
```

**Use Cases:**

**Standard Jobs**
```env
CHAPERONE_FILE_DESCRIPTOR_LIMIT=1024
```

**File-Intensive Jobs**
```env
CHAPERONE_FILE_DESCRIPTOR_LIMIT=4096
```
- Bulk file processing
- Archive extraction

## Monitoring Integration

Configure integration with Laravel observability tools.

### monitoring.pulse

Enable Laravel Pulse integration for real-time monitoring.

**Default:** `false`

**Environment Variable:** `CHAPERONE_PULSE_ENABLED`

**Configuration:**

```php
'monitoring' => [
    'pulse' => env('CHAPERONE_PULSE_ENABLED', false),
],
```

**Environment:**

```env
CHAPERONE_PULSE_ENABLED=true
```

**Use Cases:**

**Production Monitoring**
```env
CHAPERONE_PULSE_ENABLED=true
```
- Real-time dashboards
- Job metrics
- Performance tracking

**Example:**

```php
// Chaperone events automatically recorded in Pulse
// View in Pulse dashboard:
// - Job execution times
// - Failure rates
// - Resource usage trends
```

### monitoring.telescope

Enable Laravel Telescope integration for debugging.

**Default:** `false`

**Environment Variable:** `CHAPERONE_TELESCOPE_ENABLED`

**Configuration:**

```php
'monitoring' => [
    'telescope' => env('CHAPERONE_TELESCOPE_ENABLED', false),
],
```

**Environment:**

```env
CHAPERONE_TELESCOPE_ENABLED=true
```

**Use Cases:**

**Development**
```env
CHAPERONE_TELESCOPE_ENABLED=true
```
- Detailed debugging
- Job inspection
- Error analysis

**Production (Disabled)**
```env
CHAPERONE_TELESCOPE_ENABLED=false
```
- Avoid performance overhead

### monitoring.horizon

Enable Laravel Horizon integration.

**Default:** `false`

**Environment Variable:** `CHAPERONE_HORIZON_ENABLED`

**Configuration:**

```php
'monitoring' => [
    'horizon' => env('CHAPERONE_HORIZON_ENABLED', false),
],
```

**Environment:**

```env
CHAPERONE_HORIZON_ENABLED=true
```

**Use Cases:**

**Production Queue Monitoring**
```env
CHAPERONE_HORIZON_ENABLED=true
```
- Enhanced queue metrics
- Supervised job visibility in Horizon
- Integration with existing Horizon setup

## Alerting Configuration

Configure notification channels and alert thresholds.

### alerting.enabled

Enable or disable alerting functionality globally.

**Default:** `true`

**Environment Variable:** `CHAPERONE_ALERTING_ENABLED`

**Configuration:**

```php
'alerting' => [
    'enabled' => env('CHAPERONE_ALERTING_ENABLED', true),
],
```

**Environment:**

```env
CHAPERONE_ALERTING_ENABLED=false
```

### alerting.channels

Notification channels to use for alerts.

**Default:** `['mail', 'slack']`

**Environment Variable:** `CHAPERONE_ALERT_CHANNELS`

**Configuration:**

```php
'alerting' => [
    'channels' => explode(',', env('CHAPERONE_ALERT_CHANNELS', 'mail,slack')),
],
```

**Environment:**

```env
CHAPERONE_ALERT_CHANNELS=mail,slack,database
```

**Supported Channels:**
- `mail` - Email notifications
- `slack` - Slack webhook
- `database` - Database notifications
- `broadcast` - Real-time broadcast
- Custom notification channels

**Use Cases:**

**Email Only**
```env
CHAPERONE_ALERT_CHANNELS=mail
```

**Multiple Channels**
```env
CHAPERONE_ALERT_CHANNELS=mail,slack,database
```

**Custom Channel**
```php
'alerting' => [
    'channels' => ['mail', 'sms', 'pagerduty'],
],
```

### alerting.slack_webhook_url

Slack webhook URL for sending alerts.

**Default:** `null`

**Environment Variable:** `CHAPERONE_SLACK_WEBHOOK_URL`

**Configuration:**

```php
'alerting' => [
    'slack_webhook_url' => env('CHAPERONE_SLACK_WEBHOOK_URL'),
],
```

**Environment:**

```env
CHAPERONE_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/YOUR/WEBHOOK/URL
```

### alerting.recipients

Email addresses or user IDs to notify when alerts are triggered.

**Default:** `[]`

**Environment Variable:** `CHAPERONE_ALERT_RECIPIENTS`

**Configuration:**

```php
'alerting' => [
    'recipients' => explode(',', env('CHAPERONE_ALERT_RECIPIENTS', '')),
],
```

**Environment:**

```env
CHAPERONE_ALERT_RECIPIENTS=admin@example.com,ops@example.com
```

**Use Cases:**

**Multiple Recipients**
```env
CHAPERONE_ALERT_RECIPIENTS=admin@example.com,ops@example.com,dev@example.com
```

**Team Channel**
```env
CHAPERONE_ALERT_RECIPIENTS=team-alerts@example.com
```

### alerting.thresholds.error_rate

Percentage of failed jobs (0-100) that triggers an alert.

**Default:** `10`

**Environment Variable:** `CHAPERONE_ALERT_ERROR_RATE`

**Configuration:**

```php
'alerting' => [
    'thresholds' => [
        'error_rate' => env('CHAPERONE_ALERT_ERROR_RATE', 10),
    ],
],
```

**Environment:**

```env
CHAPERONE_ALERT_ERROR_RATE=5
```

**Use Cases:**

**Strict Monitoring**
```env
CHAPERONE_ALERT_ERROR_RATE=5  # Alert at 5% failure rate
```

**Tolerant Monitoring**
```env
CHAPERONE_ALERT_ERROR_RATE=25  # Alert at 25% failure rate
```

**Example:**

```php
// Alert triggered when 10% of jobs fail
// In 100 jobs: 10+ failures = alert
// In 1000 jobs: 100+ failures = alert
```

### alerting.thresholds.response_time

Maximum average execution time in seconds before triggering alert.

**Default:** `300` seconds (5 minutes)

**Environment Variable:** `CHAPERONE_ALERT_RESPONSE_TIME`

**Configuration:**

```php
'alerting' => [
    'thresholds' => [
        'response_time' => env('CHAPERONE_ALERT_RESPONSE_TIME', 300),
    ],
],
```

**Environment:**

```env
CHAPERONE_ALERT_RESPONSE_TIME=600
```

**Use Cases:**

**Fast Jobs**
```env
CHAPERONE_ALERT_RESPONSE_TIME=60  # Alert if avg > 1 minute
```

**Slow Jobs**
```env
CHAPERONE_ALERT_RESPONSE_TIME=1800  # Alert if avg > 30 minutes
```

### alerting.thresholds.queue_length

Maximum number of pending jobs before triggering alert.

**Default:** `1000`

**Environment Variable:** `CHAPERONE_ALERT_QUEUE_LENGTH`

**Configuration:**

```php
'alerting' => [
    'thresholds' => [
        'queue_length' => env('CHAPERONE_ALERT_QUEUE_LENGTH', 1000),
    ],
],
```

**Environment:**

```env
CHAPERONE_ALERT_QUEUE_LENGTH=5000
```

**Use Cases:**

**Low-Volume Queues**
```env
CHAPERONE_ALERT_QUEUE_LENGTH=100
```

**High-Volume Queues**
```env
CHAPERONE_ALERT_QUEUE_LENGTH=10000
```

## Error Recording

Configure how job failures are recorded and reported.

### errors.record

Enable or disable error recording in the database.

**Default:** `true`

**Environment Variable:** `CHAPERONE_RECORD_ERRORS`

**Configuration:**

```php
'errors' => [
    'record' => env('CHAPERONE_RECORD_ERRORS', true),
],
```

**Environment:**

```env
CHAPERONE_RECORD_ERRORS=false
```

**Use Cases:**

**Production (Enabled)**
```env
CHAPERONE_RECORD_ERRORS=true
```
- Full error tracking
- Debugging capabilities

**High-Volume Systems (Disabled)**
```env
CHAPERONE_RECORD_ERRORS=false
```
- Reduce database writes
- Rely on logging only

### errors.log_channel

The log channel to use for job errors.

**Default:** `'stack'`

**Environment Variable:** `CHAPERONE_LOG_CHANNEL`

**Configuration:**

```php
'errors' => [
    'log_channel' => env('CHAPERONE_LOG_CHANNEL', 'stack'),
],
```

**Environment:**

```env
CHAPERONE_LOG_CHANNEL=jobs
```

**Use Cases:**

**Dedicated Job Log**
```env
CHAPERONE_LOG_CHANNEL=jobs
```

**Separate Error Log**
```env
CHAPERONE_LOG_CHANNEL=job-errors
```

**Example:**

```php
// config/logging.php
'channels' => [
    'jobs' => [
        'driver' => 'daily',
        'path' => storage_path('logs/jobs.log'),
        'level' => 'debug',
        'days' => 14,
    ],
],
```

### errors.include_payload

Whether to include the full job payload in error records.

**Default:** `true`

**Environment Variable:** `CHAPERONE_INCLUDE_PAYLOAD`

**Configuration:**

```php
'errors' => [
    'include_payload' => env('CHAPERONE_INCLUDE_PAYLOAD', true),
],
```

**Environment:**

```env
CHAPERONE_INCLUDE_PAYLOAD=false
```

**Use Cases:**

**Development (Include Payload)**
```env
CHAPERONE_INCLUDE_PAYLOAD=true
```
- Full context for debugging
- Reproduce failures

**Production (Exclude Payload)**
```env
CHAPERONE_INCLUDE_PAYLOAD=false
```
- Avoid storing sensitive data
- PCI/GDPR compliance
- Reduce storage

**Example:**

```php
// With include_payload=true
$error->payload; // ["user_id" => 123, "amount" => 100.00, ...]

// With include_payload=false
$error->payload; // null
```

## Queue Management

Configure which queues are supervised by Chaperone.

### queue.supervised_queues

List of queue names that should be supervised.

**Default:** `[]` (supervise all queues)

**Environment Variable:** `CHAPERONE_SUPERVISED_QUEUES`

**Configuration:**

```php
'queue' => [
    'supervised_queues' => explode(',', env('CHAPERONE_SUPERVISED_QUEUES', '')),
],
```

**Environment:**

```env
CHAPERONE_SUPERVISED_QUEUES=critical,payments,exports
```

**Use Cases:**

**All Queues (Default)**
```env
# Leave empty to supervise all queues
CHAPERONE_SUPERVISED_QUEUES=
```

**Specific Queues**
```env
CHAPERONE_SUPERVISED_QUEUES=critical,payments
```
- Only supervise critical queues
- Exclude high-volume queues

**Example:**

```php
// Only jobs on 'critical' and 'payments' queues are supervised
ProcessPayment::dispatch()->onQueue('payments'); //  Supervised
SendEmail::dispatch()->onQueue('mail'); //  Not supervised
```

### queue.excluded_queues

List of queue names that should NOT be supervised.

**Default:** `[]`

**Environment Variable:** `CHAPERONE_EXCLUDED_QUEUES`

**Configuration:**

```php
'queue' => [
    'excluded_queues' => explode(',', env('CHAPERONE_EXCLUDED_QUEUES', '')),
],
```

**Environment:**

```env
CHAPERONE_EXCLUDED_QUEUES=notifications,emails
```

**Use Cases:**

**Exclude High-Volume Queues**
```env
CHAPERONE_EXCLUDED_QUEUES=notifications,emails,cache
```
- Reduce supervision overhead
- Focus on critical queues

**Example:**

```php
// All queues supervised except 'notifications' and 'emails'
ProcessPayment::dispatch()->onQueue('payments'); //  Supervised
SendNotification::dispatch()->onQueue('notifications'); //  Not supervised
```

**Note:** Use either `supervised_queues` OR `excluded_queues`, not both.

### queue.connection

The queue connection to use for Chaperone's internal jobs.

**Default:** `null` (use default queue connection)

**Environment Variable:** `CHAPERONE_QUEUE_CONNECTION`

**Configuration:**

```php
'queue' => [
    'connection' => env('CHAPERONE_QUEUE_CONNECTION'),
],
```

**Environment:**

```env
CHAPERONE_QUEUE_CONNECTION=redis
```

**Use Cases:**

**Dedicated Connection**
```env
CHAPERONE_QUEUE_CONNECTION=chaperone
```
- Separate Chaperone jobs from application jobs
- Different Redis database

**High-Priority Connection**
```env
CHAPERONE_QUEUE_CONNECTION=high-priority
```
- Ensure Chaperone tasks are processed quickly

**Example:**

```php
// config/queue.php
'connections' => [
    'chaperone' => [
        'driver' => 'redis',
        'connection' => 'chaperone',
        'queue' => 'supervision',
    ],
],
```

## Environment Configuration Examples

### Development

```env
# Development environment
CHAPERONE_PRIMARY_KEY_TYPE=id
CHAPERONE_MORPH_TYPE=morph

# Lenient supervision
CHAPERONE_TIMEOUT=7200
CHAPERONE_MEMORY_LIMIT=1024
CHAPERONE_CPU_LIMIT=90
CHAPERONE_HEARTBEAT_INTERVAL=30

# Circuit breaker disabled
CHAPERONE_CIRCUIT_BREAKER_ENABLED=false

# Error recording enabled with payload
CHAPERONE_RECORD_ERRORS=true
CHAPERONE_INCLUDE_PAYLOAD=true

# Monitoring enabled
CHAPERONE_TELESCOPE_ENABLED=true
CHAPERONE_PULSE_ENABLED=true

# Alerting disabled
CHAPERONE_ALERTING_ENABLED=false
```

### Production

```env
# Production environment
CHAPERONE_PRIMARY_KEY_TYPE=ulid
CHAPERONE_MORPH_TYPE=ulidMorph

# Strict supervision
CHAPERONE_TIMEOUT=3600
CHAPERONE_MEMORY_LIMIT=512
CHAPERONE_CPU_LIMIT=80
CHAPERONE_HEARTBEAT_INTERVAL=60

# Circuit breaker enabled
CHAPERONE_CIRCUIT_BREAKER_ENABLED=true
CHAPERONE_CIRCUIT_BREAKER_THRESHOLD=5
CHAPERONE_CIRCUIT_BREAKER_TIMEOUT=300

# Error recording without sensitive data
CHAPERONE_RECORD_ERRORS=true
CHAPERONE_INCLUDE_PAYLOAD=false
CHAPERONE_LOG_CHANNEL=jobs

# Monitoring enabled
CHAPERONE_PULSE_ENABLED=true
CHAPERONE_HORIZON_ENABLED=true
CHAPERONE_TELESCOPE_ENABLED=false

# Alerting enabled
CHAPERONE_ALERTING_ENABLED=true
CHAPERONE_ALERT_CHANNELS=mail,slack
CHAPERONE_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/YOUR/WEBHOOK
CHAPERONE_ALERT_RECIPIENTS=ops@example.com,oncall@example.com
CHAPERONE_ALERT_ERROR_RATE=10

# Queue configuration
CHAPERONE_SUPERVISED_QUEUES=critical,payments,exports
CHAPERONE_QUEUE_CONNECTION=redis

# DLQ configuration
CHAPERONE_DLQ_ENABLED=true
CHAPERONE_DLQ_RETENTION_DAYS=90
```

### High-Volume Production

```env
# High-volume environment
CHAPERONE_PRIMARY_KEY_TYPE=ulid
CHAPERONE_MORPH_TYPE=ulidMorph

# Optimized supervision
CHAPERONE_TIMEOUT=1800
CHAPERONE_MEMORY_LIMIT=256
CHAPERONE_HEARTBEAT_INTERVAL=120

# Error recording optimized
CHAPERONE_RECORD_ERRORS=false  # Log only, no DB writes
CHAPERONE_LOG_CHANNEL=jobs

# Circuit breaker tuned
CHAPERONE_CIRCUIT_BREAKER_THRESHOLD=10
CHAPERONE_CIRCUIT_BREAKER_TIMEOUT=600

# Selective supervision
CHAPERONE_SUPERVISED_QUEUES=critical,payments
CHAPERONE_EXCLUDED_QUEUES=notifications,emails,cache

# Alert thresholds adjusted
CHAPERONE_ALERT_ERROR_RATE=25
CHAPERONE_ALERT_QUEUE_LENGTH=10000
```

## Next Steps

Now that you understand Chaperone's configuration options, explore:

- **[Artisan Commands](artisan-commands.md)** - CLI tools for monitoring and management
- **[Basic Supervision](basic-supervision.md)** - Learn about supervision features and patterns
- **[Circuit Breakers](circuit-breakers.md)** - Protect external services from cascading failures
- **[Resource Limits](resource-limits.md)** - Configure and enforce resource constraints
- **[Health Monitoring](health-monitoring.md)** - Advanced health checks and recovery
