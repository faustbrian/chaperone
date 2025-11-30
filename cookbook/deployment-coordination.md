# Deployment Coordination

This guide covers Chaperone's deployment coordination system for achieving zero-downtime deployments with Laravel queue workers. Learn how to gracefully drain queues, wait for job completion, handle long-running jobs, and integrate with CI/CD pipelines.

## Understanding Zero-Downtime Deployments

When deploying Laravel applications with queue workers, stopping workers mid-execution can lead to:

- **Lost jobs** - Workers terminated before jobs complete
- **Corrupted data** - Partial transactions or state updates
- **Failed operations** - External API calls left incomplete
- **Resource leaks** - Database connections, file handles, or locks not released

Chaperone's deployment coordinator solves these problems by:

1. **Pausing queue ingestion** - No new jobs are picked up
2. **Monitoring running jobs** - Track completion of in-flight work
3. **Enforcing timeouts** - Handle jobs that exceed deployment windows
4. **Cancelling stragglers** - Gracefully fail jobs that can't complete
5. **Emitting events** - Integrate with deployment automation

## The Deployment Coordinator

The `DeploymentCoordinator` orchestrates the entire graceful shutdown process.

### Basic Usage

```php
use Cline\Chaperone\Deployment\DeploymentCoordinator;

$coordinator = new DeploymentCoordinator();

$success = $coordinator
    ->drainQueues(['default', 'emails', 'notifications'])
    ->waitForCompletion(timeout: 300) // 5 minutes
    ->execute();

if ($success) {
    echo "All jobs completed - safe to deploy";
} else {
    echo "Timeout reached with jobs still running";
}
```

### Fluent Interface

Chain configuration methods before executing:

```php
$coordinator = new DeploymentCoordinator();

$coordinator
    ->drainQueues(['default', 'high', 'low'])
    ->waitForCompletion(300)
    ->cancelLongRunning()
    ->onTimeout(function ($remainingJobs) {
        Log::warning('Deployment timeout', [
            'remaining' => $remainingJobs->count(),
            'jobs' => $remainingJobs->pluck('job_class'),
        ]);
    })
    ->execute();
```

## Queue Draining

The `QueueDrainer` pauses queues to prevent new jobs from starting.

### How Queue Draining Works

When queues are drained:

1. **Cache flag set** - `chaperone:queue_paused:{queue}` key created
2. **Laravel queue paused** - `queue:pause` artisan command executed
3. **Workers stop polling** - No new jobs are picked up
4. **Running jobs continue** - In-flight work completes normally

### Manual Queue Control

```php
use Cline\Chaperone\Deployment\QueueDrainer;

$drainer = new QueueDrainer();

// Pause queues
$drainer->drain(['default', 'emails']);

// Check if paused
if ($drainer->isPaused('default')) {
    echo "Queue is paused";
}

// Resume queues after deployment
$drainer->resume(['default', 'emails']);
```

### Selective Queue Draining

Drain specific queues while keeping others active:

```php
// Drain user-facing queues
$drainer->drain(['default', 'emails', 'notifications']);

// Keep internal queues running
// 'analytics', 'reporting' continue processing
```

### Automatic Resume

Always resume queues after deployment:

```php
try {
    $drainer->drain($queues);

    // Deploy application
    $this->deployApplication();

} finally {
    // Always resume, even on failure
    $drainer->resume($queues);
}
```

## Waiting for Job Completion

The `JobWaiter` monitors running jobs until they complete or timeout.

### Basic Job Waiting

```php
use Cline\Chaperone\Deployment\JobWaiter;

$waiter = new JobWaiter();

// Wait up to 5 minutes for jobs to complete
$completed = $waiter->waitForJobs(['default'], timeout: 300);

if ($completed) {
    echo "All jobs finished";
} else {
    echo "Some jobs still running";
}
```

### Monitoring Running Jobs

Get details about jobs still executing:

```php
$runningJobs = $waiter->getRunningJobs(['default', 'emails']);

foreach ($runningJobs as $job) {
    echo "Job: {$job->job_class}";
    echo "Started: {$job->started_at->diffForHumans()}";
    echo "Progress: {$job->progress}%";
}
```

