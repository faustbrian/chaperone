<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Chaperone\CircuitBreakers\CircuitBreakerManager;
use Cline\Chaperone\Database\Models\CircuitBreaker;
use Cline\Chaperone\Enums\CircuitBreakerState;
use Cline\Chaperone\Events\CircuitBreakerClosed;
use Cline\Chaperone\Events\CircuitBreakerHalfOpened;
use Cline\Chaperone\Events\CircuitBreakerOpened;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use RuntimeException;

beforeEach(function (): void {
    // Arrange: Clear any existing circuit breaker records
    CircuitBreaker::query()->delete();

    // Arrange: Clear cache locks from previous tests
    Cache::flush();

    // Arrange: Fake events for testing
    Event::fake([
        CircuitBreakerOpened::class,
        CircuitBreakerClosed::class,
        CircuitBreakerHalfOpened::class,
    ]);
});

describe('Happy Path - Normal Operation', function (): void {
    test('closed state allows execution', function (): void {
        // Arrange
        $manager = new CircuitBreakerManager('test-service');
        $executed = false;

        // Act
        $result = $manager->call(function () use (&$executed): string {
            $executed = true;

            return 'success';
        });

        // Assert
        expect($executed)->toBeTrue();
        expect($result)->toBe('success');
        expect($manager->isClosed())->toBeTrue();
    });

    test('halfopen state allows execution', function (): void {
        // Arrange
        $manager = new CircuitBreakerManager('test-service');
        $model = CircuitBreaker::query()->where('service_name', 'test-service')->first();
        $model->update(['state' => CircuitBreakerState::HalfOpen]);

        $executed = false;

        // Act
        $result = $manager->call(function () use (&$executed): string {
            $executed = true;

            return 'success';
        });

        // Assert
        expect($executed)->toBeTrue();
        expect($result)->toBe('success');
    });

    test('halfopen to closed transition after sufficient successes', function (): void {
        // Arrange
        $manager = new CircuitBreakerManager('test-service', halfOpenAttempts: 3);
        $model = CircuitBreaker::query()->where('service_name', 'test-service')->first();
        $model->update(['state' => CircuitBreakerState::HalfOpen]);

        // Act
        $manager->call(fn (): string => 'success');
        $manager->call(fn (): string => 'success');
        $manager->call(fn (): string => 'success');

        // Assert
        expect($manager->isClosed())->toBeTrue();
        Event::assertDispatched(CircuitBreakerClosed::class, fn ($event): bool => $event->service === 'test-service');
    });

    test('recordSuccess resets failure count in closed state', function (): void {
        // Arrange
        $manager = new CircuitBreakerManager('test-service');
        $model = CircuitBreaker::query()->where('service_name', 'test-service')->first();
        $model->update(['failure_count' => 2]);

        // Act
        $manager->recordSuccess();

        // Assert
        $model->refresh();
        expect($model->failure_count)->toBe(0);
    });

    test('manual close resets counters', function (): void {
        // Arrange
        $manager = new CircuitBreakerManager('test-service', failureThreshold: 3);
        $exception = new RuntimeException('Service failed');

        // Open circuit by reaching failure threshold
        $manager->recordFailure($exception);
        $manager->recordFailure($exception);
        $manager->recordFailure($exception);

        $model = CircuitBreaker::query()->where('service_name', 'test-service')->first();
        expect($model->state)->toBe(CircuitBreakerState::Open);

        // Act
        $manager->close();

        // Assert
        $model->refresh();
        expect($model->state)->toBe(CircuitBreakerState::Closed);
        expect($model->failure_count)->toBe(0);
        expect($model->opened_at)->toBeNull();
        Event::assertDispatched(CircuitBreakerClosed::class);
    });
});

describe('Sad Path - Error Handling', function (): void {
    test('open state blocks execution', function (): void {
        // Arrange
        $manager = new CircuitBreakerManager('test-service');
        $manager->open();

        $executed = false;

        // Act & Assert
        expect(fn (): mixed => $manager->call(function () use (&$executed): string {
            $executed = true;

            return 'should not run';
        }))->toThrow(RuntimeException::class, 'Circuit breaker for service [test-service] is open');

        expect($executed)->toBeFalse();
    });

    test('threshold reached opens circuit', function (): void {
        // Arrange
        $manager = new CircuitBreakerManager('test-service', failureThreshold: 3);
        $exception = new RuntimeException('Service failed');

        // Act
        $manager->recordFailure($exception);
        $manager->recordFailure($exception);
        $manager->recordFailure($exception);

        // Assert
        expect($manager->isOpen())->toBeTrue();
        Event::assertDispatched(CircuitBreakerOpened::class, fn ($event): bool => $event->service === 'test-service' && $event->failureCount === 3);
    });

    test('halfopen failure reopens circuit', function (): void {
        // Arrange
        $manager = new CircuitBreakerManager('test-service');
        $model = CircuitBreaker::query()->where('service_name', 'test-service')->first();
        $model->update(['state' => CircuitBreakerState::HalfOpen]);

        // Act
        try {
            $manager->call(function (): void {
                throw new RuntimeException('Still failing');
            });
        } catch (RuntimeException) {
            // Expected exception
        }

        // Assert
        expect($manager->isOpen())->toBeTrue();
        Event::assertDispatched(CircuitBreakerOpened::class);
    });

    test('failed call records failure and rethrows', function (): void {
        // Arrange
        $manager = new CircuitBreakerManager('test-service');
        $model = CircuitBreaker::query()->where('service_name', 'test-service')->first();
        $exception = new RuntimeException('Operation failed');

        // Act & Assert
        expect(fn (): mixed => $manager->call(function () use ($exception): void {
            throw $exception;
        }))->toThrow(RuntimeException::class, 'Operation failed');

        $model->refresh();
        expect($model->failure_count)->toBe(1);
        expect($model->last_failure_at)->not->toBeNull();
    });
});

