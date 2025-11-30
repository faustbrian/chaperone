# Worker Pool Supervision

Chaperone's Worker Pool Supervision provides robust management of multiple queue workers with automatic health monitoring, crash recovery, and pool-wide coordination. This guide covers creating worker pools, configuring health checks, implementing crash handlers, and deploying supervised worker fleets in production.

## Introduction to Worker Pools

Worker pools allow you to supervise multiple queue workers as a coordinated unit. Instead of managing individual worker processes, you define pools that automatically spawn, monitor, and restart workers to ensure continuous queue processing.

### Why Use Worker Pools?

- **Automatic Recovery** - Crashed workers are automatically replaced
- **Health Monitoring** - Built-in health checks detect and restart unhealthy workers
- **Resource Optimization** - Scale worker count based on queue depth
- **Simplified Management** - Manage worker fleets as logical units
- **Production Ready** - Battle-tested crash handling and restart logic

### Core Components

**WorkerPoolSupervisor** - Manages a pool of workers, monitors health, restarts failures
**Worker** - Individual queue worker process with health status and metrics
**WorkerPoolRegistry** - Central registry for managing multiple pools

## Creating Your First Worker Pool

### Basic Pool Setup

Create a worker pool to process a specific queue:

```php
<?php

use Cline\Chaperone\WorkerPools\WorkerPoolSupervisor;

// Create a pool with 5 workers for the 'emails' queue
$pool = new WorkerPoolSupervisor('email-workers');
$pool
    ->workers(5)
    ->queue('emails')
    ->supervise();
```

This creates a pool named `email-workers` with 5 concurrent workers processing the `emails` queue. The `supervise()` method starts the supervision loop, which continuously monitors worker health and restarts failed workers.

### Named Pools

Use descriptive names to organize pools by function:

```php
// Pool for processing imports
$importPool = new WorkerPoolSupervisor('import-workers');
$importPool
    ->workers(3)
    ->queue('imports')
    ->supervise();

// Pool for generating reports
$reportPool = new WorkerPoolSupervisor('report-workers');
$reportPool
    ->workers(2)
    ->queue('reports')
    ->supervise();

// Pool for sending notifications
$notificationPool = new WorkerPoolSupervisor('notification-workers');
$notificationPool
    ->workers(10)
    ->queue('notifications')
    ->supervise();
```

## Configuring Worker Pools

### Setting Worker Count

Configure the number of concurrent workers based on workload:

```php
// Light workload - 1-2 workers
$pool = new WorkerPoolSupervisor('maintenance-workers');
$pool->workers(1)->queue('maintenance');

// Medium workload - 5-10 workers
$pool = new WorkerPoolSupervisor('processing-workers');
$pool->workers(5)->queue('processing');

// Heavy workload - 10+ workers
$pool = new WorkerPoolSupervisor('high-volume-workers');
$pool->workers(20)->queue('high-volume');
```

### Queue Assignment

Assign pools to specific queues for targeted processing:

```php
// Process high-priority queue
$pool = new WorkerPoolSupervisor('priority-workers');
$pool
    ->workers(10)
    ->queue('high-priority')
    ->supervise();

// Process default queue
$pool = new WorkerPoolSupervisor('default-workers');
$pool
    ->workers(5)
    ->queue('default')
    ->supervise();

// Process multiple specialized queues
$pools = [
    'emails' => 5,
    'webhooks' => 3,
    'analytics' => 2,
];

foreach ($pools as $queue => $count) {
    $pool = new WorkerPoolSupervisor("{$queue}-workers");
    $pool->workers($count)->queue($queue)->supervise();
}
```

## Health Checks and Monitoring

### Default Health Checks

Workers are automatically monitored with default health checks:

```php
$pool = new WorkerPoolSupervisor('default-workers');
$pool
    ->workers(5)
    ->queue('default')
    ->supervise();

// Default health checks:
// - Process is responsive (responds to signals)
// - Memory usage under 512MB
// - Worker hasn't crashed
```

Default health checks run every second and verify:
1. Worker process is running
2. Process responds to signals
3. Memory usage is below threshold

### Custom Health Checks

Implement custom health logic with the `withHealthCheck` callback:

```php
$pool = new WorkerPoolSupervisor('api-workers');
$pool
    ->workers(5)
    ->queue('api-calls')
    ->withHealthCheck(function ($worker) {
        // Check if process is responsive
        if (!$worker->isResponsive()) {
            return false;
        }

        // Check memory usage (in MB)
        if ($worker->memoryUsage() > 1024) {
            return false;
        }

        // Check worker uptime - restart if running too long
        $uptime = now()->diffInMinutes($worker->startedAt);
        if ($uptime > 60) {
            return false;
        }

        return true;
    })
    ->supervise();
```

### Advanced Health Checks

Implement complex health verification:

```php
$pool = new WorkerPoolSupervisor('database-workers');
$pool
    ->workers(3)
    ->queue('database-imports')
    ->withHealthCheck(function ($worker) use ($logger, $metrics) {
        // Basic responsiveness check
        if (!$worker->isResponsive()) {
            $logger->error("Worker {$worker->id} not responsive");
            return false;
        }

        $memory = $worker->memoryUsage();
        $uptime = now()->diffInMinutes($worker->startedAt);

        // Track metrics
        $metrics->gauge("worker.{$worker->id}.memory", $memory);
        $metrics->gauge("worker.{$worker->id}.uptime", $uptime);

        // Memory threshold with logging
        if ($memory > 768) {
            $logger->warning("Worker {$worker->id} high memory: {$memory}MB");

            if ($memory > 1024) {
                $logger->error("Worker {$worker->id} exceeded memory limit");
                return false;
            }
        }

        // Restart workers periodically to prevent memory leaks
        if ($uptime > 120) {
            $logger->info("Worker {$worker->id} reached uptime limit: {$uptime} minutes");
            return false;
        }

        // Check database connectivity
        try {
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            $logger->error("Worker {$worker->id} database connection failed", [
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        return true;
    })
    ->supervise();
```

### Health Check Patterns

Common health check patterns for different scenarios:

```php
// Pattern 1: Memory-intensive jobs
->withHealthCheck(function ($worker) {
    return $worker->isResponsive()
        && $worker->memoryUsage() < 2048; // 2GB limit
})

// Pattern 2: Time-based rotation
->withHealthCheck(function ($worker) {
    $uptime = now()->diffInHours($worker->startedAt);
    return $worker->isResponsive()
        && $uptime < 4; // Restart every 4 hours
})

// Pattern 3: Combined checks with logging
->withHealthCheck(function ($worker) {
    $healthy = $worker->isResponsive()
        && $worker->memoryUsage() < 1024
        && now()->diffInMinutes($worker->startedAt) < 60;

    if (!$healthy) {
        Log::warning("Worker {$worker->id} failed health check", [
            'responsive' => $worker->isResponsive(),
            'memory_mb' => $worker->memoryUsage(),
            'uptime_min' => now()->diffInMinutes($worker->startedAt),
        ]);
    }

    return $healthy;
})
```

## Crash Handling and Auto-Restart

### Automatic Crash Recovery

Workers that crash are automatically detected and replaced:

```php
$pool = new WorkerPoolSupervisor('resilient-workers');
$pool
    ->workers(5)
    ->queue('processing')
    ->supervise();

// When a worker crashes:
// 1. Crash is detected in health check loop
// 2. onCrash callback is invoked (if configured)
// 3. Crashed worker is removed from pool
// 4. New worker is spawned to replace it
// 5. Pool maintains configured worker count
```

### Crash Callbacks

Handle crashes with custom logic using `onCrash`:

```php
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use App\Notifications\WorkerCrashedAlert;

$pool = new WorkerPoolSupervisor('monitored-workers');
$pool
    ->workers(5)
    ->queue('critical')
    ->onCrash(function ($worker) {
        // Log crash details
        Log::error('Worker crashed', [
            'worker_id' => $worker->id,
            'queue' => $worker->queue,
            'pid' => $worker->pid,
            'uptime' => now()->diffInMinutes($worker->startedAt),
            'memory_usage' => $worker->memoryUsage(),
            'last_health_check' => $worker->lastHealthCheck?->toDateTimeString(),
        ]);

        // Send alert notification
        Notification::send(
            User::admins(),
            new WorkerCrashedAlert($worker)
        );
    })
    ->supervise();
```

### Crash Analytics

Track crash patterns and metrics:

```php
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

$pool = new WorkerPoolSupervisor('analytics-workers');
$pool
    ->workers(10)
    ->queue('analytics')
    ->onCrash(function ($worker) use ($pool) {
        $crashKey = "crashes:{$pool->getName()}:count";
        $crashes = Cache::increment($crashKey);

        // Track crash metrics
        Metrics::increment('worker.crashes', [
            'pool' => $pool->getName(),
            'queue' => $worker->queue,
        ]);

        // Alert if crash rate is high
        if ($crashes > 10) {
            Http::post(config('slack.webhook_url'), [
                'text' => "High crash rate detected in {$pool->getName()}",
                'attachments' => [
                    [
                        'color' => 'danger',
                        'fields' => [
                            ['title' => 'Pool', 'value' => $pool->getName()],
                            ['title' => 'Crashes', 'value' => $crashes],
                            ['title' => 'Worker', 'value' => $worker->id],
                        ],
                    ],
                ],
            ]);

            // Reset counter after alert
            Cache::put($crashKey, 0, now()->addHour());
        }

        // Log crash details
        Log::error("Worker {$worker->id} crashed", [
            'pool' => $pool->getName(),
            'total_crashes' => $crashes,
            'memory' => $worker->memoryUsage(),
            'uptime' => now()->diffInSeconds($worker->startedAt),
        ]);
    })
    ->supervise();
```

### Crash Recovery Strategies

Implement sophisticated recovery logic:

