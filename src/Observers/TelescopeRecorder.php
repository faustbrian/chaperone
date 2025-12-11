<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Observers;

use Illuminate\Support\Facades\Config;
use Laravel\Telescope\Telescope;

use function class_exists;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class TelescopeRecorder
{
    public function recordSupervisionStarted(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if (!class_exists(Telescope::class)) {
            return;
        }

        Telescope::recordEvent(
            type: 'chaperone',
        );
    }

    public function recordSupervisionEnded(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if (!class_exists(Telescope::class)) {
            return;
        }

        Telescope::recordEvent(
            type: 'chaperone',
        );
    }

    public function recordHeartbeat(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if (!class_exists(Telescope::class)) {
            return;
        }

        Telescope::recordEvent(
            type: 'chaperone',
        );
    }

    public function recordStuckJob(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if (!class_exists(Telescope::class)) {
            return;
        }

        Telescope::recordEvent(
            type: 'chaperone',
        );
    }

    public function recordCircuitBreakerOpened(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if (!class_exists(Telescope::class)) {
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
