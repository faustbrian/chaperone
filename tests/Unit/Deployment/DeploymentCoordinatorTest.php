<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Tests\Unit\Deployment;

use Cline\Chaperone\Deployment\DeploymentCoordinator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * @author Brian Faust <brian@cline.sh>
 * @internal
 */
#[CoversClass(DeploymentCoordinator::class)]
#[Small()]
final class DeploymentCoordinatorTest extends TestCase
{
    private DeploymentCoordinator $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        // Arrange
        $this->coordinator = new DeploymentCoordinator();
    }

    #[Test()]
    #[TestDox('drainQueues returns self for method chaining')]
    #[Group('happy-path')]
    public function drain_queues_returns_self_for_method_chaining(): void
    {
        // Arrange
        $queues = ['default', 'emails'];

        // Act
        $result = $this->coordinator->drainQueues($queues);

        // Assert
        $this->assertSame($this->coordinator, $result);
    }

    #[Test()]
    #[TestDox('waitForCompletion returns self for method chaining')]
    #[Group('happy-path')]
    public function wait_for_completion_returns_self_for_method_chaining(): void
    {
        // Arrange
        $timeout = 600;

        // Act
        $result = $this->coordinator->waitForCompletion($timeout);

        // Assert
        $this->assertSame($this->coordinator, $result);
    }

    #[Test()]
    #[TestDox('cancelLongRunning returns self for method chaining')]
    #[Group('happy-path')]
    public function cancel_long_running_returns_self_for_method_chaining(): void
    {
        // Arrange
        // (coordinator already set up in setUp)

        // Act
        $result = $this->coordinator->cancelLongRunning();

        // Assert
        $this->assertSame($this->coordinator, $result);
    }

    #[Test()]
    #[TestDox('onTimeout returns self for method chaining')]
    #[Group('happy-path')]
    public function on_timeout_returns_self_for_method_chaining(): void
    {
        // Arrange
        $callback = function (): void {};

        // Act
        $result = $this->coordinator->onTimeout($callback);

        // Assert
        $this->assertSame($this->coordinator, $result);
    }

    #[Test()]
    #[TestDox('All fluent methods can be chained together')]
    #[Group('edge-case')]
    public function all_fluent_methods_can_be_chained_together(): void
    {
        // Arrange
        $queues = ['default', 'emails'];
        $timeout = 600;
        $callback = function (): void {};

        // Act
        $result = $this->coordinator
            ->drainQueues($queues)
            ->waitForCompletion($timeout)
            ->cancelLongRunning()
            ->onTimeout($callback);

        // Assert
        $this->assertSame($this->coordinator, $result);
    }

    #[Test()]
    #[TestDox('drainQueues accepts empty array')]
    #[Group('edge-case')]
    public function drain_queues_accepts_empty_array(): void
    {
        // Arrange
        $queues = [];

        // Act
        $result = $this->coordinator->drainQueues($queues);

        // Assert
        $this->assertSame($this->coordinator, $result);
    }

    #[Test()]
    #[TestDox('drainQueues accepts multiple queue names')]
    #[Group('happy-path')]
    public function drain_queues_accepts_multiple_queue_names(): void
    {
        // Arrange
        $queues = ['default', 'emails', 'notifications', 'reports'];

        // Act
        $result = $this->coordinator->drainQueues($queues);

        // Assert
        $this->assertSame($this->coordinator, $result);
    }

    #[Test()]
    #[TestDox('waitForCompletion accepts positive timeout value')]
    #[Group('happy-path')]
    public function wait_for_completion_accepts_positive_timeout_value(): void
    {
        // Arrange
        $timeout = 1_800;

        // Act
        $result = $this->coordinator->waitForCompletion($timeout);

        // Assert
        $this->assertSame($this->coordinator, $result);
    }

    #[Test()]
    #[TestDox('waitForCompletion accepts zero timeout value')]
    #[Group('edge-case')]
    public function wait_for_completion_accepts_zero_timeout_value(): void
    {
        // Arrange
        $timeout = 0;

        // Act
        $result = $this->coordinator->waitForCompletion($timeout);

        // Assert
        $this->assertSame($this->coordinator, $result);
    }

    #[Test()]
    #[TestDox('onTimeout accepts callable')]
    #[Group('happy-path')]
    public function on_timeout_accepts_callable(): void
    {
        // Arrange
        $callback = fn ($jobs): null => null;

        // Act
        $result = $this->coordinator->onTimeout($callback);

        // Assert
        $this->assertSame($this->coordinator, $result);
    }

    #[Test()]
    #[TestDox('onTimeout can be called multiple times')]
    #[Group('edge-case')]
    public function on_timeout_can_be_called_multiple_times(): void
    {
        // Arrange
        $firstCallback = fn (): string => 'first';
        $secondCallback = fn (): string => 'second';

        // Act
        $result = $this->coordinator
            ->onTimeout($firstCallback)
            ->onTimeout($secondCallback);

        // Assert
        $this->assertSame($this->coordinator, $result);
    }

    #[Test()]
    #[TestDox('cancelLongRunning can be called multiple times')]
    #[Group('edge-case')]
    public function cancel_long_running_can_be_called_multiple_times(): void
    {
        // Arrange
        // (coordinator already set up in setUp)

        // Act
        $result = $this->coordinator
            ->cancelLongRunning()
            ->cancelLongRunning();

        // Assert
        $this->assertSame($this->coordinator, $result);
    }

    #[Test()]
    #[TestDox('Methods can be called in any order')]
    #[Group('edge-case')]
    public function methods_can_be_called_in_any_order(): void
    {
        // Arrange
        $queues = ['default'];
        $timeout = 300;
        $callback = fn (): null => null;

        // Act
        $result = $this->coordinator
            ->onTimeout($callback)
            ->cancelLongRunning()
            ->waitForCompletion($timeout)
            ->drainQueues($queues);

        // Assert
        $this->assertSame($this->coordinator, $result);
    }
}
