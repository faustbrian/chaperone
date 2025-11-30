<?php declare(strict_types=1);

namespace Cline\Chaperone\Console\Commands;

use Cline\Chaperone\Alerting\AlertDispatcher;
use Cline\Chaperone\Notifications\CircuitBreakerOpenedNotification;
use Cline\Chaperone\Notifications\JobStuckNotification;
use Cline\Chaperone\Notifications\JobTimeoutNotification;
use Cline\Chaperone\Notifications\ResourceViolationNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;

final class TestAlertsCommand extends Command
{
    protected $signature = 'chaperone:test-alerts
                            {type? : Alert type (stuck, timeout, circuit-breaker, resource)}
                            {--channel=* : Channels to test (mail, slack)}';

    protected $description = 'Test alert notifications';

    public function handle(AlertDispatcher $dispatcher): int
    {
        $type = $this->argument('type');
        $channels = $this->option('channel') ?: ['mail', 'slack'];

        if (! $this->validateChannels($channels)) {
            return self::FAILURE;
        }

        $types = $type ? [$type] : ['stuck', 'timeout', 'circuit-breaker', 'resource'];

        foreach ($types as $alertType) {
            $this->sendTestAlert($alertType, $channels);
        }

        $this->info('✓ Test alerts sent successfully');

        return self::SUCCESS;
    }

    private function validateChannels(array $channels): bool
    {
        $valid = ['mail', 'slack'];
        $invalid = array_diff($channels, $valid);

        if ($invalid !== []) {
            $this->error('Invalid channels: '.implode(', ', $invalid));
            $this->line('Valid channels: '.implode(', ', $valid));

            return false;
        }

        foreach ($channels as $channel) {
            if ($channel === 'mail' && ! Config::get('chaperone.alerting.email.enabled')) {
                $this->warn('Email alerting is disabled in config');
            }

            if ($channel === 'slack' && ! Config::get('chaperone.alerting.slack.enabled')) {
                $this->warn('Slack alerting is disabled in config');
            }
        }

        return true;
    }

    private function sendTestAlert(string $type, array $channels): void
    {
        $this->line(sprintf('Sending test %s alert...', $type));

        $notification = match ($type) {
            'stuck' => new JobStuckNotification(
                supervisionId: 'test-'.uniqid(),
                jobClass: 'App\\Jobs\\TestJob',
                lastHeartbeat: now()->subMinutes(10),
                startedAt: now()->subMinutes(15),
            ),
            'timeout' => new JobTimeoutNotification(
                supervisionId: 'test-'.uniqid(),
                jobClass: 'App\\Jobs\\TestJob',
                timeout: 300,
                runtime: 305,
            ),
            'circuit-breaker' => new CircuitBreakerOpenedNotification(
                service: 'test-api',
                failureCount: 10,
                threshold: 5,
            ),
            'resource' => new ResourceViolationNotification(
                supervisionId: 'test-'.uniqid(),
                violationType: 'memory',
                limit: 256.0,
                jobClass: 'App\\Jobs\\TestJob',
                value: 512.5,
            ),
            default => throw new \InvalidArgumentException('Unknown alert type: ' . $type),
        };

        $recipients = $this->getRecipients($channels);

        foreach ($recipients as $recipient) {
            Notification::route('mail', $recipient)
                ->route('slack', Config::get('chaperone.alerting.slack.webhook_url'))
                ->notify($notification);
        }
    }

    private function getRecipients(array $channels): array
    {
        $recipients = [];

        if (in_array('mail', $channels, true)) {
            $recipients = array_merge(
                $recipients,
                Config::get('chaperone.alerting.email.recipients', []),
            );
        }

        return array_unique($recipients);
    }
}
