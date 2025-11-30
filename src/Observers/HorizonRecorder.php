<?php declare(strict_types=1);

namespace Cline\Chaperone\Observers;

use Illuminate\Support\Facades\Config;

final class HorizonRecorder
{
    public function recordQueueMetrics(): void
    {
        if (! $this->isEnabled()) {
            return;
        }
        if (! class_exists('Laravel\Horizon\Horizon')) {
            return;
        }
        // Horizon doesn't have a direct API for custom metrics
        // We can tag jobs for Horizon monitoring
    }

    public function recordSupervisionStarted(): void
    {
        if (! $this->isEnabled()) {
            return;
        }
        // Horizon tracks jobs automatically through queue system
    }

    public function recordSupervisionEnded(): void
    {
        if (! $this->isEnabled()) {
            return;
        }
        // Horizon tracks job completion automatically
    }

    private function isEnabled(): bool
    {
        return Config::get('chaperone.monitoring.horizon', false);
    }
}