```php
$pool = new WorkerPoolSupervisor('smart-workers');
$pool
    ->workers(5)
    ->queue('processing')
    ->onCrash(function ($worker) use ($pool) {
        $uptime = now()->diffInSeconds($worker->startedAt);

        // Quick crash detection (< 30 seconds)
        if ($uptime < 30) {
            Log::critical("Worker {$worker->id} crashed immediately after start", [
                'uptime' => $uptime,
                'pool' => $pool->getName(),
            ]);

            // Check for configuration issues
            if ($this->hasConfigurationError($pool->getName())) {
                Log::alert("Stopping pool {$pool->getName()} due to configuration errors");
                $pool->stop();
                return;
            }
        }

        // Log normal crash
        Log::warning("Worker {$worker->id} crashed", [
            'uptime' => $uptime,
            'memory' => $worker->memoryUsage(),
            'pool' => $pool->getName(),
        ]);

        // Track crash history
        $this->recordCrash($pool->getName(), $worker);

        // Analyze crash patterns
        if ($this->hasCrashPattern($pool->getName())) {
            Log::alert("Crash pattern detected in {$pool->getName()}");
            $this->alertOpsTeam($pool->getName());
        }
    })
    ->supervise();

private function hasConfigurationError(string $poolName): bool
{
    $crashes = Cache::get("crashes:{$poolName}:immediate", 0);
    return $crashes > 3;
}

private function recordCrash(string $poolName, $worker): void
{
    $key = "crashes:{$poolName}:history";
    $history = Cache::get($key, []);

    $history[] = [
        'worker_id' => $worker->id,
        'timestamp' => now()->timestamp,
        'uptime' => now()->diffInSeconds($worker->startedAt),
        'memory' => $worker->memoryUsage(),
    ];

    // Keep last 50 crashes
    if (count($history) > 50) {
        array_shift($history);
    }

    Cache::put($key, $history, now()->addDay());
}

private function hasCrashPattern(string $poolName): bool
{
    $history = Cache::get("crashes:{$poolName}:history", []);

    // Pattern: 5 crashes in 5 minutes
    $recentCrashes = collect($history)
        ->filter(fn($crash) => $crash['timestamp'] > now()->subMinutes(5)->timestamp)
        ->count();

    return $recentCrashes >= 5;
}
```

## Unhealthy Worker Callbacks

### Custom Unhealthy Handlers

Define custom behavior when workers become unhealthy:

```php
$pool = new WorkerPoolSupervisor('monitored-workers');
$pool
    ->workers(5)
    ->queue('processing')
    ->onUnhealthy(function ($worker) {
        Log::warning("Worker {$worker->id} is unhealthy", [
            'memory' => $worker->memoryUsage(),
            'uptime' => now()->diffInMinutes($worker->startedAt),
            'status' => $worker->status,
        ]);

        // Custom recovery logic
        if ($worker->memoryUsage() > 1024) {
            Log::info("Restarting {$worker->id} due to high memory");
            $worker->restart();
        } elseif ($worker->status === 'degraded') {
            Log::info("Allowing {$worker->id} time to recover");
            // Don't restart immediately, give it time
        } else {
            Log::info("Restarting {$worker->id} - general unhealthy state");
            $worker->restart();
        }
    })
    ->supervise();
```

### Default Unhealthy Behavior

If no `onUnhealthy` callback is provided, workers are automatically restarted:

```php
// Without onUnhealthy - automatic restart
$pool = new WorkerPoolSupervisor('auto-restart-workers');
$pool
    ->workers(5)
    ->queue('processing')
    ->supervise();

// With onUnhealthy - custom behavior
$pool = new WorkerPoolSupervisor('custom-workers');
$pool
    ->workers(5)
    ->queue('processing')
    ->onUnhealthy(function ($worker) {
        // Your custom logic instead of auto-restart
        $this->handleUnhealthyWorker($worker);
    })
    ->supervise();
```

### Gradual Recovery

Implement gradual recovery for transient issues:

```php
use Illuminate\Support\Facades\Cache;

$pool = new WorkerPoolSupervisor('patient-workers');
$pool
    ->workers(5)
    ->queue('processing')
    ->onUnhealthy(function ($worker) {
        $cacheKey = "worker:{$worker->id}:unhealthy_count";
        $unhealthyCount = Cache::increment($cacheKey);

        // Set expiry for cache key
        Cache::put($cacheKey, $unhealthyCount, now()->addMinutes(10));

        Log::warning("Worker {$worker->id} unhealthy check #{$unhealthyCount}");

        // Progressive response
        if ($unhealthyCount === 1) {
            // First failure - log only
            Log::info("Worker {$worker->id} first unhealthy check, monitoring");
        } elseif ($unhealthyCount === 2) {
            // Second failure - log warning
            Log::warning("Worker {$worker->id} second unhealthy check, still monitoring");
        } elseif ($unhealthyCount >= 3) {
            // Third failure - restart
            Log::error("Worker {$worker->id} repeatedly unhealthy, restarting");
            Cache::forget($cacheKey);
            $worker->restart();
        }
    })
    ->supervise();
```

