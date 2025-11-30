<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Supervisors;

use Cline\Chaperone\Events\HeartbeatMissed;
use Cline\Chaperone\Events\HeartbeatReceived;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

use function config;

/**
 * Tracks heartbeats from supervised jobs for stuck job detection.
 *
 * Maintains a registry of active supervision sessions with their last heartbeat
 * timestamps. Detects when jobs miss consecutive heartbeats and fires appropriate
 * events for monitoring and alerting.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class HeartbeatMonitor
{
    /**
     * Cache key prefix for heartbeat data.
     */
    private const string HEARTBEAT_PREFIX = 'chaperone:heartbeat:';

    /**
     * Cache key prefix for missed heartbeat counters.
     */
    private const string MISSED_PREFIX = 'chaperone:missed:';

    /**
     * Cache key for active supervision sessions.
     */
    private const string ACTIVE_SESSIONS_KEY = 'chaperone:active_sessions';

    /**
     * Record a heartbeat from a supervised job.
     *
     * Updates the last heartbeat timestamp and metadata for the supervision session.
     * Resets the missed heartbeat counter and fires HeartbeatReceived event.
     *
     * @param string $supervisionId Unique identifier for supervision session
     * @param array  $metadata      Contextual information from the heartbeat
     */
    public function recordHeartbeat(string $supervisionId, array $metadata = []): void
    {
        $now = Date::now();

        $heartbeatData = [
            'supervision_id' => $supervisionId,
            'last_heartbeat_at' => $now->toIso8601String(),
            'metadata' => $metadata,
            'recorded_at' => $now->toIso8601String(),
        ];

        // Store heartbeat data
        /** @var int $ttl */
        $ttl = config('chaperone.supervision.heartbeat_ttl_seconds', 3600);
        Cache::put(
            self::HEARTBEAT_PREFIX.$supervisionId,
            $heartbeatData,
            $ttl,
        );

        // Reset missed counter
        Cache::forget(self::MISSED_PREFIX.$supervisionId);

        // Add to active sessions
        $this->addToActiveSessions($supervisionId);

        // Fire event
        $heartbeatId = Str::uuid()->toString();
        Event::dispatch(new HeartbeatReceived($supervisionId, $heartbeatId, $metadata));
    }

    /**
     * Check for stuck jobs based on missed heartbeats.
     *
     * Scans all active supervision sessions to detect jobs that have missed
     * their expected heartbeats. Returns collection of stuck jobs with their
     * last known state.
     *
     * @return Collection<int, array{supervision_id: string, missed_count: int, last_heartbeat_at: string, metadata: array}> Collection of stuck jobs
     */
    public function checkForStuckJobs(): Collection
    {
        /** @var array<string> $activeSessions */
        $activeSessions = Cache::get(self::ACTIVE_SESSIONS_KEY, []);

        $stuckJobs = new Collection();

        foreach ($activeSessions as $supervisionId) {
            $heartbeatData = $this->getHeartbeatData($supervisionId);

            if ($heartbeatData === null) {
                continue;
            }

            $missedCount = $this->checkMissedHeartbeat($supervisionId, $heartbeatData);

            /** @var int $threshold */
            $threshold = $heartbeatData['metadata']['missed_heartbeats_threshold']
                ?? config('chaperone.supervision.missed_heartbeats_threshold', 3);

            if ($missedCount >= $threshold) {
                $stuckJobs->push([
                    'supervision_id' => $supervisionId,
                    'missed_count' => $missedCount,
                    'last_heartbeat_at' => $heartbeatData['last_heartbeat_at'],
                    'metadata' => $heartbeatData['metadata'],
                ]);
            }
        }

        return $stuckJobs;
    }

    /**
     * Get heartbeat data for a supervision session.
     *
     * Retrieves the stored heartbeat information including timestamp and metadata.
     *
     * @param  string                                                                                   $supervisionId Supervision session identifier
     * @return null|array{supervision_id: string, last_heartbeat_at: string, metadata: array, recorded_at: string} Heartbeat data or null if not found
     */
    public function getHeartbeatData(string $supervisionId): ?array
    {
        /** @var null|array{supervision_id: string, last_heartbeat_at: string, metadata: array, recorded_at: string} $data */
        $data = Cache::get(self::HEARTBEAT_PREFIX.$supervisionId);

        return $data;
    }

    /**
     * Remove a supervision session from active tracking.
     *
     * Cleans up heartbeat data and removes the session from the active registry.
     * Used when a job completes or is terminated.
     *
     * @param string $supervisionId Supervision session identifier
     */
    public function removeSession(string $supervisionId): void
    {
        // Remove heartbeat data
        Cache::forget(self::HEARTBEAT_PREFIX.$supervisionId);

        // Remove missed counter
        Cache::forget(self::MISSED_PREFIX.$supervisionId);

        // Remove from active sessions
        /** @var array<string> $activeSessions */
        $activeSessions = Cache::get(self::ACTIVE_SESSIONS_KEY, []);

        $activeSessions = array_filter(
            $activeSessions,
            fn (string $id): bool => $id !== $supervisionId,
        );

        Cache::put(self::ACTIVE_SESSIONS_KEY, $activeSessions);
    }

    /**
     * Get all active supervision sessions.
     *
     * Returns a collection of supervision IDs that are currently being tracked.
     *
     * @return Collection<int, string> Collection of active supervision IDs
     */
    public function getActiveSessions(): Collection
    {
        /** @var array<string> $sessions */
        $sessions = Cache::get(self::ACTIVE_SESSIONS_KEY, []);

        return new Collection($sessions);
    }

    /**
     * Clear all heartbeat data and active sessions.
     *
     * Removes all supervision tracking data from cache. Used for cleanup
     * during testing or system reset.
     */
    public function clearAll(): void
    {
        /** @var array<string> $activeSessions */
        $activeSessions = Cache::get(self::ACTIVE_SESSIONS_KEY, []);

        foreach ($activeSessions as $supervisionId) {
            $this->removeSession($supervisionId);
        }

        Cache::forget(self::ACTIVE_SESSIONS_KEY);
    }

    /**
     * Add supervision session to active tracking.
     *
     * Maintains a registry of all currently supervised jobs for efficient
     * stuck job detection sweeps.
     *
     * @param string $supervisionId Supervision session identifier
     */
    private function addToActiveSessions(string $supervisionId): void
    {
        /** @var array<string> $activeSessions */
        $activeSessions = Cache::get(self::ACTIVE_SESSIONS_KEY, []);

        if (!\in_array($supervisionId, $activeSessions, true)) {
            $activeSessions[] = $supervisionId;
            Cache::put(self::ACTIVE_SESSIONS_KEY, $activeSessions);
        }
    }

    /**
     * Check if a heartbeat has been missed and increment counter.
     *
     * Compares the last heartbeat timestamp against the expected interval.
     * If a heartbeat is missed, increments the missed counter and fires
     * HeartbeatMissed event.
     *
     * @param  string                                                                                   $supervisionId  Supervision session identifier
     * @param  array{supervision_id: string, last_heartbeat_at: string, metadata: array, recorded_at: string} $heartbeatData  Stored heartbeat information
     * @return int                                                                                      Current missed heartbeat count
     */
    private function checkMissedHeartbeat(string $supervisionId, array $heartbeatData): int
    {
        $now = Date::now();
        $lastHeartbeat = Date::parse($heartbeatData['last_heartbeat_at']);

        /** @var int $interval */
        $interval = $heartbeatData['metadata']['heartbeat_interval']
            ?? config('chaperone.supervision.heartbeat_interval_seconds', 30);

        $expectedNextHeartbeat = $lastHeartbeat->addSeconds($interval);

        /** @var int $missedCount */
        $missedCount = Cache::get(self::MISSED_PREFIX.$supervisionId, 0);

        if ($now->greaterThan($expectedNextHeartbeat)) {
            ++$missedCount;

            Cache::put(
                self::MISSED_PREFIX.$supervisionId,
                $missedCount,
                config('chaperone.supervision.heartbeat_ttl_seconds', 3600),
            );

            // Fire event
            $expectedAtImmutable = new DateTimeImmutable($expectedNextHeartbeat->format('c'));
            $missedDuration = (int) $now->diffInMilliseconds($expectedNextHeartbeat);

            Event::dispatch(new HeartbeatMissed(
                $supervisionId,
                $expectedAtImmutable,
                $missedDuration,
            ));
        }

        return $missedCount;
    }
}
