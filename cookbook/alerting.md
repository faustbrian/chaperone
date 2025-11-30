# Alerting System

This guide covers Chaperone's multi-channel alerting system, including email notifications, Slack webhooks, rate limiting, custom notification channels, alert types, testing, and production best practices.

## Introduction

Chaperone's alerting system provides real-time notifications when supervised jobs encounter critical issues. The system supports multiple notification channels, intelligent rate limiting to prevent alert fatigue, and comprehensive event coverage for all supervision scenarios.

### Key Features

- **Multi-Channel Delivery** - Send alerts via email, Slack, database, and custom channels
- **Intelligent Rate Limiting** - Prevent alert spam with configurable thresholds
- **Event-Driven Architecture** - Automatic alerts for stuck jobs, timeouts, circuit breakers, and resource violations
- **Production-Ready** - Built-in support for on-call rotations and incident management workflows
- **Testing Tools** - CLI commands for validating alert configuration before production deployment

### Alert Types

Chaperone automatically dispatches alerts for four critical event types:

1. **Job Stuck** - Job running without heartbeats
2. **Job Timeout** - Job exceeds configured timeout limit
3. **Circuit Breaker Opened** - Service circuit breaker trips due to failures
4. **Resource Violation** - Job exceeds memory, CPU, or other resource limits

## Configuration

### Basic Setup

Enable alerting in your `.env` file:

```env
CHAPERONE_ALERTING_ENABLED=true
CHAPERONE_ALERT_CHANNELS=mail,slack
CHAPERONE_ALERT_RECIPIENTS=ops@example.com,devops@example.com
CHAPERONE_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/YOUR/WEBHOOK/URL
```

### Configuration File

Review the alerting configuration in `config/chaperone.php`:

```php
return [
    'alerting' => [
        // Enable/disable alerting globally
        'enabled' => env('CHAPERONE_ALERTING_ENABLED', true),

        // Notification channels (mail, slack, database, etc.)
        'channels' => explode(',', env('CHAPERONE_ALERT_CHANNELS', 'mail,slack')),

        // Slack webhook URL for Slack notifications
        'slack_webhook_url' => env('CHAPERONE_SLACK_WEBHOOK_URL'),

        // Email addresses to receive alerts
        'recipients' => explode(',', env('CHAPERONE_ALERT_RECIPIENTS', '')),

        // Alert thresholds
        'thresholds' => [
            'error_rate' => env('CHAPERONE_ALERT_ERROR_RATE', 10),
            'response_time' => env('CHAPERONE_ALERT_RESPONSE_TIME', 300),
            'queue_length' => env('CHAPERONE_ALERT_QUEUE_LENGTH', 1000),
        ],
    ],
];
```

### Environment Variables

Configure alerting behavior via environment variables:

```env
# Global alerting toggle
CHAPERONE_ALERTING_ENABLED=true

# Notification channels (comma-separated)
CHAPERONE_ALERT_CHANNELS=mail,slack,database

# Email recipients (comma-separated)
CHAPERONE_ALERT_RECIPIENTS=oncall@example.com,team-lead@example.com

# Slack webhook URL
CHAPERONE_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/T00/B00/XXX

# Alert thresholds
CHAPERONE_ALERT_ERROR_RATE=10
CHAPERONE_ALERT_RESPONSE_TIME=300
CHAPERONE_ALERT_QUEUE_LENGTH=1000
```

## Email Notifications

### Setup

Configure Laravel's mail settings in `config/mail.php`:

```php
return [
    'default' => env('MAIL_MAILER', 'smtp'),

    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'host' => env('MAIL_HOST', 'smtp.mailgun.org'),
            'port' => env('MAIL_PORT', 587),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
        ],
    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'alerts@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Chaperone Alerts'),
    ],
];
```

### Email Recipients

Add recipients in `.env`:

```env
CHAPERONE_ALERT_RECIPIENTS=ops@example.com,devops@example.com,oncall@example.com
```

### Email Content

Email notifications include:

