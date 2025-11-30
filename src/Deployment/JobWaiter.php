<?php declare(strict_types=1);

namespace Cline\Chaperone\Deployment;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Sleep;
use Cline\Chaperone\Database\Models\SupervisedJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;

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

    public function getRemainingCount(array $queues): int
    {
        return $this->getRunningJobs($queues)->count();
    }
}