## Worker Pool Registry

### Registering Pools

Use the registry to manage multiple pools centrally:

```php
use Cline\Chaperone\WorkerPools\WorkerPoolRegistry;

$registry = new WorkerPoolRegistry();

// Create and register pools
$emailPool = new WorkerPoolSupervisor('email-workers');
$emailPool->workers(5)->queue('emails');
$registry->register('emails', $emailPool);

$importPool = new WorkerPoolSupervisor('import-workers');
$importPool->workers(3)->queue('imports');
$registry->register('imports', $importPool);

$reportPool = new WorkerPoolSupervisor('report-workers');
$reportPool->workers(2)->queue('reports');
$registry->register('reports', $reportPool);
```

### Accessing Registered Pools

Retrieve and manage pools through the registry:

```php
// Get specific pool
$emailPool = $registry->get('emails');
if ($emailPool) {
    $status = $emailPool->getStatus();
    echo "Pool has {$status['worker_count']} workers";
}

// Check if pool exists
if ($registry->has('emails')) {
    echo "Email pool is registered";
}

// Get all pools
$allPools = $registry->all();
foreach ($allPools as $name => $pool) {
    echo "Pool {$name}: {$pool->getName()}\n";
}
```

### Stopping Pools

Gracefully stop pools individually or all at once:

```php
// Stop specific pool
$registry->stop('emails');

// Stop all pools
$registry->stopAll();

// Stop with cleanup
foreach ($registry->all() as $name => $pool) {
    Log::info("Stopping pool: {$name}");
    $registry->stop($name);
}
```

### Dynamic Pool Management

Add and remove pools at runtime:

```php
use Cline\Chaperone\WorkerPools\WorkerPoolRegistry;

class PoolManager
{
    public function __construct(
        private WorkerPoolRegistry $registry,
    ) {}

    public function ensurePool(string $queue, int $workers): void
    {
        if (!$this->registry->has($queue)) {
            $pool = new WorkerPoolSupervisor("{$queue}-workers");
            $pool->workers($workers)->queue($queue);

            $this->registry->register($queue, $pool);

            Log::info("Created pool for queue: {$queue}");
        }
    }

    public function scalePool(string $queue, int $newWorkerCount): void
    {
        $pool = $this->registry->get($queue);

        if ($pool) {
            // Stop existing pool
            $this->registry->stop($queue);

            // Create new pool with updated count
            $newPool = new WorkerPoolSupervisor("{$queue}-workers");
            $newPool->workers($newWorkerCount)->queue($queue);

            $this->registry->register($queue, $newPool);

            Log::info("Scaled {$queue} pool to {$newWorkerCount} workers");
        }
    }

    public function removePool(string $queue): void
    {
        $this->registry->stop($queue);
        Log::info("Removed pool: {$queue}");
    }
}
```

## CLI Commands

### Viewing Worker Status

Use the `chaperone:workers` command to monitor pools:

```bash
# Show all worker pools
php artisan chaperone:workers

# Show specific pool
php artisan chaperone:workers email-workers

# JSON output for monitoring tools
php artisan chaperone:workers --format=json

# JSON output for specific pool
php artisan chaperone:workers email-workers --format=json
```

### Command Output

Table format output:

```bash
$ php artisan chaperone:workers email-workers

Worker Pool: email-workers
+-------------------------+-------+---------+---------------------+-------------+
| ID                      | PID   | Status  | Started At          | Memory (MB) |
+-------------------------+-------+---------+---------------------+-------------+
| email-workers-1a2b3c    | 12345 | running | 2025-11-23 10:00:00 | 128         |
| email-workers-2b3c4d    | 12346 | running | 2025-11-23 10:00:00 | 132         |
| email-workers-3c4d5e    | 12347 | running | 2025-11-23 10:00:01 | 125         |
| email-workers-4d5e6f    | 12348 | running | 2025-11-23 10:00:01 | 130         |
| email-workers-5e6f7g    | 12349 | running | 2025-11-23 10:00:01 | 127         |
+-------------------------+-------+---------+---------------------+-------------+
```

JSON format output:

```json
{
  "name": "email-workers",
  "worker_count": 5,
  "workers": [
    {
      "id": "email-workers-1a2b3c",
      "pid": 12345,
      "status": "running",
      "started_at": "2025-11-23 10:00:00",
      "last_health_check": "2025-11-23 10:15:30",
      "memory_usage": 128
    },
    {
      "id": "email-workers-2b3c4d",
      "pid": 12346,
      "status": "running",
      "started_at": "2025-11-23 10:00:00",
      "last_health_check": "2025-11-23 10:15:30",
      "memory_usage": 132
    }
  ]
}
```

### Monitoring with CLI

Create custom monitoring commands:

```php
<?php

namespace App\Console\Commands;

use Cline\Chaperone\WorkerPools\WorkerPoolRegistry;
use Illuminate\Console\Command;

class MonitorWorkersCommand extends Command
{
    protected $signature = 'workers:monitor
                            {--interval=5 : Check interval in seconds}
                            {--pool= : Monitor specific pool}';

    protected $description = 'Monitor worker pool health in real-time';

    public function handle(WorkerPoolRegistry $registry): int
    {
        $interval = (int) $this->option('interval');
        $poolName = $this->option('pool');

        $this->info('Starting worker pool monitor...');
        $this->info("Checking every {$interval} seconds");
        $this->newLine();

        while (true) {
            $this->displayStatus($registry, $poolName);
            sleep($interval);
        }

        return self::SUCCESS;
    }

    private function displayStatus(WorkerPoolRegistry $registry, ?string $poolName): void
    {
        $this->output->write("\033[2J\033[;H"); // Clear screen
        $this->info('Worker Pool Status - ' . now()->toDateTimeString());
        $this->newLine();

        $pools = $poolName
            ? [$registry->get($poolName)]
            : $registry->all()->values()->all();

        foreach ($pools as $pool) {
            $status = $pool->getStatus();

            $this->line("Pool: {$status['name']}");
            $this->line("Workers: {$status['worker_count']}");

            $workers = collect($status['workers']);
            $running = $workers->where('status', 'running')->count();
            $stopped = $workers->where('status', 'stopped')->count();
            $crashed = $workers->where('status', 'crashed')->count();

            $this->line("  Running: {$running}");
            $this->line("  Stopped: {$stopped}");
            $this->line("  Crashed: {$crashed}");

            $avgMemory = $workers->avg('memory_usage');
            $this->line("  Avg Memory: " . round($avgMemory) . "MB");

            $this->newLine();
        }
    }
}
```

## Production Deployment Patterns

### Systemd Service

Deploy worker pools as systemd services:

```ini
# /etc/systemd/system/worker-pool-emails.service
[Unit]
Description=Chaperone Worker Pool - Emails
After=network.target mysql.service redis.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/app
ExecStart=/usr/bin/php artisan pool:supervise emails --workers=5
Restart=always
RestartSec=10
StandardOutput=append:/var/log/worker-pool-emails.log
StandardError=append:/var/log/worker-pool-emails-error.log

[Install]
WantedBy=multi-user.target
```

Create the corresponding Artisan command:

```php
<?php

namespace App\Console\Commands;

use Cline\Chaperone\WorkerPools\WorkerPoolSupervisor;
use Illuminate\Console\Command;

class SupervisePoolCommand extends Command
{
    protected $signature = 'pool:supervise
                            {queue : Queue name to process}
                            {--workers=5 : Number of workers}';

    protected $description = 'Start supervised worker pool';

    public function handle(): int
    {
        $queue = $this->argument('queue');
        $workers = (int) $this->option('workers');

        $this->info("Starting {$workers} workers for queue: {$queue}");

        $pool = new WorkerPoolSupervisor("{$queue}-workers");
        $pool
            ->workers($workers)
            ->queue($queue)
            ->withHealthCheck(fn($w) => $w->isResponsive() && $w->memoryUsage() < 1024)
            ->onCrash(function ($worker) use ($queue) {
                Log::error("Worker crashed in {$queue} pool", [
                    'worker' => $worker->id,
                    'memory' => $worker->memoryUsage(),
                ]);
            })
            ->onUnhealthy(function ($worker) use ($queue) {
                Log::warning("Unhealthy worker in {$queue} pool", [
                    'worker' => $worker->id,
                ]);
                $worker->restart();
            })
            ->supervise();

        return self::SUCCESS;
    }
}
```

Enable and start the service:

```bash
# Enable service
sudo systemctl enable worker-pool-emails

# Start service
sudo systemctl start worker-pool-emails

# Check status
sudo systemctl status worker-pool-emails

# View logs
sudo journalctl -u worker-pool-emails -f
```

### Docker Deployment

Deploy worker pools in Docker containers:

```dockerfile
# Dockerfile.worker-pool
FROM php:8.2-cli

# Install dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    && docker-php-ext-install zip pdo_mysql pcntl

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy application
COPY . .

# Install dependencies
RUN composer install --no-dev --optimize-autoloader

# Entry point
CMD ["php", "artisan", "pool:supervise", "${QUEUE_NAME}", "--workers=${WORKER_COUNT}"]
```

Docker Compose configuration:

```yaml
# docker-compose.yml
version: '3.8'

services:
  worker-pool-emails:
    build:
      context: .
      dockerfile: Dockerfile.worker-pool
    environment:
      QUEUE_NAME: emails
      WORKER_COUNT: 5
      DB_HOST: mysql
      REDIS_HOST: redis
    restart: unless-stopped
    depends_on:
      - mysql
      - redis

  worker-pool-imports:
    build:
      context: .
      dockerfile: Dockerfile.worker-pool
    environment:
      QUEUE_NAME: imports
      WORKER_COUNT: 3
      DB_HOST: mysql
      REDIS_HOST: redis
    restart: unless-stopped
    depends_on:
      - mysql
      - redis

  worker-pool-reports:
    build:
      context: .
      dockerfile: Dockerfile.worker-pool
    environment:
      QUEUE_NAME: reports
      WORKER_COUNT: 2
      DB_HOST: mysql
      REDIS_HOST: redis
    restart: unless-stopped
    depends_on:
      - mysql
      - redis
```

