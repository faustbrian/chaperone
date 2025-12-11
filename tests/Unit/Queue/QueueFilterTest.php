<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Chaperone\Queue\QueueFilter;

describe('QueueFilter', function (): void {
    describe('Happy Paths', function (): void {
        test('shouldSupervise returns true when supervised_queues is empty (supervise all)', function (): void {
            // Arrange
            config(['chaperone.queue.supervised_queues' => []]);
            config(['chaperone.queue.excluded_queues' => []]);
            $filter = new QueueFilter();

            // Act
            $result = $filter->shouldSupervise('default');

            // Assert
            expect($result)->toBeTrue();
        });

        test('shouldSupervise returns true when queue is in supervised_queues allowlist', function (): void {
            // Arrange
            config(['chaperone.queue.supervised_queues' => ['high-priority', 'default']]);
            config(['chaperone.queue.excluded_queues' => []]);
            $filter = new QueueFilter();

            // Act
            $result = $filter->shouldSupervise('high-priority');

            // Assert
            expect($result)->toBeTrue();
        });

        test('getSupervisedQueues returns filtered array without empty strings', function (): void {
            // Arrange
            config(['chaperone.queue.supervised_queues' => ['default', '', 'high-priority', '']]);
            $filter = new QueueFilter();

            // Act
            $result = $filter->getSupervisedQueues();

            // Assert
            expect($result)->toBe([0 => 'default', 2 => 'high-priority'])
                ->and($result)->not->toContain('')
                ->and(array_values($result))->toBe(['default', 'high-priority']);
        });

        test('getExcludedQueues returns filtered array without empty strings', function (): void {
            // Arrange
            config(['chaperone.queue.excluded_queues' => ['test-queue', '', 'debug-queue', '']]);
            $filter = new QueueFilter();

            // Act
            $result = $filter->getExcludedQueues();

            // Assert
            expect($result)->toBe([0 => 'test-queue', 2 => 'debug-queue'])
                ->and($result)->not->toContain('')
                ->and(array_values($result))->toBe(['test-queue', 'debug-queue']);
        });

        test('shouldSupervise works with multiple queues in allowlist', function (): void {
            // Arrange
            config(['chaperone.queue.supervised_queues' => ['queue1', 'queue2', 'queue3']]);
            config(['chaperone.queue.excluded_queues' => []]);
            $filter = new QueueFilter();

            // Act
            $result1 = $filter->shouldSupervise('queue1');
            $result2 = $filter->shouldSupervise('queue2');
            $result3 = $filter->shouldSupervise('queue3');

            // Assert
            expect($result1)->toBeTrue()
                ->and($result2)->toBeTrue()
                ->and($result3)->toBeTrue();
        });
    });

    describe('Sad Paths', function (): void {
        test('shouldSupervise returns false when queue is in excluded_queues (denylist)', function (): void {
            // Arrange
            config(['chaperone.queue.supervised_queues' => []]);
            config(['chaperone.queue.excluded_queues' => ['debug', 'test']]);
            $filter = new QueueFilter();

            // Act
            $result = $filter->shouldSupervise('debug');

            // Assert
            expect($result)->toBeFalse();
        });

        test('shouldSupervise returns false when queue not in supervised_queues allowlist', function (): void {
            // Arrange
            config(['chaperone.queue.supervised_queues' => ['high-priority', 'default']]);
            config(['chaperone.queue.excluded_queues' => []]);
            $filter = new QueueFilter();

            // Act
            $result = $filter->shouldSupervise('low-priority');

            // Assert
            expect($result)->toBeFalse();
        });

        test('excluded queues take precedence over supervised queues', function (): void {
            // Arrange
            config(['chaperone.queue.supervised_queues' => ['default', 'test', 'high-priority']]);
            config(['chaperone.queue.excluded_queues' => ['test']]);
            $filter = new QueueFilter();

            // Act
            $result = $filter->shouldSupervise('test');

            // Assert
            expect($result)->toBeFalse();
        });
    });

    describe('Edge Cases', function (): void {
        test('empty configuration arrays return defaults', function (): void {
            // Arrange
            config(['chaperone.queue.supervised_queues' => []]);
            config(['chaperone.queue.excluded_queues' => []]);
            $filter = new QueueFilter();

            // Act
            $supervisedQueues = $filter->getSupervisedQueues();
            $excludedQueues = $filter->getExcludedQueues();

            // Assert
            expect($supervisedQueues)->toBeArray()->toBeEmpty()
                ->and($excludedQueues)->toBeArray()->toBeEmpty();
        });

        test('configuration with only empty strings filters to empty array', function (): void {
            // Arrange
            config(['chaperone.queue.supervised_queues' => ['', '', '']]);
            config(['chaperone.queue.excluded_queues' => ['', '']]);
            $filter = new QueueFilter();

            // Act
            $supervisedQueues = $filter->getSupervisedQueues();
            $excludedQueues = $filter->getExcludedQueues();

            // Assert
            expect($supervisedQueues)->toBeEmpty()
                ->and($excludedQueues)->toBeEmpty();
        });

        test('queue name matching is case-sensitive', function (): void {
            // Arrange
            config(['chaperone.queue.supervised_queues' => ['Default', 'HIGH-PRIORITY']]);
            config(['chaperone.queue.excluded_queues' => []]);
            $filter = new QueueFilter();

            // Act
            $resultLowercase = $filter->shouldSupervise('default');
            $resultUppercase = $filter->shouldSupervise('Default');

            // Assert
            expect($resultLowercase)->toBeFalse()
                ->and($resultUppercase)->toBeTrue();
        });

        test('mixed empty and non-empty strings in configuration', function (): void {
            // Arrange
            config(['chaperone.queue.supervised_queues' => ['', 'queue1', '', 'queue2', '']]);
            config(['chaperone.queue.excluded_queues' => ['', 'excluded1', '']]);
            $filter = new QueueFilter();

            // Act
            $supervisedQueues = $filter->getSupervisedQueues();
            $excludedQueues = $filter->getExcludedQueues();

            // Assert
            expect($supervisedQueues)->toBe([1 => 'queue1', 3 => 'queue2'])
                ->and(array_values($supervisedQueues))->toBe(['queue1', 'queue2'])
                ->and($excludedQueues)->toBe([1 => 'excluded1'])
                ->and(array_values($excludedQueues))->toBe(['excluded1']);
        });
    });
});