### Remaining Job Count

Check how many jobs are still running:

```php
$count = $waiter->getRemainingCount(['default', 'emails']);

if ($count > 0) {
    echo "{$count} jobs still running";
}
```

### Polling Interval

The waiter polls every 5 seconds by default:

```php
// Internal polling logic
while (true) {
    $remaining = $this->getRemainingCount($queues);

    if ($remaining === 0) {
        return true; // All done
    }

    if (time() - $startTime >= $timeout) {
        return false; // Timeout
    }

    sleep(5); // Check again in 5 seconds
}
```

## Handling Timeouts

When deployment windows are limited, configure timeout behavior.

### Basic Timeout Handling

```php
$coordinator = new DeploymentCoordinator();

$success = $coordinator
    ->drainQueues(['default'])
    ->waitForCompletion(180) // 3 minute timeout
    ->execute();

if (!$success) {
    // Deployment timed out
    $remaining = $coordinator->getRemainingJobs();
    echo "{$remaining->count()} jobs still running";
}
```

### Timeout Callbacks

Execute custom logic when timeouts occur:

```php
$coordinator
    ->drainQueues(['default'])
    ->waitForCompletion(300)
    ->onTimeout(function ($remainingJobs) {
        // Log timeout
        Log::error('Deployment timeout', [
            'count' => $remainingJobs->count(),
        ]);

        // Send notification
        Notification::send(
            User::admins(),
            new DeploymentTimeoutNotification($remainingJobs)
        );

        // Record metrics
        Metric::increment('deployment.timeouts');
    })
    ->execute();
```

### Choosing Timeout Values

Set timeouts based on your deployment constraints:

```php
// Quick deployments (< 5 min maintenance window)
->waitForCompletion(180) // 3 minutes

// Standard deployments (< 10 min window)
->waitForCompletion(300) // 5 minutes

// Extended deployments (< 30 min window)
->waitForCompletion(900) // 15 minutes

// Long deployments (flexible timing)
->waitForCompletion(3600) // 1 hour
```

## Cancellation Strategies

Handle jobs that exceed deployment timeouts.

### Cancel Long-Running Jobs

Forcibly fail jobs that can't complete:

```php
$coordinator
    ->drainQueues(['default'])
    ->waitForCompletion(300)
    ->cancelLongRunning() // <-- Enable cancellation
    ->execute();
```

### How Cancellation Works

When enabled, timed-out jobs are:

1. **Marked as failed** - `failed_at` timestamp set
2. **Moved to dead letter queue** - Available for manual retry
3. **Resources released** - Database connections, locks freed
4. **Events dispatched** - `DeploymentCompleted` with cancellation count

```php
// Internal cancellation logic
if ($this->shouldCancel) {
    $remainingJobs->each(fn ($job) => $job->update([
        'failed_at' => now(),
    ]));
}
```

### Selective Cancellation

Cancel specific queues while allowing others to complete:

```php
// Cancel non-critical queues
$coordinator
    ->drainQueues(['default', 'low-priority'])
    ->waitForCompletion(300)
    ->cancelLongRunning()
    ->execute();

// Let critical queues finish
$criticalCoordinator
    ->drainQueues(['payments', 'orders'])
    ->waitForCompletion(1800) // Give them 30 minutes
    ->execute(); // Don't cancel - wait for completion
```

### Manual Cancellation

Cancel specific jobs programmatically:

```php
use Cline\Chaperone\Database\Models\SupervisedJob;

// Find long-running jobs
$longRunning = SupervisedJob::running()
    ->where('started_at', '<', now()->subMinutes(30))
    ->get();

// Cancel them
foreach ($longRunning as $job) {
    $job->update(['failed_at' => now()]);

    Log::info('Cancelled job for deployment', [
        'job' => $job->job_class,
        'runtime' => $job->started_at->diffInMinutes(),
    ]);
}
```

## Deployment Events

Listen to deployment lifecycle events for monitoring and automation.

### DeploymentStarted

Fired when deployment preparation begins:

