<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Notifications;

use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Date;

use function config;
use function sprintf;

/**
 * Notification sent when a supervised job is detected as stuck.
 *
 * Alerts administrators when a job has been running longer than expected
 * without making progress. Provides details about the stuck job including
 * supervision ID, job class, stuck duration, and last heartbeat timestamp.
 *
 * Supports multiple notification channels including email and Slack for
 * immediate notification of job health issues.
 *
 * ```php
 * // Notify via configured channels
 * $notifiable->notify(new JobStuckNotification(
 *     supervisionId: '12345',
 *     jobClass: ProcessLargeDataset::class,
 *     stuckDuration: 1800000, // 30 minutes in milliseconds
 *     lastHeartbeat: new DateTimeImmutable('2024-01-15 10:30:00'),
 * ));
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class JobStuckNotification extends Notification
{
    use Queueable;

    /**
     * Create a new job stuck notification.
     *
     * @param string                 $supervisionId Unique identifier for the supervision session
     * @param string                 $jobClass      Fully qualified class name of the stuck job
     * @param int                    $stuckDuration How long the job has been stuck in milliseconds
     * @param null|DateTimeImmutable $lastHeartbeat Timestamp of the last heartbeat received, if any
     */
    public function __construct(
        public readonly string $supervisionId,
        public readonly string $jobClass,
        public readonly int $stuckDuration,
        public readonly ?DateTimeImmutable $lastHeartbeat,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return config('chaperone.alerting.channels', ['mail']);
    }

    /**
     * Get the mail representation of the notification.
     *
     * Creates a formatted email alert with details about the stuck job
     * including supervision ID, job class, stuck duration, and last heartbeat.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        $stuckMinutes = (int) ($this->stuckDuration / 60_000);
        $lastHeartbeat = $this->lastHeartbeat?->format('Y-m-d H:i:s') ?? 'Never';

        return new MailMessage()
            ->error()
            ->subject(sprintf('[Chaperone Alert] Job Stuck: %s', $this->jobClass))
            ->greeting('Job Stuck Alert')
            ->line('A supervised job has been detected as stuck and may require intervention.')
            ->line('')
            ->line(sprintf('**Supervision ID:** %s', $this->supervisionId))
            ->line(sprintf('**Job Class:** %s', $this->jobClass))
            ->line(sprintf('**Stuck Duration:** %d minutes', $stuckMinutes))
            ->line(sprintf('**Last Heartbeat:** %s', $lastHeartbeat))
            ->line('')
            ->line('This job may be in an infinite loop, deadlocked, or otherwise unable to complete.')
            ->action('View Job Details', config('app.url').'/chaperone/jobs/'.$this->supervisionId);
    }

    /**
     * Get the Slack representation of the notification.
     *
     * Creates a formatted Slack message with details about the stuck job
     * using the configured webhook URL.
     */
    public function toSlack(mixed $notifiable): SlackMessage
    {
        $this->lastHeartbeat?->format('Y-m-d H:i:s') ?? 'Never';

        return new SlackMessage()
            ->error()
            ->to(config('chaperone.alerting.slack_webhook_url'))
            ->content('Job Stuck Alert')
            ->attachment(function ($attachment): void {
                $attachment
                    ->title(sprintf('Job Stuck: %s', $this->jobClass))
                    ->color('danger')
                    ->fields([
                        'Supervision ID' => $this->supervisionId,
                        'Job Class' => $this->jobClass,
                        'Stuck Duration' => sprintf('%d minutes', (int) ($this->stuckDuration / 60_000)),
                        'Last Heartbeat' => $this->lastHeartbeat?->format('Y-m-d H:i:s') ?? 'Never',
                    ])
                    ->footer('Chaperone Job Supervision')
                    ->timestamp(Date::now()->getTimestamp());
            });
    }
}