- **Subject Line** - Alert type and affected job class
- **Greeting** - Alert severity and type
- **Job Details** - Supervision ID, job class, relevant metrics
- **Context** - Explanation of the issue and potential causes
- **Action Button** - Direct link to job details (requires UI setup)

### Example Email Alert

```
Subject: [Chaperone Alert] Job Stuck: App\Jobs\ProcessLargeDataset

Job Stuck Alert

A supervised job has been detected as stuck and may require intervention.

Supervision ID: 550e8400-e29b-41d4-a716-446655440000
Job Class: App\Jobs\ProcessLargeDataset
Stuck Duration: 30 minutes
Last Heartbeat: 2024-01-15 10:30:00

This job may be in an infinite loop, deadlocked, or otherwise unable to complete.

[View Job Details]
```

## Slack Integration

### Webhook Setup

Create a Slack webhook:

1. Navigate to https://api.slack.com/apps
2. Create a new app or select existing app
3. Enable "Incoming Webhooks"
4. Add webhook to desired channel
5. Copy webhook URL

### Configuration

Add webhook URL to `.env`:

```env
CHAPERONE_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXX
```

### Slack Message Format

Slack notifications use rich message formatting:

- **Content** - Alert type and emoji indicator
- **Title** - Job class or service name
- **Color** - Red for errors, orange for warnings
- **Fields** - Structured key-value data
- **Footer** - "Chaperone Job Supervision"
- **Timestamp** - Unix timestamp for message

### Example Slack Alert

```
🚨 Circuit Breaker Opened

Service: payment-gateway
━━━━━━━━━━━━━━━━━━━━━━━━━━
Failure Count: 10
Opened At: 2024-01-15 14:23:45
Status: Service Unavailable

Chaperone Job Supervision • Just now
```

### Channel Routing

Route different alert types to different Slack channels:

```php
use Cline\Chaperone\Notifications\JobStuckNotification;
use Illuminate\Notifications\Notification;

class CustomJobStuckNotification extends JobStuckNotification
{
    public function toSlack(mixed $notifiable): SlackMessage
    {
        $channel = $this->getSeverityChannel();

        return (new SlackMessage())
            ->to($channel)
            ->error()
            ->content('Job Stuck Alert')
            ->attachment(function ($attachment): void {
                $attachment
                    ->title(sprintf('Job Stuck: %s', $this->jobClass))
                    ->color('danger')
                    ->fields([
                        'Supervision ID' => $this->supervisionId,
                        'Stuck Duration' => sprintf('%d minutes', $this->stuckDuration / 60000),
                    ]);
            });
    }

    private function getSeverityChannel(): string
    {
        // Route critical jobs to on-call channel
        if (str_contains($this->jobClass, 'Critical')) {
            return '#alerts-critical';
        }

        return '#alerts-jobs';
    }
}
```

## Rate Limiting

### How It Works

Chaperone implements intelligent rate limiting to prevent alert fatigue:

- **Per-Type Tracking** - Each alert type tracked separately
- **Per-Key Tracking** - Each supervision ID or service tracked separately
- **5-Minute Window** - Default rate limit of one alert per 5 minutes
- **Cache-Based** - Uses Laravel's cache for distributed rate limiting

### Implementation

Rate limiting is automatic and built into `AlertDispatcher`:

```php
private function shouldAlert(string $type, string $key): bool
{
    if (! Config::get('chaperone.alerting.enabled', false)) {
        return false;
    }

    // Rate limiting: max 1 alert per type+key per 5 minutes
    $cacheKey = "chaperone:alert_sent:{$type}:{$key}";

    return ! Cache::has($cacheKey);
}

private function recordAlert(string $type, string $key): void
{
    $cacheKey = "chaperone:alert_sent:{$type}:{$key}";

    // Rate limit for 5 minutes
    Cache::put($cacheKey, true, 300);
}
```

### Custom Rate Limiting

Extend `AlertDispatcher` for custom rate limit windows:

```php
namespace App\Alerting;

use Cline\Chaperone\Alerting\AlertDispatcher as BaseDispatcher;
use Illuminate\Support\Facades\Cache;

class CustomAlertDispatcher extends BaseDispatcher
{
    protected function shouldAlert(string $type, string $key): bool
    {
        $cacheKey = "chaperone:alert_sent:{$type}:{$key}";

        // Custom rate limits per alert type
        $rateLimitSeconds = match ($type) {
            'job_stuck' => 600,      // 10 minutes
            'job_timeout' => 300,    // 5 minutes
            'circuit_breaker' => 900, // 15 minutes
            'resource_violation' => 180, // 3 minutes
            default => 300,
        };

        if (Cache::has($cacheKey)) {
            return false;
        }

        Cache::put($cacheKey, true, $rateLimitSeconds);

        return true;
    }
}
```

Bind custom dispatcher in `AppServiceProvider`:

```php
use App\Alerting\CustomAlertDispatcher;
use Cline\Chaperone\Alerting\AlertDispatcher;

public function register(): void
{
    $this->app->singleton(AlertDispatcher::class, function ($app) {
        return new CustomAlertDispatcher($app['events']);
    });
}
```

### Bypassing Rate Limits

Temporarily bypass rate limits for critical debugging:

```php
use Illuminate\Support\Facades\Cache;

// Clear rate limit for specific job
Cache::forget('chaperone:alert_sent:job_stuck:supervision-id-123');

// Clear all Chaperone rate limits
Cache::flush(); // Use with caution in production
```

## Custom Notification Channels

### Creating a Channel

Create a custom notification channel for PagerDuty, Opsgenie, or other services:

```php
namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;

class PagerDutyChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toPagerDuty')) {
            return;
        }

        $message = $notification->toPagerDuty($notifiable);

        Http::post('https://events.pagerduty.com/v2/enqueue', [
            'routing_key' => config('services.pagerduty.routing_key'),
            'event_action' => 'trigger',
            'payload' => [
                'summary' => $message['summary'],
                'severity' => $message['severity'],
                'source' => 'chaperone',
                'custom_details' => $message['details'],
            ],
        ]);
    }
}
```

### Extending Notifications

Add custom channel support to notification classes:

```php
namespace App\Notifications;

use Cline\Chaperone\Notifications\JobStuckNotification as BaseNotification;

class JobStuckNotification extends BaseNotification
{
    public function via(mixed $notifiable): array
    {
        return ['mail', 'slack', PagerDutyChannel::class];
    }

    public function toPagerDuty(mixed $notifiable): array
    {
        return [
            'summary' => "Job Stuck: {$this->jobClass}",
            'severity' => 'critical',
            'details' => [
                'supervision_id' => $this->supervisionId,
                'job_class' => $this->jobClass,
                'stuck_duration_minutes' => $this->stuckDuration / 60000,
                'last_heartbeat' => $this->lastHeartbeat?->format('c'),
            ],
        ];
    }
}
```

### Discord Webhook Channel

Example Discord webhook channel:

```php
namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

class DiscordChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toDiscord')) {
            return;
        }

        $message = $notification->toDiscord($notifiable);

        Http::post(config('services.discord.webhook_url'), [
            'embeds' => [[
                'title' => $message['title'],
                'description' => $message['description'],
                'color' => $message['color'] ?? 15158332, // Red
                'fields' => $message['fields'],
                'footer' => [
                    'text' => 'Chaperone Job Supervision',
                ],
                'timestamp' => now()->toIso8601String(),
            ]],
        ]);
    }
}
```

Add to notification:

```php
public function via(mixed $notifiable): array
{
    return ['mail', 'slack', DiscordChannel::class];
}

public function toDiscord(mixed $notifiable): array
{
    return [
        'title' => "⚠️ Job Timeout: {$this->jobClass}",
        'description' => 'A supervised job has exceeded its timeout limit.',
        'color' => 15158332, // Red
        'fields' => [
            ['name' => 'Supervision ID', 'value' => $this->supervisionId, 'inline' => false],
            ['name' => 'Timeout Limit', 'value' => "{$this->timeoutSeconds}s", 'inline' => true],
            ['name' => 'Actual Duration', 'value' => "{$this->actualDuration}s", 'inline' => true],
        ],
    ];
}
```