```php
use Cline\Chaperone\Events\DeploymentStarted;
use Illuminate\Support\Facades\Event;

Event::listen(DeploymentStarted::class, function ($event) {
    Log::info('Deployment started', [
        'queues' => $event->queues,
        'started_at' => $event->startedAt,
    ]);

    // Notify team
    Slack::send("Deployment started - queues draining");

    // Record in deployment tracker
    DeploymentTracker::start([
        'queues' => $event->queues,
        'timestamp' => $event->startedAt,
    ]);
});
```

### DeploymentCompleted

Fired when all jobs complete or are cancelled:

```php
use Cline\Chaperone\Events\DeploymentCompleted;

Event::listen(DeploymentCompleted::class, function ($event) {
    Log::info('Deployment completed', [
        'queues' => $event->queues,
        'completed_at' => $event->completedAt,
        'cancelled_jobs' => $event->cancelledJobCount,
    ]);

    if ($event->cancelledJobCount > 0) {
        // Some jobs were cancelled
        Slack::send("Deployment complete - {$event->cancelledJobCount} jobs cancelled");
    } else {
        // Clean deployment
        Slack::send("Deployment complete - all jobs finished");
    }

    // Record metrics
    Metric::record('deployment.cancelled_jobs', $event->cancelledJobCount);
});
```

### DeploymentTimedOut

Fired when the deployment timeout is reached:

```php
use Cline\Chaperone\Events\DeploymentTimedOut;

Event::listen(DeploymentTimedOut::class, function ($event) {
    Log::warning('Deployment timed out', [
        'queues' => $event->queues,
        'timeout' => $event->timeout,
        'remaining' => $event->remainingJobCount,
    ]);

    // Send alert
    Notification::send(
        User::admins(),
        new DeploymentTimeoutAlert(
            $event->queues,
            $event->remainingJobCount
        )
    );

    // Record incident
    Incident::create([
        'type' => 'deployment_timeout',
        'severity' => 'warning',
        'details' => [
            'queues' => $event->queues,
            'remaining_jobs' => $event->remainingJobCount,
        ],
    ]);
});
```

### Event Subscribers

Group all deployment event handlers:

```php
namespace App\Listeners;

use Cline\Chaperone\Events\DeploymentCompleted;
use Cline\Chaperone\Events\DeploymentStarted;
use Cline\Chaperone\Events\DeploymentTimedOut;
use Illuminate\Events\Dispatcher;

class DeploymentEventSubscriber
{
    public function handleStarted(DeploymentStarted $event): void
    {
        // Handle deployment start
    }

    public function handleCompleted(DeploymentCompleted $event): void
    {
        // Handle deployment completion
    }

    public function handleTimedOut(DeploymentTimedOut $event): void
    {
        // Handle deployment timeout
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            DeploymentStarted::class => 'handleStarted',
            DeploymentCompleted::class => 'handleCompleted',
            DeploymentTimedOut::class => 'handleTimedOut',
        ];
    }
}
```

Register in `EventServiceProvider`:

```php
protected $subscribe = [
    DeploymentEventSubscriber::class,
];
```

## Artisan Command

Use the built-in artisan command for deployments.

### Basic Command Usage

```bash
php artisan chaperone:prepare-deployment
```

### Specifying Queues

Drain specific queues:

```bash
php artisan chaperone:prepare-deployment --queues=default --queues=emails
```

### Setting Timeout

Configure deployment timeout:

```bash
# 5 minute timeout
php artisan chaperone:prepare-deployment --timeout=300

# 15 minute timeout
php artisan chaperone:prepare-deployment --timeout=900
```

### Enabling Cancellation

Cancel long-running jobs after timeout:

```bash
php artisan chaperone:prepare-deployment --cancel
```

### Complete Example

```bash
php artisan chaperone:prepare-deployment \
  --queues=default \
  --queues=emails \
  --queues=notifications \
  --timeout=600 \
  --cancel
```

### Command Output

The command provides visual feedback:

```
Preparing for deployment...
Queues: default, emails, notifications
Timeout: 600s

[==========>---------] 50% (300s elapsed)

✓ Deployment preparation complete
```

Or on timeout:

```
⚠ Deployment timed out with 3 jobs remaining

ID    Class                      Queue    Started
----  -------------------------  -------  -------------
1234  ProcessLargeDataset        default  5 minutes ago
1235  GenerateMonthlyReport      default  12 minutes ago
1236  SyncExternalInventory      default  8 minutes ago

✗ Deployment preparation failed or timed out
```