### Kubernetes Deployment

Deploy as Kubernetes Deployments:

```yaml
# k8s/worker-pool-emails.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: worker-pool-emails
  labels:
    app: worker-pool
    queue: emails
spec:
  replicas: 1
  selector:
    matchLabels:
      app: worker-pool
      queue: emails
  template:
    metadata:
      labels:
        app: worker-pool
        queue: emails
    spec:
      containers:
      - name: worker-pool
        image: your-registry/app:latest
        command: ["php", "artisan", "pool:supervise", "emails", "--workers=5"]
        env:
        - name: APP_ENV
          value: production
        - name: DB_HOST
          value: mysql-service
        - name: REDIS_HOST
          value: redis-service
        resources:
          requests:
            memory: "512Mi"
            cpu: "500m"
          limits:
            memory: "2Gi"
            cpu: "2000m"
        livenessProbe:
          exec:
            command:
            - php
            - artisan
            - pool:health
            - emails
          initialDelaySeconds: 30
          periodSeconds: 60
        readinessProbe:
          exec:
            command:
            - php
            - artisan
            - pool:ready
            - emails
          initialDelaySeconds: 10
          periodSeconds: 30
```

### Supervisor Process Manager

Deploy with Supervisor for process management:

```ini
; /etc/supervisor/conf.d/worker-pool-emails.conf
[program:worker-pool-emails]
process_name=%(program_name)s
command=php /var/www/app/artisan pool:supervise emails --workers=5
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/app/storage/logs/worker-pool-emails.log
stopwaitsecs=3600
```

Reload Supervisor configuration:

```bash
# Reread configuration
sudo supervisorctl reread

# Update processes
sudo supervisorctl update

# Start worker pool
sudo supervisorctl start worker-pool-emails

# Check status
sudo supervisorctl status
```

### Multi-Pool Management

Manage multiple pools in production:

```php
<?php

namespace App\Console\Commands;

use Cline\Chaperone\WorkerPools\WorkerPoolRegistry;
use Cline\Chaperone\WorkerPools\WorkerPoolSupervisor;
use Illuminate\Console\Command;

class SuperviseAllPoolsCommand extends Command
{
    protected $signature = 'pools:supervise';
    protected $description = 'Start all configured worker pools';

    public function handle(): int
    {
        $pools = config('worker-pools');
        $registry = new WorkerPoolRegistry();

        foreach ($pools as $name => $config) {
            $this->info("Starting pool: {$name}");

            $pool = new WorkerPoolSupervisor($name);
            $pool
                ->workers($config['workers'])
                ->queue($config['queue'])
                ->withHealthCheck($this->getHealthCheck($config))
                ->onCrash($this->getCrashHandler($name))
                ->onUnhealthy($this->getUnhealthyHandler($name));

            $registry->register($name, $pool);

            // Start in background
            dispatch(function () use ($pool) {
                $pool->supervise();
            })->onQueue('supervisor');
        }

        $this->info('All pools started');

        // Keep process alive
        while (true) {
            sleep(60);
            $this->checkPools($registry);
        }

        return self::SUCCESS;
    }

    private function getHealthCheck(array $config): callable
    {
        return function ($worker) use ($config) {
            return $worker->isResponsive()
                && $worker->memoryUsage() < ($config['memory_limit'] ?? 1024);
        };
    }

    private function getCrashHandler(string $poolName): callable
    {
        return function ($worker) use ($poolName) {
            Log::error("Worker crashed", [
                'pool' => $poolName,
                'worker' => $worker->id,
                'memory' => $worker->memoryUsage(),
            ]);

            Metrics::increment('worker.crashes', ['pool' => $poolName]);
        };
    }

    private function getUnhealthyHandler(string $poolName): callable
    {
        return function ($worker) use ($poolName) {
            Log::warning("Worker unhealthy", [
                'pool' => $poolName,
                'worker' => $worker->id,
            ]);

            $worker->restart();
        };
    }

    private function checkPools(WorkerPoolRegistry $registry): void
    {
        foreach ($registry->all() as $name => $pool) {
            $status = $pool->getStatus();

            Metrics::gauge('pool.workers', $status['worker_count'], [
                'pool' => $name,
            ]);
        }
    }
}
```

Configuration file:

```php
// config/worker-pools.php
return [
    'email-workers' => [
        'queue' => 'emails',
        'workers' => env('POOL_EMAILS_WORKERS', 5),
        'memory_limit' => 512,
    ],

    'import-workers' => [
        'queue' => 'imports',
        'workers' => env('POOL_IMPORTS_WORKERS', 3),
        'memory_limit' => 2048,
    ],

    'report-workers' => [
        'queue' => 'reports',
        'workers' => env('POOL_REPORTS_WORKERS', 2),
        'memory_limit' => 1024,
    ],

    'notification-workers' => [
        'queue' => 'notifications',
        'workers' => env('POOL_NOTIFICATIONS_WORKERS', 10),
        'memory_limit' => 256,
    ],
];
```

