<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Concerns;

use Cline\Chaperone\Supervisors\HeartbeatMonitor;
use Illuminate\Support\Str;

use function array_merge;
use function resolve;
use function round;

/**
 * Enables Laravel queue jobs to be automatically supervised by Chaperone.
 *
 * Provides heartbeat reporting, progress tracking, and unique supervision identifiers
 * for jobs under Chaperone's supervision. The trait automatically generates a unique
 * supervision ID when the job is constructed, allowing the supervisor to track the
 * job's lifecycle, detect stuck jobs, and monitor execution progress.
 *
 * Jobs using this trait can report heartbeats during long-running operations to
 * signal they are still actively processing. Progress reporting allows jobs to
 * communicate their completion percentage along with custom metadata for monitoring
 * and observability purposes.
 *
 * ```php
 * use Cline\Chaperone\Concerns\Supervised;
 * use Illuminate\Contracts\Queue\ShouldQueue;
 *
 * class ImportUsers implements ShouldQueue
 * {
 *     use Supervised;
 *
 *     public function handle(): void
 *     {
 *         $users = User::cursor();
 *         $total = User::count();
 *
 *         foreach ($users as $index => $user) {
 *             $this->processUser($user);
 *
 *             $this->heartbeat(['current_user' => $user->email]);
 *             $this->reportProgress($index + 1, $total);
 *         }
 *     }
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @phpstan-ignore trait.unused
 */
trait Supervised
{
    /**
     * Unique identifier for this supervision session.
     */
    private string $supervisionId;

    /**
     * Initialize supervision ID on job construction.
     *
     * Automatically called when the job is instantiated, generating a unique
     * UUID for tracking this specific job execution throughout its lifecycle.
     */
    public function __construct()
    {
        $this->supervisionId = Str::uuid()->toString();
    }

    /**
     * Record a heartbeat signal for this supervised job.
     *
     * Sends a heartbeat to the supervision system, indicating the job is still
     * actively processing. Use this method within long-running loops or operations
     * to prevent the job from being marked as stuck. Metadata can include any
     * contextual information useful for monitoring and debugging.
     *
     * ```php
     * $this->heartbeat([
     *     'current_user' => $user->email,
     *     'iteration' => $index,
     *     'memory_usage' => memory_get_usage(true),
     * ]);
     * ```
     *
     * @param array<string, mixed> $metadata Contextual information about the current job state
     */
    public function heartbeat(array $metadata = []): void
    {
        resolve(HeartbeatMonitor::class)->recordHeartbeat($this->supervisionId, $metadata);
    }

    /**
     * Report progress for this supervised job.
     *
     * Updates the job's progress by recording the current position relative to
     * the total expected work. Progress information is stored as metadata in the
     * heartbeat system, allowing supervisors and monitoring tools to track job
     * completion percentage and estimate remaining execution time.
     *
     * ```php
     * $total = 1000;
     * foreach ($items as $index => $item) {
     *     $this->processItem($item);
     *     $this->reportProgress($index + 1, $total, [
     *         'current_item' => $item->id,
     *         'success_count' => $successCount,
     *         'error_count' => $errorCount,
     *     ]);
     * }
     * ```
     *
     * @param int                  $current  Current position in the work (e.g., 50 items processed)
     * @param int                  $total    Total work to be completed (e.g., 100 items total)
     * @param array<string, mixed> $metadata Additional contextual information about the progress
     */
    public function reportProgress(int $current, int $total, array $metadata = []): void
    {
        $progressMetadata = array_merge($metadata, [
            'progress_current' => $current,
            'progress_total' => $total,
            'progress_percentage' => $total > 0 ? round(($current / $total) * 100, 2) : 0,
        ]);

        $this->heartbeat($progressMetadata);
    }

    /**
     * Get the unique supervision identifier for this job instance.
     *
     * Returns the UUID assigned to this specific job execution. This identifier
     * is used throughout the supervision system to track heartbeats, monitor
     * health checks, and correlate events related to this job.
     *
     * @return string The supervision session identifier (UUID format)
     */
    public function getSupervisionId(): string
    {
        return $this->supervisionId;
    }
}