## CI/CD Integration

Integrate deployment coordination into automated pipelines.

### GitHub Actions

```yaml
name: Deploy to Production

on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest

    steps:
      - name: Checkout code
        uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.2

      - name: Install dependencies
        run: composer install --no-dev --optimize-autoloader

      - name: Prepare deployment
        run: |
          php artisan chaperone:prepare-deployment \
            --queues=default \
            --queues=emails \
            --timeout=300 \
            --cancel

      - name: Deploy application
        run: |
          # Your deployment script
          ./deploy.sh

      - name: Resume queues
        if: always()
        run: |
          php artisan queue:resume default
          php artisan queue:resume emails
```

### GitLab CI

```yaml
stages:
  - prepare
  - deploy
  - cleanup

prepare-deployment:
  stage: prepare
  script:
    - composer install --no-dev
    - php artisan chaperone:prepare-deployment --timeout=300
  only:
    - main

deploy-application:
  stage: deploy
  script:
    - ./deploy.sh
  only:
    - main

resume-queues:
  stage: cleanup
  script:
    - php artisan queue:resume default
  when: always
  only:
    - main
```

### Jenkins Pipeline

```groovy
pipeline {
    agent any

    stages {
        stage('Prepare Deployment') {
            steps {
                sh 'composer install --no-dev'
                sh 'php artisan chaperone:prepare-deployment --timeout=300 --cancel'
            }
        }

        stage('Deploy Application') {
            steps {
                sh './deploy.sh'
            }
        }

        stage('Resume Queues') {
            steps {
                sh 'php artisan queue:resume default'
            }
        }
    }

    post {
        always {
            sh 'php artisan queue:resume default || true'
        }
    }
}
```

## Laravel Forge Integration

Integrate with Laravel Forge deployment scripts.

### Forge Deployment Script

Add to your site's deployment script:

```bash
cd /home/forge/example.com

git pull origin main

# Prepare deployment - drain queues
php artisan chaperone:prepare-deployment \
  --queues=default \
  --timeout=300 \
  --cancel

# Install dependencies
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Run migrations
php artisan migrate --force

# Cache configuration
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Resume queues
php artisan queue:resume default

# Restart workers
php artisan queue:restart
```

### Forge Quick Deploy Hook

Create a webhook for automated deployments:

```php
// routes/web.php
Route::post('/deploy-webhook', function (Request $request) {
    // Verify webhook signature
    if (!$this->verifyForgeSignature($request)) {
        abort(403);
    }

    // Prepare deployment
    Artisan::call('chaperone:prepare-deployment', [
        '--timeout' => 300,
        '--cancel' => true,
    ]);

    // Trigger Forge deployment
    // (Forge will run your deployment script)

    return response()->json(['status' => 'success']);
});
```

### Multi-Server Deployments

For multi-server setups, coordinate across all servers:

```bash
#!/bin/bash
# deploy.sh

SERVERS=("server1.example.com" "server2.example.com" "server3.example.com")

echo "Draining queues on all servers..."
for server in "${SERVERS[@]}"; do
  ssh forge@$server "cd /home/forge/example.com && php artisan chaperone:prepare-deployment --timeout=300"
done

echo "Deploying to all servers..."
for server in "${SERVERS[@]}"; do
  ssh forge@$server "cd /home/forge/example.com && git pull && composer install --no-dev"
done

echo "Resuming queues..."
for server in "${SERVERS[@]}"; do
  ssh forge@$server "cd /home/forge/example.com && php artisan queue:resume default"
done

echo "Deployment complete!"
```

## Laravel Vapor Integration

Deploy to AWS Lambda with queue coordination.

### Vapor Deployment Hook

Add to `vapor.yml`:

```yaml
id: 12345
name: my-app
environments:
  production:
    memory: 1024
    timeout: 30
    database: my-db

    deploy:
      - 'composer install --no-dev --classmap-authoritative'
      - 'php artisan config:cache'
      - 'php artisan chaperone:prepare-deployment --timeout=180'
```

