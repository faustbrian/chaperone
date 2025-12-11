<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Observers;

use Cline\Chaperone\Database\Models\CircuitBreaker;
use Cline\Chaperone\Database\Models\Heartbeat;
use Cline\Chaperone\Database\Models\SupervisedJob;
use Illuminate\Support\Facades\Config;
use Laravel\Pulse\Facades\Pulse;

use function class_exists;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class PulseRecorder
{
    public function recordSupervisionStarted(SupervisedJob $job): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if (!class_exists(Pulse::class)) {
            return;
        }

        Pulse::record(
            type: 'chaperone_supervision_started',
            key: $job->job_class,
            value: 1,
        );
    }

    public function recordSupervisionEnded(SupervisedJob $job): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if (!class_exists(Pulse::class)) {
            return;
        }

        $duration = $job->completed_at?->diffInSeconds($job->started_at) ?? 0;

        Pulse::record(
            type: 'chaperone_supervision_ended',
            key: $job->job_class,
            value: $duration,
        );
    }

    public function recordHeartbeat(Heartbeat $heartbeat): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if (!class_exists(Pulse::class)) {
            return;
        }

        Pulse::record(
            type: 'chaperone_heartbeat',
            key: (string) $heartbeat->supervised_job_id,
            value: $heartbeat->memory_usage ?? 0,
        );
    }

    public function recordStuckJob(SupervisedJob $job): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if (!class_exists(Pulse::class)) {
            return;
        }

        Pulse::record(
            type: 'chaperone_stuck_job',
            key: $job->job_class,
            value: 1,
        );
    }

    public function recordCircuitBreakerOpened(CircuitBreaker $breaker): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if (!class_exists(Pulse::class)) {
            return;
        }

        Pulse::record(
            type: 'chaperone_circuit_breaker_opened',
            key: $breaker->service_name,
            value: $breaker->failure_count,
        );
    }

    private function isEnabled(): bool
    {
        return Config::get('chaperone.monitoring.pulse', false);
    }
}
