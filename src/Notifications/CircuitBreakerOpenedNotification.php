<?php declare(strict_types=1);

namespace Cline\Chaperone\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;

final class CircuitBreakerOpenedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $service,
        private readonly int $failureCount,
        private readonly \DateTimeInterface $openedAt,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['mail', 'slack'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return new MailMessage()
            ->error()
            ->subject('Circuit Breaker Opened: ' . $this->service)
            ->line("A circuit breaker has been opened due to repeated failures.")
            ->line('Service: ' . $this->service)
            ->line('Failure Count: ' . $this->failureCount)
            ->line('Opened At: ' . $this->openedAt->format('Y-m-d H:i:s'))
            ->line('The service will be temporarily unavailable until recovery.');
    }

    public function toSlack(mixed $notifiable): SlackMessage
    {
        return new SlackMessage()
            ->error()
            ->content("🚨 Circuit Breaker Opened")
            ->attachment(function ($attachment): void {
                $attachment
                    ->title($this->service)
                    ->fields([
                        'Failure Count' => $this->failureCount,
                        'Opened At' => $this->openedAt->format('Y-m-d H:i:s'),
                        'Status' => 'Service Unavailable',
                    ]);
            });
    }
}
