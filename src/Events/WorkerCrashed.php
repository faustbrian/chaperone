<?php declare(strict_types=1);

namespace Cline\Chaperone\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final readonly class WorkerCrashed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public string $poolName,
        public string $workerId,
        public ?int $pid,
        public ?string $exitCode,
    ) {}
}
