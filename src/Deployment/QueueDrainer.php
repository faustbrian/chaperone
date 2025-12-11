<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Deployment;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class QueueDrainer
{
    public function drain(array $queues): void
    {
        foreach ($queues as $queue) {
            Cache::put('chaperone:queue_paused:'.$queue, true, 3_600);
        }

        // Pause queues using Laravel's queue system
        foreach ($queues as $queue) {
            Artisan::call('queue:pause', ['--queue' => $queue]);
        }
    }

    public function resume(array $queues): void
    {
        foreach ($queues as $queue) {
            Cache::forget('chaperone:queue_paused:'.$queue);
        }

        foreach ($queues as $queue) {
            Artisan::call('queue:resume', ['--queue' => $queue]);
        }
    }

    public function isPaused(string $queue): bool
    {
        return Cache::has('chaperone:queue_paused:'.$queue);
    }
}
