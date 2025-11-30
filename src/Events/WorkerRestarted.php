<?php declare(strict_types=1);

namespace Cline\Chaperone\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final readonly class WorkerRestarted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public string $poolName,
        public string $workerId,
        public string $reason,
    ) {}
}