## Troubleshooting Common Issues

### Workers Not Starting

**Problem:** Pool starts but workers don't spawn

```php
// Check worker spawning
$pool = new WorkerPoolSupervisor('test-pool');
$pool->workers(5)->queue('default');

$status = $pool->getStatus();
var_dump($status); // Check if workers array is populated
```

**Solution:** Ensure queue configuration is correct:

```php
// config/queue.php
'connections' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => env('REDIS_QUEUE', 'default'),
        'retry_after' => 90,
        'block_for' => null,
    ],
],
```

### High Memory Usage

**Problem:** Workers consume too much memory

```php
// Monitor memory usage
$pool = new WorkerPoolSupervisor('memory-monitored');
$pool
    ->workers(5)
    ->queue('processing')
    ->withHealthCheck(function ($worker) {
        $memory = $worker->memoryUsage();

        if ($memory > 512) {
            Log::warning("High memory: {$memory}MB", [
                'worker' => $worker->id,
            ]);
        }

        return $memory < 1024;
    })
    ->supervise();
```

**Solution:** Implement memory limits and rotation:

```php
$pool = new WorkerPoolSupervisor('optimized-workers');
$pool
    ->workers(5)
    ->queue('processing')
    ->withHealthCheck(function ($worker) {
        $memory = $worker->memoryUsage();
        $uptime = now()->diffInMinutes($worker->startedAt);

        // Restart after 1GB or 30 minutes
        return $memory < 1024 && $uptime < 30;
    })
    ->supervise();
```

### Workers Crash Immediately

**Problem:** Workers crash right after starting

```php
$pool = new WorkerPoolSupervisor('debug-pool');
$pool
    ->workers(1)
    ->queue('default')
    ->onCrash(function ($worker) {
        $uptime = now()->diffInSeconds($worker->startedAt);

        if ($uptime < 10) {
            Log::critical('Immediate crash detected', [
                'worker' => $worker->id,
                'uptime' => $uptime,
            ]);

            // Stop pool to prevent crash loop
            throw new \RuntimeException('Workers crashing immediately');
        }
    })
    ->supervise();
```

**Solution:** Check for configuration or dependency issues:

```bash
# Test worker manually
php artisan queue:work default --once

# Check for errors
tail -f storage/logs/laravel.log

# Verify queue connection
php artisan tinker
>>> Queue::connection()->size('default')
```

### Pool Not Stopping Gracefully

**Problem:** Workers don't stop when pool is stopped

```php
// Add signal handling
$pool = new WorkerPoolSupervisor('graceful-pool');
$pool->workers(5)->queue('processing');

// Handle shutdown signals
pcntl_signal(SIGTERM, function () use ($pool) {
    Log::info('Received SIGTERM, stopping pool');
    $pool->stop();
    exit(0);
});

pcntl_signal(SIGINT, function () use ($pool) {
    Log::info('Received SIGINT, stopping pool');
    $pool->stop();
    exit(0);
});

$pool->supervise();
```

**Solution:** Implement proper shutdown handling:

```php
class GracefulPoolManager
{
    private WorkerPoolSupervisor $pool;
    private bool $shouldStop = false;

    public function start(): void
    {
        $this->registerSignalHandlers();

        $this->pool = new WorkerPoolSupervisor('managed-pool');
        $this->pool->workers(5)->queue('processing');

        while (!$this->shouldStop) {
            // Custom supervision loop
            sleep(1);
        }

        $this->pool->stop();
    }

    private function registerSignalHandlers(): void
    {
        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, fn() => $this->shouldStop = true);
        pcntl_signal(SIGINT, fn() => $this->shouldStop = true);
    }
}
```

### Health Checks Too Aggressive

**Problem:** Workers restart too frequently

```php
// Too strict - restarts every minute
$pool->withHealthCheck(function ($worker) {
    return $worker->isResponsive()
        && now()->diffInMinutes($worker->startedAt) < 1;
});
```

**Solution:** Use reasonable thresholds:

```php
// Balanced health checks
$pool->withHealthCheck(function ($worker) {
    // Allow workers to run for reasonable duration
    $uptime = now()->diffInMinutes($worker->startedAt);
    $memory = $worker->memoryUsage();

    // Restart conditions:
    // - Not responsive (crashed)
    // - Memory over 1GB
    // - Running longer than 2 hours
    return $worker->isResponsive()
        && $memory < 1024
        && $uptime < 120;
});
```

## Best Practices

### 1. Right-Size Worker Pools

Choose worker counts based on workload and resources:

```php
// CPU-intensive jobs - limit workers to CPU count
$cpuCount = (int) shell_exec('nproc');
$pool = new WorkerPoolSupervisor('cpu-intensive');
$pool->workers($cpuCount)->queue('processing');

// I/O-intensive jobs - can use more workers
$pool = new WorkerPoolSupervisor('io-intensive');
$pool->workers($cpuCount * 2)->queue('api-calls');

// Database jobs - match connection pool size
$dbConnections = config('database.connections.mysql.pool_size', 10);
$pool = new WorkerPoolSupervisor('database-jobs');
$pool->workers(min($dbConnections, 5))->queue('database');
```

