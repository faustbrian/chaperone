<?php declare(strict_types=1);

namespace Cline\Chaperone\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;

final class JobTimeoutNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $supervisionId,
        private readonly string $jobClass,
        private readonly int $timeoutSeconds,
        private readonly int $actualDuration,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['mail', 'slack'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return new MailMessage()
            ->error()
            ->subject('Job Timeout: ' . $this->jobClass)
            ->line("A supervised job has exceeded its timeout limit.")
            ->line('Job Class: ' . $this->jobClass)
            ->line('Supervision ID: ' . $this->supervisionId)
            ->line(sprintf('Timeout Limit: %d seconds', $this->timeoutSeconds))
            ->line(sprintf('Actual Duration: %d seconds', $this->actualDuration));
    }

    public function toSlack(mixed $notifiable): SlackMessage
    {
        return new SlackMessage()
            ->error()
            ->content("⏱️ Job Timeout Alert")
            ->attachment(function ($attachment): void {
                $attachment
                    ->title($this->jobClass)
                    ->fields([
                        'Supervision ID' => $this->supervisionId,
                        'Timeout Limit' => $this->timeoutSeconds . 's',
                        'Actual Duration' => $this->actualDuration . 's',
                    ]);
            });
    }
}
