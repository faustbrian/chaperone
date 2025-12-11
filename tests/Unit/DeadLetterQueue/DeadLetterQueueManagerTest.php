<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\DeadLetterQueue;

use Cline\Chaperone\Database\Models\DeadLetterJob;
use Cline\Chaperone\Database\Models\SupervisedJob;
use Cline\Chaperone\DeadLetterQueue\DeadLetterQueueManager;
use Cline\Chaperone\Enums\SupervisedJobStatus;
use Cline\Chaperone\Events\JobMovedToDeadLetterQueue;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\Support\TestJob;
use Tests\TestCase;

/**
 * @author Brian Faust <brian@cline.sh>
 * @internal
 */
#[CoversClass(DeadLetterQueueManager::class)]
#[Small()]
final class DeadLetterQueueManagerTest extends TestCase
{
    use RefreshDatabase;

    private DeadLetterQueueManager $manager;

    #[Override()]
    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new DeadLetterQueueManager();
    }

    #[Test()]
    #[TestDox('Creates dead letter queue entry with complete job and exception details')]
    #[Group('happy-path')]
    public function creates_dead_letter_queue_entry_with_complete_details(): void
    {
        // Arrange
        $supervisedJob = SupervisedJob::query()->create([
            'job_id' => 'test-job-123',
            'job_class' => TestJob::class,
            'started_at' => Date::now(),
            'status' => SupervisedJobStatus::Failed,
            'metadata' => ['user_id' => 1, 'action' => 'process_payment'],
        ]);

        $exception = new Exception('Payment gateway timeout');

        // Act
        $this->manager->moveToDeadLetterQueue($supervisedJob, $exception);

        // Assert
        $this->assertDatabaseHas('dead_letter_queue', [
            'supervised_job_id' => $supervisedJob->id,
            'job_class' => TestJob::class,
            'exception' => Exception::class,
            'message' => 'Payment gateway timeout',
        ]);

        $deadLetterJob = DeadLetterJob::query()->first();
        $this->assertInstanceOf(DeadLetterJob::class, $deadLetterJob);
        $this->assertEquals($supervisedJob->id, $deadLetterJob->supervised_job_id);
        $this->assertEquals(['user_id' => 1, 'action' => 'process_payment'], $deadLetterJob->payload);
        $this->assertNotEmpty($deadLetterJob->trace);
        $this->assertIsString($deadLetterJob->trace);
    }

    #[Test()]
    #[TestDox('Dispatches JobMovedToDeadLetterQueue event when job is moved to DLQ')]
    #[Group('happy-path')]
    public function dispatches_event_when_job_moved_to_dead_letter_queue(): void
    {
        // Arrange
        Event::fake();

        $supervisedJob = SupervisedJob::query()->create([
            'job_id' => 'test-job-456',
            'job_class' => TestJob::class,
            'started_at' => Date::now(),
            'status' => SupervisedJobStatus::Failed,
            'metadata' => ['test' => 'data'],
        ]);

        $exception = new Exception('Test exception');

        // Act
        $this->manager->moveToDeadLetterQueue($supervisedJob, $exception);

        // Assert
        Event::assertDispatched(JobMovedToDeadLetterQueue::class, fn ($event): bool => $event->supervisionId === (string) $supervisedJob->id
            && $event->jobClass === TestJob::class
            && $event->exception === $exception
            && $event->failedAt instanceof Carbon);
    }

    #[Test()]
    #[TestDox('Preserves job payload in dead letter queue entry for retry')]
    #[Group('happy-path')]
    public function preserves_job_payload_for_retry(): void
    {
        // Arrange
        $complexPayload = [
            'user_id' => 42,
            'items' => ['item1', 'item2', 'item3'],
            'metadata' => [
                'source' => 'api',
                'timestamp' => '2024-01-15 10:30:00',
            ],
        ];

        $supervisedJob = SupervisedJob::query()->create([
            'job_id' => 'test-job-payload',
            'job_class' => TestJob::class,
            'started_at' => Date::now(),
            'status' => SupervisedJobStatus::Failed,
            'metadata' => $complexPayload,
        ]);

        $exception = new Exception('Job failed');

        // Act
        $this->manager->moveToDeadLetterQueue($supervisedJob, $exception);

        // Assert
        $deadLetterJob = DeadLetterJob::query()->first();
        $this->assertEquals($complexPayload, $deadLetterJob->payload);
    }

    #[Test()]
    #[TestDox('Does not create DLQ entry when dead letter queue is disabled')]
    #[Group('sad-path')]
    public function does_not_create_entry_when_disabled(): void
    {
        // Arrange
        Config::set('chaperone.dead_letter_queue.enabled', false);

        $supervisedJob = SupervisedJob::query()->create([
            'job_id' => 'test-job-disabled',
            'job_class' => TestJob::class,
            'started_at' => Date::now(),
            'status' => SupervisedJobStatus::Failed,
        ]);

        $exception = new Exception('Test exception');

        // Act
        $this->manager->moveToDeadLetterQueue($supervisedJob, $exception);

        // Assert
        $this->assertDatabaseCount('dead_letter_queue', 0);
    }

    #[Test()]
    #[TestDox('Retrieves single dead letter entry by ID')]
    #[Group('happy-path')]
    public function retrieves_single_dead_letter_entry_by_id(): void
    {
        // Arrange
        $supervisedJob = SupervisedJob::query()->create([
            'job_id' => 'test-job-get',
            'job_class' => TestJob::class,
            'started_at' => Date::now(),
            'status' => SupervisedJobStatus::Failed,
        ]);

        $deadLetterJob = DeadLetterJob::query()->create([
            'supervised_job_id' => $supervisedJob->id,
            'job_class' => TestJob::class,
            'exception' => Exception::class,
            'message' => 'Test message',
            'trace' => 'Test trace',
            'payload' => ['test' => 'data'],
            'failed_at' => Date::now(),
        ]);

        // Act
        $result = $this->manager->get((string) $deadLetterJob->id);

        // Assert
        $this->assertNotNull($result);
        $this->assertEquals($deadLetterJob->id, $result['id']);
        $this->assertEquals($supervisedJob->id, $result['supervised_job_id']);
        $this->assertEquals(TestJob::class, $result['job_class']);
        $this->assertEquals(Exception::class, $result['exception']);
        $this->assertEquals('Test message', $result['message']);
        $this->assertEquals(['test' => 'data'], $result['payload']);
    }

    #[Test()]
    #[TestDox('Returns null when dead letter entry does not exist')]
    #[Group('sad-path')]
    public function returns_null_when_entry_does_not_exist(): void
    {
        // Arrange
        $nonExistentId = '999';

        // Act
        $result = $this->manager->get($nonExistentId);

        // Assert
        $this->assertNull($result);
    }

    #[Test()]
    #[TestDox('Retrieves all dead letter entries ordered by most recent first')]
    #[Group('happy-path')]
    public function retrieves_all_entries_ordered_by_most_recent(): void
    {
        // Arrange
        $supervisedJob1 = SupervisedJob::query()->create([
            'job_id' => 'job-1',
            'job_class' => TestJob::class,
            'started_at' => Date::now(),
            'status' => SupervisedJobStatus::Failed,
        ]);

        $supervisedJob2 = SupervisedJob::query()->create([
            'job_id' => 'job-2',
            'job_class' => TestJob::class,
            'started_at' => Date::now(),
            'status' => SupervisedJobStatus::Failed,
        ]);

        DeadLetterJob::query()->create([
            'supervised_job_id' => $supervisedJob1->id,
            'job_class' => TestJob::class,
            'exception' => Exception::class,
            'message' => 'First failure',
            'trace' => 'trace',
            'failed_at' => Date::now()->subHours(2),
        ]);

        DeadLetterJob::query()->create([
            'supervised_job_id' => $supervisedJob2->id,
            'job_class' => TestJob::class,
            'exception' => Exception::class,
            'message' => 'Second failure',
            'trace' => 'trace',
            'failed_at' => Date::now()->subHours(1),
        ]);

        // Act
        $results = $this->manager->all();

        // Assert
        $this->assertCount(2, $results);
        $this->assertEquals('Second failure', $results->first()->message);
        $this->assertEquals('First failure', $results->last()->message);
    }

    #[Test()]
    #[TestDox('Returns total count of dead letter queue entries')]
    #[Group('happy-path')]
    public function returns_total_count_of_entries(): void
    {
        // Arrange
        for ($i = 0; $i < 5; ++$i) {
            $supervisedJob = SupervisedJob::query()->create([
                'job_id' => 'job-'.$i,
                'job_class' => TestJob::class,
                'started_at' => Date::now(),
                'status' => SupervisedJobStatus::Failed,
            ]);

            DeadLetterJob::query()->create([
                'supervised_job_id' => $supervisedJob->id,
                'job_class' => TestJob::class,
                'exception' => Exception::class,
                'message' => 'Message '.$i,
                'trace' => 'trace',
                'failed_at' => Date::now(),
            ]);
        }

        // Act
        $count = $this->manager->count();

        // Assert
        $this->assertSame(5, $count);
    }

    #[Test()]
    #[TestDox('Retries dead letter job by re-dispatching with stored payload')]
    #[Group('happy-path')]
    public function retries_dead_letter_job_by_redispatching(): void
    {
        // Arrange
        $supervisedJob = SupervisedJob::query()->create([
            'job_id' => 'retry-job',
            'job_class' => TestJob::class,
            'started_at' => Date::now(),
            'status' => SupervisedJobStatus::Failed,
        ]);

        $deadLetterJob = DeadLetterJob::query()->create([
            'supervised_job_id' => $supervisedJob->id,
            'job_class' => TestJob::class,
            'exception' => Exception::class,
            'message' => 'Failed job',
            'trace' => 'trace',
            'payload' => ['data' => 'test'],
            'failed_at' => Date::now(),
        ]);

        // Act
        $this->manager->retry((string) $deadLetterJob->id);

        // Assert
        $deadLetterJob->refresh();
        $this->assertInstanceOf(Carbon::class, $deadLetterJob->retried_at);
        $this->assertInstanceOf(Carbon::class, $deadLetterJob->retried_at);
    }

    #[Test()]
    #[TestDox('Throws exception when retrying non-existent dead letter entry')]
    #[Group('sad-path')]
    public function throws_exception_when_retrying_non_existent_entry(): void
    {
        // Arrange
        $nonExistentId = '999';

        // Act
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Dead letter job 999 not found');

        // Assert
        $this->manager->retry($nonExistentId);
    }

    #[Test()]
    #[TestDox('Throws exception when retrying job with non-existent class')]
    #[Group('sad-path')]
    public function throws_exception_when_job_class_does_not_exist(): void
    {
        // Arrange
        $supervisedJob = SupervisedJob::query()->create([
            'job_id' => 'invalid-class-job',
            'job_class' => 'App\\Jobs\\NonExistentJob',
            'started_at' => Date::now(),
            'status' => SupervisedJobStatus::Failed,
        ]);

        $deadLetterJob = DeadLetterJob::query()->create([
            'supervised_job_id' => $supervisedJob->id,
            'job_class' => 'App\\Jobs\\NonExistentJob',
            'exception' => Exception::class,
            'message' => 'Failed job',
            'trace' => 'trace',
            'failed_at' => Date::now(),
        ]);

        // Act
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Job class App\\Jobs\\NonExistentJob does not exist');

        // Assert
        $this->manager->retry((string) $deadLetterJob->id);
    }

    #[Test()]
    #[TestDox('Prunes entries older than specified retention period')]
    #[Group('happy-path')]
    public function prunes_entries_older_than_retention_period(): void
    {
        // Arrange
        Date::setTestNow(Date::parse('2024-02-15 12:00:00'));

        $supervisedJob1 = SupervisedJob::query()->create([
            'job_id' => 'old-job',
            'job_class' => TestJob::class,
            'started_at' => Date::now(),
            'status' => SupervisedJobStatus::Failed,
        ]);

        $supervisedJob2 = SupervisedJob::query()->create([
            'job_id' => 'recent-job',
            'job_class' => TestJob::class,
            'started_at' => Date::now(),
            'status' => SupervisedJobStatus::Failed,
        ]);

        // Old entry (40 days ago)
        DeadLetterJob::query()->create([
            'supervised_job_id' => $supervisedJob1->id,
            'job_class' => TestJob::class,
            'exception' => Exception::class,
            'message' => 'Old failure',
            'trace' => 'trace',
            'failed_at' => Date::now()->subDays(40),
        ]);

        // Recent entry (10 days ago)
        DeadLetterJob::query()->create([
            'supervised_job_id' => $supervisedJob2->id,
            'job_class' => TestJob::class,
            'exception' => Exception::class,
            'message' => 'Recent failure',
            'trace' => 'trace',
            'failed_at' => Date::now()->subDays(10),
        ]);

        // Act
        $deleted = $this->manager->prune(30);

        // Assert
        $this->assertSame(1, $deleted);
        $this->assertDatabaseCount('dead_letter_queue', 1);
        $this->assertDatabaseHas('dead_letter_queue', [
            'message' => 'Recent failure',
        ]);
        $this->assertDatabaseMissing('dead_letter_queue', [
            'message' => 'Old failure',
        ]);

        Date::setTestNow();
    }

    #[Test()]
    #[TestDox('Uses configured retention period when no days parameter provided')]
    #[Group('happy-path')]
    public function uses_configured_retention_period_when_no_days_provided(): void
    {
        // Arrange
        Config::set('chaperone.dead_letter_queue.retention_period', 15);
        Date::setTestNow(Date::parse('2024-02-15 12:00:00'));

        $supervisedJob1 = SupervisedJob::query()->create([
            'job_id' => 'old-job',
            'job_class' => TestJob::class,
            'started_at' => Date::now(),
            'status' => SupervisedJobStatus::Failed,
        ]);

        $supervisedJob2 = SupervisedJob::query()->create([
            'job_id' => 'recent-job',
            'job_class' => TestJob::class,
            'started_at' => Date::now(),
            'status' => SupervisedJobStatus::Failed,
        ]);

        DeadLetterJob::query()->create([
            'supervised_job_id' => $supervisedJob1->id,
            'job_class' => TestJob::class,
            'exception' => Exception::class,
            'message' => 'Old failure',
            'trace' => 'trace',
            'failed_at' => Date::now()->subDays(20),
        ]);

        DeadLetterJob::query()->create([
            'supervised_job_id' => $supervisedJob2->id,
            'job_class' => TestJob::class,
            'exception' => Exception::class,
            'message' => 'Recent failure',
            'trace' => 'trace',
            'failed_at' => Date::now()->subDays(10),
        ]);

        // Act
        $deleted = $this->manager->prune();

        // Assert
        $this->assertSame(1, $deleted);
        $this->assertDatabaseCount('dead_letter_queue', 1);

        Date::setTestNow();
    }

    #[Test()]
    #[TestDox('Does not prune any entries when retention period is zero')]
    #[Group('edge-case')]
    public function does_not_prune_when_retention_period_is_zero(): void
    {
        // Arrange
        $supervisedJob = SupervisedJob::query()->create([
            'job_id' => 'old-job',
            'job_class' => TestJob::class,
            'started_at' => Date::now(),
            'status' => SupervisedJobStatus::Failed,
        ]);

        DeadLetterJob::query()->create([
            'supervised_job_id' => $supervisedJob->id,
            'job_class' => TestJob::class,
            'exception' => Exception::class,
            'message' => 'Very old failure',
            'trace' => 'trace',
            'failed_at' => Date::now()->subYears(5),
        ]);

        // Act
        $deleted = $this->manager->prune(0);

        // Assert
        $this->assertSame(0, $deleted);
        $this->assertDatabaseCount('dead_letter_queue', 1);
    }

    #[Test()]
    #[TestDox('Returns zero count when no entries exist')]
    #[Group('edge-case')]
    public function returns_zero_count_when_no_entries_exist(): void
    {
        // Arrange
        // No entries created

        // Act
        $count = $this->manager->count();

        // Assert
        $this->assertSame(0, $count);
    }

    #[Test()]
    #[TestDox('Returns empty collection when no entries exist')]
    #[Group('edge-case')]
    public function returns_empty_collection_when_no_entries_exist(): void
    {
        // Arrange
        // No entries created

        // Act
        $results = $this->manager->all();

        // Assert
        $this->assertCount(0, $results);
        $this->assertTrue($results->isEmpty());
    }

    #[Test()]
    #[TestDox('Handles null payload gracefully')]
    #[Group('edge-case')]
    public function handles_null_payload_gracefully(): void
    {
        // Arrange
        $supervisedJob = SupervisedJob::query()->create([
            'job_id' => 'null-payload-job',
            'job_class' => TestJob::class,
            'started_at' => Date::now(),
            'status' => SupervisedJobStatus::Failed,
            'metadata' => null,
        ]);

        $exception = new Exception('Job failed');

        // Act
        $this->manager->moveToDeadLetterQueue($supervisedJob, $exception);

        // Assert
        $deadLetterJob = DeadLetterJob::query()->first();
        $this->assertNull($deadLetterJob->payload);
    }

    #[Test()]
    #[TestDox('Loads supervised job relationship in all() method')]
    #[Group('happy-path')]
    public function loads_supervised_job_relationship_in_all_method(): void
    {
        // Arrange
        $supervisedJob = SupervisedJob::query()->create([
            'job_id' => 'relationship-job',
            'job_class' => TestJob::class,
            'started_at' => Date::now(),
            'status' => SupervisedJobStatus::Failed,
        ]);

        DeadLetterJob::query()->create([
            'supervised_job_id' => $supervisedJob->id,
            'job_class' => TestJob::class,
            'exception' => Exception::class,
            'message' => 'Test',
            'trace' => 'trace',
            'failed_at' => Date::now(),
        ]);

        // Act
        $results = $this->manager->all();

        // Assert
        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->relationLoaded('supervisedJob'));
        $this->assertEquals($supervisedJob->id, $results->first()->supervisedJob->id);
    }
}
