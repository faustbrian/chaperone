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

use function sprintf;

/**
 * @author Brian Faust <brian@cline.sh>
 */
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
            ->subject('Job Timeout: '.$this->jobClass)
            ->line('A supervised job has exceeded its timeout limit.')
            ->line('Job Class: '.$this->jobClass)
            ->line('Supervision ID: '.$this->supervisionId)
            ->line(sprintf('Timeout Limit: %d seconds', $this->timeoutSeconds))
            ->line(sprintf('Actual Duration: %d seconds', $this->actualDuration));
    }

    public function toSlack(mixed $notifiable): SlackMessage
    {
        return new SlackMessage()
            ->error()
            ->content('⏱️ Job Timeout Alert')
            ->attachment(function ($attachment): void {
                $attachment
                    ->title($this->jobClass)
                    ->fields([
                        'Supervision ID' => $this->supervisionId,
                        'Timeout Limit' => $this->timeoutSeconds.'s',
                        'Actual Duration' => $this->actualDuration.'s',
                    ]);
            });
    }
}
