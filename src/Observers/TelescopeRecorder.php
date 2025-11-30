<?php declare(strict_types=1);

namespace Cline\Chaperone\Observers;

use Laravel\Telescope\Telescope;
use Illuminate\Support\Facades\Config;

final class TelescopeRecorder
{
    public function recordSupervisionStarted(): void
    {
        if (! $this->isEnabled()) {
            return;
        }
        if (! class_exists(Telescope::class)) {
            return;
        }
        Telescope::recordEvent(
            type: 'chaperone',
        );
    }

    public function recordSupervisionEnded(): void
    {
        if (! $this->isEnabled()) {
            return;
        }
        if (! class_exists(Telescope::class)) {
            return;
        }
        Telescope::recordEvent(
            type: 'chaperone',
        );
    }

    public function recordHeartbeat(): void
    {
        if (! $this->isEnabled()) {
            return;
        }
        if (! class_exists(Telescope::class)) {
            return;
        }
        Telescope::recordEvent(
            type: 'chaperone',
        );
    }

    public function recordStuckJob(): void
    {
        if (! $this->isEnabled()) {
            return;
        }
        if (! class_exists(Telescope::class)) {
            return;
        }
        Telescope::recordEvent(
            type: 'chaperone',
        );
    }

    public function recordCircuitBreakerOpened(): void
    {
        if (! $this->isEnabled()) {
            return;
        }
        if (! class_exists(Telescope::class)) {
            return;
        }
        Telescope::recordEvent(
            type: 'chaperone',
        );
    }

    private function isEnabled(): bool
    {
        return Config::get('chaperone.monitoring.telescope', false);
    }
}
