<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Database\Models;

use Cline\VariableKeys\Database\Concerns\HasVariablePrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Override;

/**
 * Eloquent model representing job heartbeats.
 *
 * Stores periodic health signals from supervised jobs, including resource
 * usage metrics and progress information. Heartbeats enable monitoring of
 * job health and detection of stalled or unresponsive jobs.
 *
 * Each heartbeat captures a snapshot of the job's state at a specific point
 * in time, tracking memory and CPU usage, as well as progress indicators.
 * This enables trend analysis and early detection of resource issues.
 *
 * @property null|float                $cpu_usage         CPU usage percentage
 * @property mixed                     $id                Primary key (auto-increment, UUID, or ULID)
 * @property null|int                  $memory_usage      Memory usage in bytes
 * @property null|array<string, mixed> $metadata          Additional heartbeat data
 * @property null|int                  $progress_current  Current progress value
 * @property null|int                  $progress_total    Total progress value
 * @property Carbon                    $recorded_at       When heartbeat was recorded
 * @property mixed                     $supervised_job_id Foreign key to supervised job
 * @property SupervisedJob             $supervisedJob     Related supervised job
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class Heartbeat extends Model
{
    use HasVariablePrimaryKey;
    use HasFactory;

    /**
     * Indicates if the model should be timestamped.
     *
     * Disabled because the model uses a custom timestamp field (recorded_at)
     * instead of Laravel's standard created_at and updated_at fields.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * Allows bulk assignment of heartbeat fields during creation.
     * All resource and progress fields are fillable to support various
     * monitoring scenarios.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'supervised_job_id',
        'recorded_at',
        'memory_usage',
        'cpu_usage',
        'progress_current',
        'progress_total',
        'metadata',
    ];

    /**
     * Get the table name from configuration.
     *
     * Retrieves the configured table name from chaperone.table_names.heartbeats,
     * defaulting to 'heartbeats' if not configured. Allows customization of the
     * table name without modifying the model.
     *
     * @return string The configured table name for heartbeat storage
     */
    #[Override()]
    public function getTable(): string
    {
        /** @var string */
        return Config::get('chaperone.table_names.heartbeats', 'heartbeats');
    }

    /**
     * Get the supervised job this heartbeat belongs to.
     *
     * Defines a many-to-one relationship to the supervised job that generated
     * this heartbeat. Each heartbeat is associated with exactly one supervised job.
     *
     * @return BelongsTo<SupervisedJob, $this> Relation to the supervised job
     */
    public function supervisedJob(): BelongsTo
    {
        /** @var class-string<SupervisedJob> $model */
        $model = Config::get('chaperone.models.supervised_job', SupervisedJob::class);

        return $this->belongsTo($model, 'supervised_job_id');
    }

    /**
     * Get the attribute casting configuration.
     *
     * Configures automatic casting of the recorded_at column to Carbon instance
     * for convenient date/time manipulation. Metadata is cast to array for easy
     * access to additional heartbeat information.
     *
     * @return array<string, string> Attribute casting map
     */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'memory_usage' => 'integer',
            'cpu_usage' => 'decimal:2',
            'progress_current' => 'integer',
            'progress_total' => 'integer',
            'metadata' => 'array',
        ];
    }
}
