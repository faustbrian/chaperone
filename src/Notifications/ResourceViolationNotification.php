<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;

use function ucfirst;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class ResourceViolationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $supervisionId,
        private readonly string $violationType,
        private readonly float $limit,
        private readonly float $actual,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['mail', 'slack'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return new MailMessage()
            ->warning()
            ->subject('Resource Violation: '.$this->violationType)
            ->line('A supervised job has exceeded its resource limit.')
            ->line('Supervision ID: '.$this->supervisionId)
            ->line('Violation Type: '.$this->violationType)
            ->line('Limit: '.$this->limit)
            ->line('Actual: '.$this->actual);
    }

    public function toSlack(mixed $notifiable): SlackMessage
    {
        return new SlackMessage()
            ->warning()
            ->content('⚡ Resource Violation Alert')
            ->attachment(function ($attachment): void {
                $attachment
                    ->title(ucfirst($this->violationType).' Limit Exceeded')
                    ->fields([
                        'Supervision ID' => $this->supervisionId,
                        'Limit' => (string) $this->limit,
                        'Actual' => (string) $this->actual,
                    ]);
            });
    }
}