### Custom Vapor Hook

Use Vapor hooks for more control:

```php
// VaporHooks.php
namespace App\Vapor;

use Illuminate\Support\Facades\Artisan;

class Hooks
{
    public static function preDeployment(): void
    {
        // Drain queues before deploying
        Artisan::call('chaperone:prepare-deployment', [
            '--queues' => 'default,emails',
            '--timeout' => 300,
        ]);
    }

    public static function postDeployment(): void
    {
        // Resume queues after deployment
        Artisan::call('queue:resume', ['--queue' => 'default']);
        Artisan::call('queue:resume', ['--queue' => 'emails']);
    }
}
```

Register in `vapor.yml`:

```yaml
environments:
  production:
    pre-deploy: 'App\Vapor\Hooks::preDeployment'
    post-deploy: 'App\Vapor\Hooks::postDeployment'
```

## Laravel Envoyer Integration

Use Envoyer for zero-downtime deployments.

### Envoyer Deployment Hooks

Add before-activation hook:

```bash
# Before Activation Hook
cd {{ release }}

php artisan chaperone:prepare-deployment \
  --timeout=300 \
  --cancel
```

Add after-activation hook:

```bash
# After Activation Hook
cd {{ release }}

php artisan queue:resume default
php artisan queue:restart
```

### Envoyer Deployment Script

Complete deployment flow:

```bash
# Clone Repository
git clone {{ repository }} {{ release }}
cd {{ release }}

# Install Dependencies
composer install --no-dev --optimize-autoloader

# Before Activation - Drain Queues
php artisan chaperone:prepare-deployment --timeout=300

# Activate Release
# (Envoyer swaps the current symlink)

# After Activation - Resume Queues
php artisan queue:resume default
php artisan queue:restart

# Run Post-Deployment Tasks
php artisan config:cache
php artisan route:cache
php artisan migrate --force
```

### Envoyer Health Checks

Verify deployment health before activating:

```bash
# Health Check Hook
cd {{ release }}

# Check if queues drained successfully
if php artisan chaperone:verify-drained; then
  echo "Queues drained - safe to activate"
  exit 0
else
  echo "Queues still active - aborting deployment"
  exit 1
fi
```

Create the verification command:

```php
namespace App\Console\Commands;

use Cline\Chaperone\Deployment\JobWaiter;
use Illuminate\Console\Command;

class VerifyDrainedCommand extends Command
{
    protected $signature = 'chaperone:verify-drained {--queues=*}';

    public function handle(JobWaiter $waiter): int
    {
        $queues = $this->option('queues') ?: ['default'];

        $remaining = $waiter->getRemainingCount($queues);

        if ($remaining === 0) {
            $this->info('All queues drained');
            return self::SUCCESS;
        }

        $this->error("{$remaining} jobs still running");
        return self::FAILURE;
    }
}
```

## Production Deployment Checklist

Follow this checklist for safe production deployments.

### Pre-Deployment

- [ ] **Review pending jobs** - Check queue depth and job types
  ```php
  SupervisedJob::pending()->count();
  ```

- [ ] **Estimate completion time** - Assess how long jobs will take
  ```php
  $avgDuration = SupervisedJob::completed()
      ->where('created_at', '>', now()->subHour())
      ->avg('duration');
  ```

- [ ] **Configure timeout** - Set based on queue depth and avg duration
  ```bash
  # Queue depth: 50, Avg duration: 30s
  # Timeout: 50 * 30s = 1500s (25 minutes)
  --timeout=1500
  ```

- [ ] **Notify stakeholders** - Alert team about deployment window
  ```php
  Slack::send("Deployment starting in 5 minutes");
  ```

- [ ] **Enable maintenance mode** - Prevent new job creation
  ```bash
  php artisan down --retry=60
  ```

### During Deployment

- [ ] **Drain queues** - Pause job ingestion
  ```bash
  php artisan chaperone:prepare-deployment
  ```

- [ ] **Monitor progress** - Watch job completion
  ```php
  while ($waiter->getRemainingCount(['default']) > 0) {
      echo $waiter->getRemainingCount(['default']) . " jobs remaining\n";
      sleep(5);
  }
  ```