## Alert Types and Events

### Job Stuck Alert

Triggered when a job runs without sending heartbeats.

**Event:** `Cline\Chaperone\Events\JobStuck`

**Notification:** `Cline\Chaperone\Notifications\JobStuckNotification`

**Data:**
- Supervision ID
- Job class name
- Stuck duration (milliseconds)
- Last heartbeat timestamp

**Example:**

```php
use Cline\Chaperone\Events\JobStuck;
use Illuminate\Support\Facades\Event;

Event::listen(JobStuck::class, function (JobStuck $event) {
    Log::warning('Job stuck detected', [
        'supervision_id' => $event->supervisionId,
        'stuck_duration' => $event->stuckDuration,
        'last_heartbeat' => $event->lastHeartbeat?->format('Y-m-d H:i:s'),
    ]);
});
```

### Job Timeout Alert

Triggered when a job exceeds its configured timeout limit.

**Event:** `Cline\Chaperone\Events\JobTimeout`

**Notification:** `Cline\Chaperone\Notifications\JobTimeoutNotification`

**Data:**
- Supervision ID
- Job class name
- Timeout limit (seconds)
- Actual duration (seconds)

**Example:**

```php
use Cline\Chaperone\Events\JobTimeout;
use Illuminate\Support\Facades\Event;

Event::listen(JobTimeout::class, function (JobTimeout $event) {
    Log::error('Job timeout detected', [
        'supervision_id' => $event->supervisionId,
        'timeout_seconds' => $event->timeoutSeconds,
        'actual_duration' => $event->actualDuration,
        'exceeded_by' => $event->actualDuration - $event->timeoutSeconds,
    ]);
});
```

### Circuit Breaker Opened Alert

Triggered when a circuit breaker opens due to repeated failures.

**Event:** `Cline\Chaperone\Events\CircuitBreakerOpened`

**Notification:** `Cline\Chaperone\Notifications\CircuitBreakerOpenedNotification`

**Data:**
- Service name
- Failure count
- Opened timestamp

**Example:**

```php
use Cline\Chaperone\Events\CircuitBreakerOpened;
use Illuminate\Support\Facades\Event;

Event::listen(CircuitBreakerOpened::class, function (CircuitBreakerOpened $event) {
    Log::critical('Circuit breaker opened', [
        'service' => $event->service,
        'failure_count' => $event->failureCount,
        'opened_at' => $event->openedAt->format('Y-m-d H:i:s'),
    ]);

    // Trigger incident in your incident management system
    IncidentManager::create([
        'title' => "Circuit Breaker Opened: {$event->service}",
        'severity' => 'critical',
        'service' => $event->service,
    ]);
});
```

### Resource Violation Alert

Triggered when a job exceeds memory, CPU, or other resource limits.

**Event:** `Cline\Chaperone\Events\ResourceViolationDetected`

**Notification:** `Cline\Chaperone\Notifications\ResourceViolationNotification`

**Data:**
- Supervision ID
- Job class name
- Violation type (memory, cpu, timeout)
- Configured limit
- Actual value

**Example:**

```php
use Cline\Chaperone\Events\ResourceViolationDetected;
use Illuminate\Support\Facades\Event;

Event::listen(ResourceViolationDetected::class, function (ResourceViolationDetected $event) {
    Log::warning('Resource violation detected', [
        'supervision_id' => $event->supervisionId,
        'violation_type' => $event->violationType->value,
        'limit' => $event->limit,
        'actual' => $event->actual,
        'exceeded_by' => $event->actual - $event->limit,
    ]);

    // Auto-kill jobs that severely exceed memory limits
    if ($event->violationType->value === 'memory' && $event->actual > $event->limit * 2) {
        $job = SupervisedJob::find($event->jobId);
        $job?->kill();
    }
});
```

## Testing Alerts

### Test Command

Use the built-in test command to validate alert configuration:

```bash
# Test all alert types on all channels
php artisan chaperone:test-alerts

# Test specific alert type
php artisan chaperone:test-alerts stuck
php artisan chaperone:test-alerts timeout
php artisan chaperone:test-alerts circuit-breaker
php artisan chaperone:test-alerts resource

# Test specific channels
php artisan chaperone:test-alerts --channel=mail
php artisan chaperone:test-alerts --channel=slack
php artisan chaperone:test-alerts stuck --channel=mail --channel=slack
```

### Manual Testing

Send test notifications manually:

```php
use Cline\Chaperone\Notifications\JobStuckNotification;
use Illuminate\Support\Facades\Notification;

// Test email notification
Notification::route('mail', 'test@example.com')
    ->notify(new JobStuckNotification(
        supervisionId: 'test-123',
        jobClass: 'App\Jobs\TestJob',
        stuckDuration: 1800000, // 30 minutes
        lastHeartbeat: now()->subMinutes(30),
    ));

// Test Slack notification
Notification::route('slack', config('chaperone.alerting.slack_webhook_url'))
    ->notify(new JobTimeoutNotification(
        supervisionId: 'test-456',
        jobClass: 'App\Jobs\TestJob',
        timeoutSeconds: 300,
        actualDuration: 350,
    ));
```

### Testing in Staging

Create a test job that triggers alerts:

```php
namespace App\Jobs;

use Cline\Chaperone\Contracts\Supervised;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class TestAlertJob implements ShouldQueue, Supervised
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $supervisionId;

    public int $timeout = 10; // Short timeout for testing
    public int $memoryLimit = 64; // Low limit for testing

    public function __construct()
    {
        $this->supervisionId = (string) Str::uuid();
    }

    public function handle(): void
    {
        $this->heartbeat(['status' => 'started']);

        // Simulate stuck job (no heartbeats)
        sleep(600); // 10 minutes - will trigger timeout

        $this->heartbeat(['status' => 'completed']);
    }

    public function heartbeat(array $metadata = []): void {}
    public function reportProgress(int $current, int $total, array $metadata = []): void {}
    public function getSupervisionId(): string { return $this->supervisionId; }
}
```

Dispatch and verify alerts:

```bash
php artisan tinker
>>> App\Jobs\TestAlertJob::dispatch();
>>> # Wait for timeout alert to fire
```

## Production Best Practices

### 1. Configure Appropriate Recipients

Use distribution lists or on-call rotation emails:

```env
# Good - distribution list
CHAPERONE_ALERT_RECIPIENTS=oncall@example.com,devops-alerts@example.com

# Bad - individual developer emails
CHAPERONE_ALERT_RECIPIENTS=john@example.com,jane@example.com
```

### 2. Separate Alert Channels by Severity

Route different severity levels to different channels:

```php
namespace App\Providers;

use Cline\Chaperone\Alerting\AlertDispatcher;
use Illuminate\Support\ServiceProvider;

class AlertingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Critical alerts to PagerDuty
        Event::listen(
            \Cline\Chaperone\Events\CircuitBreakerOpened::class,
            fn ($event) => $this->sendToPagerDuty($event),
        );

        // Warning alerts to Slack
        Event::listen(
            \Cline\Chaperone\Events\ResourceViolationDetected::class,
            fn ($event) => $this->sendToSlack($event),
        );

        // Info alerts to email
        Event::listen(
            \Cline\Chaperone\Events\JobStuck::class,
            fn ($event) => $this->sendToEmail($event),
        );
    }

    private function sendToPagerDuty($event): void
    {
        // PagerDuty integration logic
    }

    private function sendToSlack($event): void
    {
        // Slack integration logic
    }

    private function sendToEmail($event): void
    {
        // Email integration logic
    }
}
```

### 3. Implement Alert Aggregation

Aggregate multiple alerts into digest emails:

```php
namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class AlertAggregator
{
    public function aggregate(string $type, array $data): void
    {
        $key = "chaperone:alerts:aggregated:{$type}";

        $alerts = Cache::get($key, []);
        $alerts[] = array_merge($data, ['timestamp' => now()]);

        Cache::put($key, $alerts, 300); // 5 minutes

        // Send digest if threshold reached
        if (count($alerts) >= 10) {
            $this->sendDigest($type, $alerts);
            Cache::forget($key);
        }
    }

    private function sendDigest(string $type, array $alerts): void
    {
        Mail::to(config('chaperone.alerting.recipients'))
            ->send(new AlertDigestMail($type, $alerts));
    }
}
```

### 4. Monitor Alert Delivery

Track alert delivery success and failures:

```php
namespace App\Listeners;

use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Facades\Log;

class MonitorAlertDelivery
{
    public function handleSent(NotificationSent $event): void
    {
        Log::info('Alert delivered', [
            'channel' => $event->channel,
            'notification' => class_basename($event->notification),
            'notifiable' => get_class($event->notifiable),
        ]);
    }

    public function handleFailed(NotificationFailed $event): void
    {
        Log::error('Alert delivery failed', [
            'channel' => $event->channel,
            'notification' => class_basename($event->notification),
            'data' => $event->data,
        ]);

        // Retry via fallback channel
        if ($event->channel === 'slack') {
            Notification::route('mail', config('chaperone.alerting.recipients'))
                ->notify($event->notification);
        }
    }
}
```

Register listeners in `EventServiceProvider`:

```php
protected $listen = [
    NotificationSent::class => [
        MonitorAlertDelivery::class.'@handleSent',
    ],
    NotificationFailed::class => [
        MonitorAlertDelivery::class.'@handleFailed',
    ],
];
```

### 5. Configure Alert Thresholds

Set appropriate thresholds to balance signal and noise:

```env
# Alert if error rate exceeds 10%
CHAPERONE_ALERT_ERROR_RATE=10

# Alert if average execution time exceeds 5 minutes
CHAPERONE_ALERT_RESPONSE_TIME=300

# Alert if queue length exceeds 1000 jobs
CHAPERONE_ALERT_QUEUE_LENGTH=1000
```

### 6. Implement Runbook Links

Add runbook links to alert notifications:

```php
namespace App\Notifications;

use Cline\Chaperone\Notifications\JobStuckNotification as BaseNotification;

class JobStuckNotification extends BaseNotification
{
    public function toMail(mixed $notifiable): MailMessage
    {
        return parent::toMail($notifiable)
            ->line('')
            ->line('**Runbook:** https://docs.example.com/runbooks/job-stuck')
            ->action('View Runbook', 'https://docs.example.com/runbooks/job-stuck');
    }

    public function toSlack(mixed $notifiable): SlackMessage
    {
        return parent::toSlack($notifiable)
            ->attachment(function ($attachment): void {
                $attachment->action('View Runbook', 'https://docs.example.com/runbooks/job-stuck');
            });
    }
}
```

## On-Call Rotation Integration

### PagerDuty Integration

Integrate with PagerDuty for on-call rotations:

```php
namespace App\Services;

use Illuminate\Support\Facades\Http;

class PagerDutyService
{
    public function triggerIncident(array $data): void
    {
        Http::post('https://events.pagerduty.com/v2/enqueue', [
            'routing_key' => config('services.pagerduty.routing_key'),
            'event_action' => 'trigger',
            'dedup_key' => $data['supervision_id'],
            'payload' => [
                'summary' => $data['summary'],
                'severity' => $data['severity'],
                'source' => 'chaperone',
                'component' => 'queue-jobs',
                'custom_details' => $data['details'],
            ],
            'links' => [
                [
                    'href' => config('app.url').'/chaperone/jobs/'.$data['supervision_id'],
                    'text' => 'View Job Details',
                ],
            ],
        ]);
    }

    public function resolveIncident(string $supervisionId): void
    {
        Http::post('https://events.pagerduty.com/v2/enqueue', [
            'routing_key' => config('services.pagerduty.routing_key'),
            'event_action' => 'resolve',
            'dedup_key' => $supervisionId,
        ]);
    }
}
```

Use in event listeners:

```php
use Cline\Chaperone\Events\JobStuck;
use App\Services\PagerDutyService;

Event::listen(JobStuck::class, function (JobStuck $event) {
    app(PagerDutyService::class)->triggerIncident([
        'supervision_id' => $event->supervisionId,
        'summary' => "Job Stuck: {$event->jobClass}",
        'severity' => 'error',
        'details' => [
            'stuck_duration_minutes' => $event->stuckDuration / 60000,
            'last_heartbeat' => $event->lastHeartbeat?->format('c'),
        ],
    ]);
});
```

### Opsgenie Integration

Integrate with Opsgenie:

```php
namespace App\Services;

use Illuminate\Support\Facades\Http;

class OpsgenieService
{
    public function createAlert(array $data): void
    {
        Http::withHeaders([
            'Authorization' => 'GenieKey '.config('services.opsgenie.api_key'),
        ])->post('https://api.opsgenie.com/v2/alerts', [
            'message' => $data['message'],
            'alias' => $data['supervision_id'],
            'description' => $data['description'],
            'priority' => $data['priority'],
            'source' => 'Chaperone',
            'tags' => ['chaperone', 'queue-jobs'],
            'details' => $data['details'],
        ]);
    }

    public function closeAlert(string $supervisionId): void
    {
        Http::withHeaders([
            'Authorization' => 'GenieKey '.config('services.opsgenie.api_key'),
        ])->post("https://api.opsgenie.com/v2/alerts/{$supervisionId}/close", [
            'source' => 'Chaperone',
            'note' => 'Job completed successfully',
        ]);
    }
}
```

## Alert Fatigue Prevention

### 1. Use Intelligent Rate Limiting

Implement escalating rate limits:

```php
namespace App\Alerting;

use Illuminate\Support\Facades\Cache;

class EscalatingRateLimiter
{
    public function shouldAlert(string $type, string $key): bool
    {
        $countKey = "chaperone:alert_count:{$type}:{$key}";
        $count = Cache::get($countKey, 0);

        // Escalating delays: 5min, 15min, 30min, 1hr
        $delay = match (true) {
            $count === 0 => 0,
            $count === 1 => 300,      // 5 minutes
            $count === 2 => 900,      // 15 minutes
            $count === 3 => 1800,     // 30 minutes
            default => 3600,          // 1 hour
        };

        $lastAlertKey = "chaperone:last_alert:{$type}:{$key}";
        $lastAlert = Cache::get($lastAlertKey);

        if ($lastAlert && now()->timestamp - $lastAlert < $delay) {
            return false;
        }

        Cache::put($countKey, $count + 1, 86400); // 24 hours
        Cache::put($lastAlertKey, now()->timestamp, 86400);

        return true;
    }

    public function reset(string $type, string $key): void
    {
        Cache::forget("chaperone:alert_count:{$type}:{$key}");
        Cache::forget("chaperone:last_alert:{$type}:{$key}");
    }
}
```

### 2. Implement Alert Suppression Windows

Suppress alerts during maintenance windows:

```php
namespace App\Services;

use Illuminate\Support\Facades\Cache;

class MaintenanceWindow
{
    public function isInMaintenanceWindow(): bool
    {
        return Cache::get('chaperone:maintenance_mode', false);
    }

    public function start(int $durationMinutes): void
    {
        Cache::put('chaperone:maintenance_mode', true, $durationMinutes * 60);
    }

    public function end(): void
    {
        Cache::forget('chaperone:maintenance_mode');
    }
}
```

Use in alert dispatcher:

```php
private function shouldAlert(string $type, string $key): bool
{
    if (app(MaintenanceWindow::class)->isInMaintenanceWindow()) {
        return false;
    }

    return parent::shouldAlert($type, $key);
}
```

### 3. Group Related Alerts

Group alerts from the same job class:

```php
namespace App\Services;

use Illuminate\Support\Facades\Cache;

class AlertGrouper
{
    public function shouldSendGrouped(string $jobClass): bool
    {
        $groupKey = "chaperone:alert_group:{$jobClass}";
        $alerts = Cache::get($groupKey, []);

        $alerts[] = now();
        Cache::put($groupKey, $alerts, 600); // 10 minutes

        // Send grouped alert if 5+ alerts in 10 minutes
        if (count($alerts) >= 5) {
            $this->sendGroupedAlert($jobClass, count($alerts));
            Cache::forget($groupKey);
            return true;
        }

        return false;
    }

    private function sendGroupedAlert(string $jobClass, int $count): void
    {
        Notification::route('mail', config('chaperone.alerting.recipients'))
            ->notify(new GroupedAlertNotification($jobClass, $count));
    }
}
```

### 4. Implement Alert Prioritization

Prioritize alerts based on job importance:

```php
namespace App\Alerting;

trait AlertPriority
{
    protected function getAlertPriority(): string
    {
        // Critical jobs get immediate alerts
        if (in_array($this->jobClass, config('chaperone.critical_jobs', []))) {
            return 'critical';
        }

        // High-value jobs get high priority
        if (str_contains($this->jobClass, 'Payment') || str_contains($this->jobClass, 'Order')) {
            return 'high';
        }

        // Background jobs get low priority
        if (str_contains($this->jobClass, 'Cleanup') || str_contains($this->jobClass, 'Archive')) {
            return 'low';
        }

        return 'normal';
    }

    protected function shouldSendImmediately(): bool
    {
        return $this->getAlertPriority() === 'critical';
    }
}
```

## Monitoring Alert System Health

### Alert Delivery Metrics

Track alert delivery metrics:

```php
namespace App\Metrics;

use Illuminate\Support\Facades\DB;

class AlertMetrics
{
    public function getDeliveryStats(): array
    {
        return [
            'total_sent' => Cache::get('chaperone:alerts:sent:total', 0),
            'total_failed' => Cache::get('chaperone:alerts:failed:total', 0),
            'by_channel' => [
                'mail' => Cache::get('chaperone:alerts:sent:mail', 0),
                'slack' => Cache::get('chaperone:alerts:sent:slack', 0),
            ],
            'by_type' => [
                'stuck' => Cache::get('chaperone:alerts:sent:stuck', 0),
                'timeout' => Cache::get('chaperone:alerts:sent:timeout', 0),
                'circuit_breaker' => Cache::get('chaperone:alerts:sent:circuit_breaker', 0),
                'resource' => Cache::get('chaperone:alerts:sent:resource', 0),
            ],
        ];
    }

    public function recordSent(string $type, string $channel): void
    {
        Cache::increment('chaperone:alerts:sent:total');
        Cache::increment("chaperone:alerts:sent:{$channel}");
        Cache::increment("chaperone:alerts:sent:{$type}");
    }

    public function recordFailed(string $type, string $channel): void
    {
        Cache::increment('chaperone:alerts:failed:total');
        Cache::increment("chaperone:alerts:failed:{$channel}");
        Cache::increment("chaperone:alerts:failed:{$type}");
    }
}
```

### Health Check Endpoint

Create health check endpoint for alert system:

```php
namespace App\Http\Controllers;

use App\Metrics\AlertMetrics;
use Illuminate\Http\JsonResponse;

class AlertHealthController extends Controller
{
    public function __invoke(AlertMetrics $metrics): JsonResponse
    {
        $stats = $metrics->getDeliveryStats();
        $failureRate = $stats['total_sent'] > 0
            ? ($stats['total_failed'] / $stats['total_sent']) * 100
            : 0;

        return response()->json([
            'healthy' => $failureRate < 5, // Less than 5% failure rate
            'stats' => $stats,
            'failure_rate' => round($failureRate, 2),
        ]);
    }
}
```

## Next Steps

- **[Events](events.md)** - Listen to supervision lifecycle events and create custom handlers
- **[Advanced Usage](advanced-usage.md)** - Worker pools, deployment coordination, and distributed supervision
- **[Health Monitoring](health-monitoring.md)** - Advanced health checks and automated recovery strategies
- **[Production Deployment](production-deployment.md)** - Deploy Chaperone to production with confidence