### 2. Implement Comprehensive Logging

Log pool events for debugging and monitoring:

```php
$pool = new WorkerPoolSupervisor('logged-pool');
$pool
    ->workers(5)
    ->queue('processing')
    ->withHealthCheck(function ($worker) {
        $healthy = $worker->isResponsive() && $worker->memoryUsage() < 1024;

        Log::debug('Health check', [
            'worker' => $worker->id,
            'healthy' => $healthy,
            'memory' => $worker->memoryUsage(),
        ]);

        return $healthy;
    })
    ->onCrash(function ($worker) {
        Log::error('Worker crashed', [
            'worker' => $worker->id,
            'uptime' => now()->diffInSeconds($worker->startedAt),
            'memory' => $worker->memoryUsage(),
        ]);
    })
    ->onUnhealthy(function ($worker) {
        Log::warning('Worker unhealthy', [
            'worker' => $worker->id,
            'status' => $worker->status,
        ]);

        $worker->restart();
    })
    ->supervise();
```

### 3. Use Metrics and Monitoring

Integrate with metrics systems:

```php
use Illuminate\Support\Facades\Metrics;

$pool = new WorkerPoolSupervisor('monitored-pool');
$pool
    ->workers(5)
    ->queue('processing')
    ->withHealthCheck(function ($worker) use ($pool) {
        $healthy = $worker->isResponsive() && $worker->memoryUsage() < 1024;

        // Track metrics
        Metrics::gauge('worker.memory', $worker->memoryUsage(), [
            'pool' => $pool->getName(),
            'worker' => $worker->id,
        ]);

        Metrics::gauge('worker.uptime', now()->diffInSeconds($worker->startedAt), [
            'pool' => $pool->getName(),
            'worker' => $worker->id,
        ]);

        return $healthy;
    })
    ->onCrash(function ($worker) use ($pool) {
        Metrics::increment('worker.crashes', [
            'pool' => $pool->getName(),
        ]);
    })
    ->supervise();
```

### 4. Implement Graceful Shutdown

Handle shutdown signals properly:

```php
class WorkerPoolService
{
    private array $pools = [];
    private bool $running = true;

    public function start(): void
    {
        $this->registerSignalHandlers();
        $this->createPools();

        while ($this->running) {
            $this->monitorPools();
            sleep(10);
        }

        $this->shutdown();
    }

    private function registerSignalHandlers(): void
    {
        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, function () {
            Log::info('Received SIGTERM, initiating shutdown');
            $this->running = false;
        });

        pcntl_signal(SIGINT, function () {
            Log::info('Received SIGINT, initiating shutdown');
            $this->running = false;
        });
    }

    private function shutdown(): void
    {
        Log::info('Shutting down all pools');

        foreach ($this->pools as $name => $pool) {
            Log::info("Stopping pool: {$name}");
            $pool->stop();
        }

        Log::info('All pools stopped');
    }
}
```

### 5. Test Pool Configuration

Test your pool configuration before production:

```php
use Tests\TestCase;
use Cline\Chaperone\WorkerPools\WorkerPoolSupervisor;

class WorkerPoolTest extends TestCase
{
    public function test_pool_creates_correct_worker_count(): void
    {
        $pool = new WorkerPoolSupervisor('test-pool');
        $pool->workers(5)->queue('test');

        $status = $pool->getStatus();

        $this->assertEquals(5, $status['worker_count']);
    }

    public function test_health_check_callback_is_invoked(): void
    {
        $healthCheckCalled = false;

        $pool = new WorkerPoolSupervisor('test-pool');
        $pool
            ->workers(1)
            ->queue('test')
            ->withHealthCheck(function ($worker) use (&$healthCheckCalled) {
                $healthCheckCalled = true;
                return true;
            });

        // Trigger health check
        $pool->supervise();

        $this->assertTrue($healthCheckCalled);
    }

    public function test_crash_callback_is_invoked(): void
    {
        $crashCallbackCalled = false;

        $pool = new WorkerPoolSupervisor('test-pool');
        $pool
            ->workers(1)
            ->queue('test')
            ->onCrash(function ($worker) use (&$crashCallbackCalled) {
                $crashCallbackCalled = true;
            });

        // Simulate crash and verify callback

        $this->assertTrue($crashCallbackCalled);
    }
}
```

## Next Steps

- **[Basic Supervision](basic-supervision.md)** - Learn about supervising individual jobs
- **[Health Monitoring](health-monitoring.md)** - Advanced health check strategies
- **[Resource Limits](resource-limits.md)** - Configure memory, CPU, and timeout limits
- **[Artisan Commands](artisan-commands.md)** - Available commands for pool management
- **[Configuration](configuration.md)** - Complete configuration reference
