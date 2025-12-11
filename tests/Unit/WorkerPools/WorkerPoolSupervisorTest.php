<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Chaperone\WorkerPools\WorkerPoolSupervisor;

describe('WorkerPoolSupervisor', function (): void {
    describe('Happy Paths', function (): void {
        test('constructor creates supervisor with name', function (): void {
            // Arrange
            $name = 'test-pool';

            // Act
            $supervisor = new WorkerPoolSupervisor($name);

            // Assert
            expect($supervisor->getName())->toBe($name);
        });

        test('workers() sets worker count', function (): void {
            // Arrange
            $supervisor = new WorkerPoolSupervisor('test-pool');

            // Act
            $result = $supervisor->workers(5);

            // Assert
            expect($result)->toBe($supervisor)
                ->and($supervisor->getStatus()['worker_count'])->toBe(5);
        });

        test('queue() sets queue name', function (): void {
            // Arrange
            $supervisor = new WorkerPoolSupervisor('test-pool');

            // Act
            $result = $supervisor->queue('high-priority');

            // Assert
            expect($result)->toBe($supervisor);
        });

        test('withHealthCheck() registers callback', function (): void {
            // Arrange
            $supervisor = new WorkerPoolSupervisor('test-pool');
            $callback = fn ($worker): true => true;

            // Act
            $result = $supervisor->withHealthCheck($callback);

            // Assert
            expect($result)->toBe($supervisor);
        });

        test('onUnhealthy() registers callback', function (): void {
            // Arrange
            $supervisor = new WorkerPoolSupervisor('test-pool');
            $callback = fn ($worker): null => null;

            // Act
            $result = $supervisor->onUnhealthy($callback);

            // Assert
            expect($result)->toBe($supervisor);
        });

        test('onCrash() registers callback', function (): void {
            // Arrange
            $supervisor = new WorkerPoolSupervisor('test-pool');
            $callback = fn ($worker): null => null;

            // Act
            $result = $supervisor->onCrash($callback);

            // Assert
            expect($result)->toBe($supervisor);
        });

        test('getStatus() returns pool information', function (): void {
            // Arrange
            $supervisor = new WorkerPoolSupervisor('test-pool');
            $supervisor->workers(3);

            // Act
            $status = $supervisor->getStatus();

            // Assert
            expect($status)->toBeArray()
                ->toHaveKeys(['name', 'worker_count', 'workers'])
                ->and($status['name'])->toBe('test-pool')
                ->and($status['worker_count'])->toBe(3)
                ->and($status['workers'])->toBeArray();
        });

        test('fluent chaining works', function (): void {
            // Arrange
            $supervisor = new WorkerPoolSupervisor('test-pool');
            $healthCheck = fn ($worker): true => true;

            // Act
            $result = $supervisor
                ->workers(5)
                ->queue('high-priority')
                ->withHealthCheck($healthCheck);

            // Assert
            expect($result)->toBe($supervisor)
                ->and($supervisor->getStatus()['worker_count'])->toBe(5);
        });
    });

    describe('Sad Paths', function (): void {
        test('workers() rejects count less than 1', function (): void {
            // Arrange
            $supervisor = new WorkerPoolSupervisor('test-pool');

            // Act & Assert
            expect(fn (): WorkerPoolSupervisor => $supervisor->workers(0))
                ->toThrow(InvalidArgumentException::class, 'Worker count must be at least 1');
        });

        test('workers() rejects negative count', function (): void {
            // Arrange
            $supervisor = new WorkerPoolSupervisor('test-pool');

            // Act & Assert
            expect(fn (): WorkerPoolSupervisor => $supervisor->workers(-5))
                ->toThrow(InvalidArgumentException::class, 'Worker count must be at least 1');
        });

        test('supervise() throws if already supervising', function (): void {
            // Arrange
            $supervisor = new WorkerPoolSupervisor('test-pool');

            // We can't actually test supervise() as it blocks in an infinite loop
            // This test documents the expected behavior but cannot be executed
            // without refactoring the class to be more testable
            expect(true)->toBeTrue();
        })->skip('Supervise method blocks indefinitely, requires refactoring for testability');
    });

    describe('Edge Cases', function (): void {
        test('stop() with no workers', function (): void {
            // Arrange
            $supervisor = new WorkerPoolSupervisor('test-pool');

            // Act
            $supervisor->stop();

            // Assert
            expect($supervisor->getStatus()['workers'])->toBeEmpty();
        });

        test('stop() clears workers collection', function (): void {
            // Arrange
            $supervisor = new WorkerPoolSupervisor('test-pool');

            // Act
            $supervisor->stop();

            // Assert
            expect($supervisor->getStatus()['workers'])->toBeEmpty();
        });

        test('getStatus() with default worker count', function (): void {
            // Arrange
            $supervisor = new WorkerPoolSupervisor('test-pool');

            // Act
            $status = $supervisor->getStatus();

            // Assert
            expect($status['worker_count'])->toBe(1)
                ->and($status['workers'])->toBeEmpty();
        });

        test('workers(1) sets minimum valid count', function (): void {
            // Arrange
            $supervisor = new WorkerPoolSupervisor('test-pool');

            // Act
            $result = $supervisor->workers(1);

            // Assert
            expect($result)->toBe($supervisor)
                ->and($supervisor->getStatus()['worker_count'])->toBe(1);
        });

        test('queue() with empty string', function (): void {
            // Arrange
            $supervisor = new WorkerPoolSupervisor('test-pool');

            // Act
            $result = $supervisor->queue('');

            // Assert
            expect($result)->toBe($supervisor);
        });

        test('multiple callback registrations', function (): void {
            // Arrange
            $supervisor = new WorkerPoolSupervisor('test-pool');
            $firstCallback = fn ($worker): true => true;
            $secondCallback = fn ($worker): false => false;

            // Act
            $supervisor->withHealthCheck($firstCallback);
            $result = $supervisor->withHealthCheck($secondCallback);

            // Assert
            expect($result)->toBe($supervisor);
        });
    });
});
