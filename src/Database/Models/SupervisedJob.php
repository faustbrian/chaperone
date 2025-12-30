<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Database\Models;

use Cline\VariableKeys\Database\Concerns\HasVariablePrimaryKey;
use Cline\Chaperone\Enums\SupervisedJobStatus;
use Cline\Chaperone\Support\MorphKeyValidator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Override;

/**
 * Eloquent model representing supervised jobs.
 *
 * Stores records of all supervised jobs that are monitored by Chaperone,
 * tracking their lifecycle from start to completion or failure. Provides
 * complete visibility into job execution with health metrics and status.
 *
 * Each supervised job record tracks its lifecycle through various timestamp
 * fields (started_at, last_heartbeat_at, completed_at, failed_at), enabling
 * precise monitoring and debugging of job execution. The polymorphic initiator
 * relationship allows tracking which user, system, or entity triggered each job.
 *
 * @property null|Carbon                         $completed_at       When job completed successfully
 * @property Carbon                              $created_at         Record creation timestamp
 * @property null|DeadLetterJob                  $deadLetterJob      Dead letter queue entry if job was moved to DLQ
 * @property Collection<int, SupervisedJobError> $errors             Collection of error records
 * @property null|Carbon                         $failed_at          When job failed
 * @property Collection<int, JobHealthCheck>     $healthChecks       Collection of health check records
 * @property Collection<int, Heartbeat>          $heartbeats         Collection of heartbeat records
 * @property mixed                               $id                 Primary key (auto-increment, UUID, or ULID)
 * @property null|Model                          $initiatedBy        Who initiated this job
 * @property null|string                         $initiator_id       Polymorphic ID of initiator
 * @property null|string                         $initiator_type     Polymorphic type of initiator
 * @property string                              $job_class          Fully qualified job class name
 * @property string                              $job_id             Queue job identifier
 * @property null|Carbon                         $last_heartbeat_at  Last heartbeat received
 * @property null|array<string, mixed>           $metadata           Additional job metadata
 * @property Collection<int, ResourceViolation>  $resourceViolations Collection of resource violation records
 * @property Carbon                              $started_at         When job started execution
 * @property SupervisedJobStatus                 $status             Current job status
 * @property Carbon                              $updated_at         Record update timestamp
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class SupervisedJob extends Model
{
    use HasVariablePrimaryKey;
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * Allows bulk assignment of job lifecycle fields during creation and updates.
     * All timestamp and status fields are fillable to support both automatic and
     * manual job tracking scenarios.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'job_id',
        'job_class',
        'initiator_type',
        'initiator_id',
        'started_at',
        'last_heartbeat_at',
        'completed_at',
        'failed_at',
        'status',
        'metadata',
    ];

    /**
     * Get the table name from configuration.
     *
     * Retrieves the configured table name from chaperone.table_names.supervised_jobs,
     * defaulting to 'supervised_jobs' if not configured. Allows customization of the
     * table name without modifying the model.
     *
     * @return string The configured table name for supervised job storage
     */
    #[Override()]
    public function getTable(): string
    {
        /** @var string */
        return Config::get('chaperone.table_names.supervised_jobs', 'supervised_jobs');
    }

    /**
     * Get the polymorphic initiator of this job.
     *
     * Defines a polymorphic relationship to the entity that initiated this job,
     * which could be a User, System, or any other model. Enables tracking of who
     * or what triggered each job for audit purposes.
     *
     * @return MorphTo<Model, $this> Polymorphic relation to the initiator entity
     */
    public function initiatedBy(): MorphTo
    {
        return $this->morphTo('initiator');
    }

    /**
     * Get all heartbeats for this job.
     *
     * Defines a one-to-many relationship to heartbeat records captured during job
     * execution. Each supervised job can have multiple heartbeat entries tracking
     * its health and resource usage over time.
     *
     * @return HasMany<Heartbeat, $this> Collection of heartbeat records
     */
    public function heartbeats(): HasMany
    {
        /** @var class-string<Heartbeat> $model */
        $model = Config::get('chaperone.models.heartbeat', Heartbeat::class);

        return $this->hasMany($model, 'supervised_job_id');
    }

    /**
     * Get all health checks for this job.
     *
     * Defines a one-to-many relationship to health check records. Each supervised
     * job can have multiple health check entries documenting its health status
     * throughout its execution.
     *
     * @return HasMany<JobHealthCheck, $this> Collection of health check records
     */
    public function healthChecks(): HasMany
    {
        /** @var class-string<JobHealthCheck> $model */
        $model = Config::get('chaperone.models.job_health_check', JobHealthCheck::class);

        return $this->hasMany($model, 'supervised_job_id');
    }

    /**
     * Get all resource violations for this job.
     *
     * Defines a one-to-many relationship to resource violation records. Each
     * supervised job can have multiple violation entries if it exceeds configured
     * resource limits during execution.
     *
     * @return HasMany<ResourceViolation, $this> Collection of resource violation records
     */
    public function resourceViolations(): HasMany
    {
        /** @var class-string<ResourceViolation> $model */
        $model = Config::get('chaperone.models.resource_violation', ResourceViolation::class);

        return $this->hasMany($model, 'supervised_job_id');
    }

    /**
     * Get all errors for this job.
     *
     * Defines a one-to-many relationship to error records. Each supervised
     * job can have multiple error entries documenting failures during execution,
     * including exception details, stack traces, and contextual information.
     *
     * @return HasMany<SupervisedJobError, $this> Collection of error records
     */
    public function errors(): HasMany
    {
        /** @var class-string<SupervisedJobError> $model */
        $model = Config::get('chaperone.models.supervised_job_error', SupervisedJobError::class);

        return $this->hasMany($model, 'supervised_job_id');
    }

    /**
     * Get the dead letter queue entry for this job.
     *
     * Defines a one-to-one relationship to the dead letter job record created
     * when this supervised job permanently failed and was moved to the dead letter
     * queue. The relationship exists only for jobs that exceeded retry attempts.
     *
     * @return HasOne<DeadLetterJob, $this> Dead letter queue entry
     */
    public function deadLetterJob(): HasOne
    {
        /** @var class-string<DeadLetterJob> $model */
        $model = Config::get('chaperone.models.dead_letter_job', DeadLetterJob::class);

        return $this->hasOne($model, 'supervised_job_id');
    }

    /**
     * Boot the model and register event listeners.
     *
     * Sets up a creating event listener to validate that any initiator model
     * used in the polymorphic relationship has proper morph key mapping configured.
     * This prevents storing unmapped models when enforcement is enabled.
     */
    #[Override()]
    protected static function boot(): void
    {
        parent::boot();

        self::creating(function (self $supervisedJob): void {
            // Validate initiator morph key if an initiator is set
            if ($supervisedJob->initiator_type === null || $supervisedJob->initiator_id === null) {
                return;
            }

            /** @var null|Model $initiator */
            $initiator = $supervisedJob->initiatedBy;

            if ($initiator === null) {
                return;
            }

            MorphKeyValidator::validateMorphKey($initiator);
        });
    }

    /**
     * Get the attribute casting configuration.
     *
     * Configures automatic casting of timestamp columns to Carbon instances for
     * convenient date/time manipulation and formatting throughout the application.
     * Also casts the status field to the SupervisedJobStatus enum for type-safe
     * status checking.
     *
     * @return array<string, string> Attribute casting map
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'status' => SupervisedJobStatus::class,
            'metadata' => 'array',
        ];
    }
}