describe('Edge Cases - Boundaries & Transitions', function (): void {
    test('threshold boundary n minus 1 failures', function (): void {
        // Arrange
        $manager = new CircuitBreakerManager('test-service', failureThreshold: 5);
        $exception = new RuntimeException('Service failed');

        // Act
        $manager->recordFailure($exception);
        $manager->recordFailure($exception);
        $manager->recordFailure($exception);
        $manager->recordFailure($exception);

        // Assert
        expect($manager->isClosed())->toBeTrue();
        $model = CircuitBreaker::query()->where('service_name', 'test-service')->first();
        expect($model->failure_count)->toBe(4);
    });

    test('open to halfopen transition after timeout', function (): void {
        // Arrange
        $now = Date::now();
        Date::setTestNow($now);

        $manager = new CircuitBreakerManager('test-service', timeout: 60);
        $manager->open();

        // Move time forward past timeout
        Date::setTestNow($now->addSeconds(61));

        // Act
        $result = $manager->call(fn (): string => 'recovered');

        // Assert
        expect($result)->toBe('recovered');
        Event::assertDispatched(CircuitBreakerHalfOpened::class);

        Date::setTestNow();
    });

    test('halfopen success count boundary', function (): void {
        // Arrange
        $manager = new CircuitBreakerManager('test-service', halfOpenAttempts: 3);
        $model = CircuitBreaker::query()->where('service_name', 'test-service')->first();
        $model->update([
            'state' => CircuitBreakerState::HalfOpen,
        ]);

        // Act - Record 2 successes (not enough to close), then 1 more
        $manager->call(fn (): string => 'success');
        $manager->call(fn (): string => 'success');
        $manager->call(fn (): string => 'success'); // This 3rd success should close the circuit

        // Assert
        expect($manager->isClosed())->toBeTrue();
    });

    test('state check methods accuracy', function (): void {
        // Arrange
        $manager = new CircuitBreakerManager('test-service');
        $model = CircuitBreaker::query()->where('service_name', 'test-service')->first();

        // Assert - Closed state
        expect($manager->isClosed())->toBeTrue();
        expect($manager->isOpen())->toBeFalse();
        expect($manager->isHalfOpen())->toBeFalse();

        // Act - Transition to Open
        $model->update(['state' => CircuitBreakerState::Open]);

        // Assert - Open state
        expect($manager->isClosed())->toBeFalse();
        expect($manager->isOpen())->toBeTrue();
        expect($manager->isHalfOpen())->toBeFalse();

        // Act - Transition to HalfOpen
        $model->update(['state' => CircuitBreakerState::HalfOpen]);

        // Assert - HalfOpen state
        expect($manager->isClosed())->toBeFalse();
        expect($manager->isOpen())->toBeFalse();
        expect($manager->isHalfOpen())->toBeTrue();
    });

    test('event dispatching with correct data', function (): void {
        // Arrange
        $manager = new CircuitBreakerManager('payment-service', failureThreshold: 5);
        $exception = new RuntimeException('Service failed');

        // Act
        $manager->recordFailure($exception);
        $manager->recordFailure($exception);
        $manager->recordFailure($exception);
        $manager->recordFailure($exception);
        $manager->recordFailure($exception);

        // Assert
        Event::assertDispatched(CircuitBreakerOpened::class, fn ($event): bool => $event->service === 'payment-service'
            && $event->failureCount === 5
            && $event->openedAt instanceof DateTimeImmutable);
    });

    test('configuration values used correctly', function (): void {
        // Arrange
        $manager = new CircuitBreakerManager('test-service', failureThreshold: 10, timeout: 120);
        $exception = new RuntimeException('Service failed');

        // Act
        for ($i = 0; $i < 9; ++$i) {
            $manager->recordFailure($exception);
        }

        // Assert
        expect($manager->isClosed())->toBeTrue();

        // Act - One more to reach threshold
        $manager->recordFailure($exception);

        // Assert
        expect($manager->isOpen())->toBeTrue();
    });
});
