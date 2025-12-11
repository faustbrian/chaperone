<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Deployment;

use Cline\Chaperone\Database\Models\SupervisedJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Sleep;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class JobWaiter
{
    public function waitForJobs(array $queues, int $timeout): bool
    {
        $startTime = Date::now()->getTimestamp();

        while (true) {
            $remaining = $this->getRemainingCount($queues);

            if ($remaining === 0) {
                return true;
            }

            if (Date::now()->getTimestamp() - $startTime >= $timeout) {
                return false;
            }

            Sleep::sleep(5); // Poll every 5 seconds
        }
    }

    public function getRunningJobs(): Collection
    {
        $model = Config::get('chaperone.models.supervised_job', SupervisedJob::class);

        return $model::query()
            ->whereNull('completed_at')
            ->whereNull('failed_at')
            ->get();
    }

    public function getRemainingCount(): int
    {
        return $this->getRunningJobs()->count();
    }
}
