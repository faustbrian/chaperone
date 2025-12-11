<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Database\Models;

use Cline\Chaperone\Database\Concerns\HasChaperonePrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Override;

/**
 * Eloquent model representing dead letter queue entries.
 *
 * Stores records of jobs that have permanently failed after exceeding retry attempts
 * or encountering unrecoverable errors. Provides complete failure context including
 * exception details, stack traces, and job payload for later analysis or manual retry.
 *
 * Each dead letter entry maintains a link to its original supervised job (nullable),
 * allowing correlation with the full supervision history while surviving job record
 * deletion. The payload field stores the complete job data needed for retry attempts.
 *
 * @property string                    $exception         Exception class name
 * @property Carbon                    $failed_at         When job was moved to DLQ
 * @property mixed                     $id                Primary key (auto-increment, UUID, or ULID)
 * @property string                    $job_class         Fully qualified job class name
 * @property string                    $message           Exception message
 * @property null|array<string, mixed> $payload           Job payload for retry
 * @property null|Carbon               $retried_at        When job was retried from DLQ
 * @property null|string               $supervised_job_id Foreign key to supervised job
 * @property null|SupervisedJob        $supervisedJob     Associated supervised job
 * @property string                    $trace             Stack trace
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class DeadLetterJob extends Model
{
    use HasChaperonePrimaryKey;
    use HasFactory;

    /**
     * Indicates if the model should be timestamped.
     *
     * Dead letter queue entries do not use standard created_at/updated_at timestamps,
     * instead tracking failed_at and retried_at for more specific lifecycle events.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * Allows bulk assignment of all dead letter entry fields during creation.
     * All fields are fillable as they're populated from supervised job failures.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'supervised_job_id',
        'job_class',
        'exception',
        'message',
        'trace',
        'payload',
        'failed_at',
        'retried_at',
    ];

    /**
     * Get the table name from configuration.
     *
     * Retrieves the configured table name from chaperone.table_names.dead_letter_queue,
     * defaulting to 'dead_letter_queue' if not configured. Allows customization of the
     * table name without modifying the model.
     *
     * @return string The configured table name for dead letter queue storage
     */
    #[Override()]
    public function getTable(): string
    {
        /** @var string */
        return Config::get('chaperone.table_names.dead_letter_queue', 'dead_letter_queue');
    }

    /**
     * Get the supervised job associated with this dead letter entry.
     *
     * Defines a belongs-to relationship to the supervised job that failed and was
     * moved to the dead letter queue. The relationship is nullable as supervised
     * jobs may be deleted while preserving dead letter entries for audit purposes.
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
     * Configures automatic casting of timestamp columns to Carbon instances for
     * convenient date/time manipulation and formatting. Also casts payload to array
     * for easy access to job parameters.
     *
     * @return array<string, string> Attribute casting map
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'failed_at' => 'datetime',
            'retried_at' => 'datetime',
        ];
    }
}