- [ ] **Handle timeouts** - Decide on cancellation strategy
  ```php
  if (!$coordinator->execute()) {
      // Manual intervention required
  }
  ```

- [ ] **Deploy application** - Update code and assets
  ```bash
  git pull
  composer install --no-dev
  php artisan migrate --force
  ```

### Post-Deployment

- [ ] **Resume queues** - Re-enable job processing
  ```bash
  php artisan queue:resume default
  ```

- [ ] **Restart workers** - Reload new code
  ```bash
  php artisan queue:restart
  ```

- [ ] **Verify health** - Check queue processing
  ```bash
  php artisan queue:work --once
  ```

- [ ] **Monitor errors** - Watch for deployment issues
  ```php
  SupervisedJob::failed()
      ->where('failed_at', '>', now()->subMinutes(10))
      ->count();
  ```

- [ ] **Disable maintenance mode** - Resume normal operations
  ```bash
  php artisan up
  ```

- [ ] **Notify completion** - Alert team
  ```php
  Slack::send("Deployment complete - all systems operational");
  ```

## Rollback Procedures

Safely rollback deployments when issues occur.

### Quick Rollback

```bash
#!/bin/bash
# rollback.sh

echo "Starting rollback..."

# Stop queue workers
php artisan queue:restart

# Pause queues
php artisan queue:pause default

# Wait for current jobs to complete
php artisan chaperone:prepare-deployment --timeout=180

# Revert to previous release
git reset --hard HEAD~1
composer install --no-dev

# Run migrations (if needed)
php artisan migrate:rollback --step=1

# Resume queues
php artisan queue:resume default

# Restart workers with old code
php artisan queue:restart

echo "Rollback complete"
```

### Forge Rollback

Use Forge's deployment history:

1. Navigate to your site in Forge
2. Click "Deployments" tab
3. Find the previous successful deployment
4. Click "Rollback to this deployment"
5. Manually resume queues:
   ```bash
   ssh forge@server "cd /home/forge/example.com && php artisan queue:resume default"
   ```

### Envoyer Rollback

Envoyer makes rollbacks trivial:

1. Click "Deployments" in Envoyer
2. Find the previous deployment
3. Click "Activate" to switch symlink
4. Workers automatically pick up old code
5. Verify queues are processing:
   ```bash
   php artisan queue:monitor
   ```

### Rollback with Running Jobs

If jobs are running during rollback:

```php
// Cancel all running jobs
SupervisedJob::running()->each(function ($job) {
    $job->update(['failed_at' => now()]);
});

// Purge queue
Artisan::call('queue:clear', ['--queue' => 'default']);

// Rollback code
system('git reset --hard HEAD~1');
system('composer install --no-dev');

// Restart workers
Artisan::call('queue:restart');
```

### Handling Data Migrations

For deployments with database migrations:

```bash
# Before deployment - backup database
php artisan db:backup

# During deployment
php artisan migrate

# If rollback needed
php artisan migrate:rollback
# or
php artisan db:restore latest
```

## Advanced Patterns

### Gradual Queue Draining

Drain queues gradually for very high-volume systems:

```php
$queues = ['default', 'emails', 'notifications', 'analytics'];
$timeout = 600; // 10 minutes total

foreach ($queues as $queue) {
    $coordinator = new DeploymentCoordinator();

    $success = $coordinator
        ->drainQueues([$queue])
        ->waitForCompletion($timeout / count($queues))
        ->execute();

    if (!$success) {
        Log::warning("Queue {$queue} didn't drain in time");
    }
}
```

### Priority-Based Draining

Drain high-priority queues first:

```php
$queuesByPriority = [
    'critical' => ['payments', 'orders'],
    'high' => ['emails', 'notifications'],
    'normal' => ['default'],
    'low' => ['analytics', 'reporting'],
];

foreach ($queuesByPriority as $priority => $queues) {
    $timeout = match($priority) {
        'critical' => 1800, // 30 minutes
        'high' => 600,      // 10 minutes
        'normal' => 300,    // 5 minutes
        'low' => 0,         // Cancel immediately
    };

    $coordinator = new DeploymentCoordinator();
    $coordinator
        ->drainQueues($queues)
        ->waitForCompletion($timeout);

    if ($timeout === 0) {
        $coordinator->cancelLongRunning();
    }

    $coordinator->execute();
}
```

