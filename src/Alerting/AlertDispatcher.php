<?php declare(strict_types=1);

namespace Cline\Chaperone\Alerting;

use Cline\Chaperone\Events\CircuitBreakerOpened;
use Cline\Chaperone\Events\JobStuck;
use Cline\Chaperone\Events\JobTimeout;
use Cline\Chaperone\Events\ResourceViolationDetected;
use Cline\Chaperone\Notifications\CircuitBreakerOpenedNotification;
use Cline\Chaperone\Notifications\JobStuckNotification;
use Cline\Chaperone\Notifications\JobTimeoutNotification;
use Cline\Chaperone\Notifications\ResourceViolationNotification;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;

final readonly class AlertDispatcher
{
    public function __construct(Dispatcher $events)
    {
        $this->registerListeners($events);
    }

    private function registerListeners(Dispatcher $events): void
    {
        $events->listen(
            JobStuck::class,
            function (JobStuck $event): void {
                $job = $this->getJob($event->jobId);
                if ($job !== null) {
                    $this->sendJobStuckAlert(
                        $event->supervisionId,
                        $job->job_class,
                        $event->stuckDuration,
                        $event->lastHeartbeat,
                    );
                }
            },
        );

        $events->listen(
            JobTimeout::class,
            function (JobTimeout $event): void {
                $job = $this->getJob($event->jobId);
                if ($job !== null) {
                    $this->sendJobTimeoutAlert(
                        $event->supervisionId,
                        $job->job_class,
                        $event->timeoutSeconds,
                        $event->actualDuration,
                    );
                }
            },
        );

        $events->listen(
            CircuitBreakerOpened::class,
            fn (CircuitBreakerOpened $event) => $this->sendCircuitBreakerOpenedAlert(
                $event->service,
                $event->failureCount,
                $event->openedAt,
            ),
        );

        $events->listen(
            ResourceViolationDetected::class,
            function (ResourceViolationDetected $event): void {
                $job = $this->getJob($event->jobId);
                if ($job !== null) {
                    $this->sendResourceViolationAlert(
                        $event->supervisionId,
                        $job->job_class,
                        $event->violationType->value,
                        $event->limit,
                        $event->actual,
                    );
                }
            },
        );
    }

    public function sendJobStuckAlert(
        string $supervisionId,
        string $jobClass,
        int $stuckDuration,
        ?\DateTimeInterface $lastHeartbeat,
    ): void {
        if (! $this->shouldAlert('job_stuck', $supervisionId)) {
            return;
        }

        $notification = new JobStuckNotification(
            $supervisionId,
            $jobClass,
            $stuckDuration,
            $lastHeartbeat,
        );

        $this->dispatch($notification);
        $this->recordAlert('job_stuck', $supervisionId);
    }

    public function sendJobTimeoutAlert(
        string $supervisionId,
        string $jobClass,
        int $timeoutSeconds,
        int $actualDuration,
    ): void {
        if (! $this->shouldAlert('job_timeout', $supervisionId)) {
            return;
        }

        $notification = new JobTimeoutNotification(
            $supervisionId,
            $jobClass,
            $timeoutSeconds,
            $actualDuration,
        );

        $this->dispatch($notification);
        $this->recordAlert('job_timeout', $supervisionId);
    }

    public function sendCircuitBreakerOpenedAlert(
        string $service,
        int $failureCount,
        \DateTimeInterface $openedAt,
    ): void {
        if (! $this->shouldAlert('circuit_breaker', $service)) {
            return;
        }

        $notification = new CircuitBreakerOpenedNotification(
            $service,
            $failureCount,
            $openedAt,
        );

        $this->dispatch($notification);
        $this->recordAlert('circuit_breaker', $service);
    }

    public function sendResourceViolationAlert(
        string $supervisionId,
        string $jobClass,
        string $violationType,
        float $limit,
        float $actual,
    ): void {
        if (! $this->shouldAlert('resource_violation', sprintf('%s:%s', $supervisionId, $violationType))) {
            return;
        }

        $notification = new ResourceViolationNotification(
            $supervisionId,
            $jobClass,
            $violationType,
            $actual,
            $limit,
        );

        $this->dispatch($notification);
        $this->recordAlert('resource_violation', sprintf('%s:%s', $supervisionId, $violationType));
    }

    private function dispatch(mixed $notification): void
    {
        if (! Config::get('chaperone.alerting.enabled', false)) {
            return;
        }

        $recipients = $this->getRecipients();

        if ($recipients === []) {
            return;
        }

        Notification::route('mail', $recipients)
            ->route('slack', Config::get('chaperone.alerting.slack_webhook_url'))
            ->notify($notification);
    }

    private function shouldAlert(string $type, string $key): bool
    {
        if (! Config::get('chaperone.alerting.enabled', false)) {
            return false;
        }

        // Rate limiting: max 1 alert per type+key per 5 minutes
        $cacheKey = sprintf('chaperone:alert_sent:%s:%s', $type, $key);

        return ! Cache::has($cacheKey);
    }

    private function recordAlert(string $type, string $key): void
    {
        $cacheKey = sprintf('chaperone:alert_sent:%s:%s', $type, $key);

        // Rate limit for 5 minutes
        Cache::put($cacheKey, true, 300);
    }

    private function getRecipients(): array
    {
        $recipients = Config::get('chaperone.alerting.recipients', []);

        if (! is_array($recipients)) {
            return [];
        }

        return array_filter($recipients);
    }

    private function getJob(string $jobId): ?object
    {
        $model = Config::get('chaperone.models.supervised_job');

        return $model::find($jobId);
    }
}