### Blue-Green Deployments

Use blue-green strategy for zero-downtime:

```php
// Deploy to "green" environment
Artisan::call('deploy:environment', ['env' => 'green']);

// Drain queues on "blue" (current)
$coordinator = new DeploymentCoordinator();
$coordinator
    ->drainQueues(['default'])
    ->waitForCompletion(300)
    ->execute();

// Switch traffic to "green"
LoadBalancer::switchTo('green');

// Keep "blue" as fallback
sleep(300); // 5 minute soak

// If green is stable, drain and shutdown blue
$blueCoordinator = new DeploymentCoordinator();
$blueCoordinator
    ->drainQueues(['default'])
    ->waitForCompletion(300)
    ->cancelLongRunning()
    ->execute();

Artisan::call('server:shutdown', ['env' => 'blue']);
```

### Canary Deployments

Gradually roll out to workers:

```php
// Deploy to 10% of workers
$canaryWorkers = Worker::inRandomOrder()->take(5)->get();

foreach ($canaryWorkers as $worker) {
    // Drain this worker's queues
    $worker->drainQueues();

    // Deploy new code
    $worker->deploy();

    // Monitor for errors
    sleep(60); // 1 minute soak

    $errors = SupervisedJob::failed()
        ->where('worker_id', $worker->id)
        ->where('failed_at', '>', now()->subMinute())
        ->count();

    if ($errors > 0) {
        // Rollback canary
        $worker->rollback();
        throw new DeploymentException("Canary deployment failed");
    }
}

// If canary succeeds, deploy to remaining workers
$remainingWorkers = Worker::whereNotIn('id', $canaryWorkers->pluck('id'))->get();
foreach ($remainingWorkers as $worker) {
    $worker->drainQueues();
    $worker->deploy();
}
```

## Best Practices

### 1. Always Set Timeouts

Never deploy without a timeout:

```php
// Bad - could wait forever
$coordinator->drainQueues(['default'])->execute();

// Good - bounded wait time
$coordinator
    ->drainQueues(['default'])
    ->waitForCompletion(300)
    ->execute();
```

### 2. Handle Timeout Failures

Always have a plan for timeout failures:

```php
$success = $coordinator
    ->drainQueues(['default'])
    ->waitForCompletion(300)
    ->execute();

if (!$success) {
    // Option 1: Cancel and proceed
    $coordinator->cancelLongRunning();
    $coordinator->execute();

    // Option 2: Abort deployment
    Log::error('Deployment aborted - jobs still running');
    throw new DeploymentException();

    // Option 3: Manual intervention
    $this->notifyOps('Manual intervention required');
    return;
}
```

### 3. Monitor Queue Depth

Check queue depth before draining:

```php
$depth = SupervisedJob::pending()->count();

if ($depth > 1000) {
    Log::warning('High queue depth - deployment may take longer', [
        'depth' => $depth,
    ]);

    // Adjust timeout
    $timeout = ($depth / 10) * 30; // 30s per 10 jobs
    $coordinator->waitForCompletion($timeout);
}
```

### 4. Resume Queues in Finally Blocks

Always resume queues, even on failure:

```php
$drainer = new QueueDrainer();

try {
    $drainer->drain(['default']);
    $this->deploy();
} catch (Exception $e) {
    Log::error('Deployment failed', ['error' => $e->getMessage()]);
    throw $e;
} finally {
    // Always resume
    $drainer->resume(['default']);
}
```

### 5. Test Deployment Procedures

Test your deployment process in staging:

```bash
# Staging deployment test
php artisan chaperone:prepare-deployment --timeout=60
./deploy.sh
php artisan queue:resume default

# Verify
php artisan queue:work --once
```

## Next Steps

- **[Events](events.md)** - Listen to deployment and supervision events
- **[Health Monitoring](health-monitoring.md)** - Monitor job health during deployments
- **[Resource Limits](resource-limits.md)** - Configure resource constraints
- **[Advanced Usage](advanced-usage.md)** - Worker pools and advanced patterns
