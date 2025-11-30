<?php declare(strict_types=1);

namespace Cline\Chaperone\Tests\Unit\Deployment;

use Cline\Chaperone\Deployment\DeploymentCoordinator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeploymentCoordinator::class)]
#[Small]
final class DeploymentCoordinatorTest extends TestCase
{
    private DeploymentCoordinator $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        // Arrange
        $this->coordinator = new DeploymentCoordinator();
    }

    #[Test]
    #[TestDox('drainQueues returns self for method chaining')]
    #[Group('happy-path')]
    public function drainQueuesReturnsSelfForMethodChaining(): void
    {
        // Arrange
        $queues = ['default', 'emails'];

        // Act
        $result = $this->coordinator->drainQueues($queues);

        // Assert
        $this->assertSame($this->coordinator, $result);
    }

    #[Test]
    #[TestDox('waitForCompletion returns self for method chaining')]
    #[Group('happy-path')]
    public function waitForCompletionReturnsSelfForMethodChaining(): void
    {
        // Arrange
        $timeout = 600;

        // Act
        $result = $this->coordinator->waitForCompletion($timeout);

        // Assert
        $this->assertSame($this->coordinator, $result);
    }

    #[Test]
    #[TestDox('cancelLongRunning returns self for method chaining')]
    #[Group('happy-path')]
    public function cancelLongRunningReturnsSelfForMethodChaining(): void
    {
        // Arrange
        // (coordinator already set up in setUp)

        // Act
        $result = $this->coordinator->cancelLongRunning();

        // Assert
        $this->assertSame($this->coordinator, $result);
    }

    #[Test]
    #[TestDox('onTimeout returns self for method chaining')]
    #[Group('happy-path')]
    public function onTimeoutReturnsSelfForMethodChaining(): void
    {
        // Arrange
        $callback = function (): void {};

        // Act
        $result = $this->coordinator->onTimeout($callback);

        // Assert
        $this->assertSame($this->coordinator, $result);
    }

    #[Test]
    #[TestDox('All fluent methods can be chained together')]
    #[Group('edge-case')]
    public function allFluentMethodsCanBeChainedTogether(): void
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

    #[Test]
    #[TestDox('drainQueues accepts empty array')]
    #[Group('edge-case')]
    public function drainQueuesAcceptsEmptyArray(): void
    {
        // Arrange
        $queues = [];

        // Act
        $result = $this->coordinator->drainQueues($queues);

        // Assert
        $this->assertSame($this->coordinator, $result);
    }

    #[Test]
    #[TestDox('drainQueues accepts multiple queue names')]
    #[Group('happy-path')]
    public function drainQueuesAcceptsMultipleQueueNames(): void
    {
        // Arrange
        $queues = ['default', 'emails', 'notifications', 'reports'];

        // Act
        $result = $this->coordinator->drainQueues($queues);

        // Assert
        $this->assertSame($this->coordinator, $result);
    }

    #[Test]
    #[TestDox('waitForCompletion accepts positive timeout value')]
    #[Group('happy-path')]
    public function waitForCompletionAcceptsPositiveTimeoutValue(): void
    {
        // Arrange
        $timeout = 1800;

        // Act
        $result = $this->coordinator->waitForCompletion($timeout);

        // Assert
        $this->assertSame($this->coordinator, $result);
    }

    #[Test]
    #[TestDox('waitForCompletion accepts zero timeout value')]
    #[Group('edge-case')]
    public function waitForCompletionAcceptsZeroTimeoutValue(): void
    {
        // Arrange
        $timeout = 0;

        // Act
        $result = $this->coordinator->waitForCompletion($timeout);

        // Assert
        $this->assertSame($this->coordinator, $result);
    }

    #[Test]
    #[TestDox('onTimeout accepts callable')]
    #[Group('happy-path')]
    public function onTimeoutAcceptsCallable(): void
    {
        // Arrange
        $callback = fn($jobs): null => null;

        // Act
        $result = $this->coordinator->onTimeout($callback);

        // Assert
        $this->assertSame($this->coordinator, $result);
    }

    #[Test]
    #[TestDox('onTimeout can be called multiple times')]
    #[Group('edge-case')]
    public function onTimeoutCanBeCalledMultipleTimes(): void
    {
        // Arrange
        $firstCallback = fn(): string => 'first';
        $secondCallback = fn(): string => 'second';

        // Act
        $result = $this->coordinator
            ->onTimeout($firstCallback)
            ->onTimeout($secondCallback);

        // Assert
        $this->assertSame($this->coordinator, $result);
    }

    #[Test]
    #[TestDox('cancelLongRunning can be called multiple times')]
    #[Group('edge-case')]
    public function cancelLongRunningCanBeCalledMultipleTimes(): void
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

    #[Test]
    #[TestDox('Methods can be called in any order')]
    #[Group('edge-case')]
    public function methodsCanBeCalledInAnyOrder(): void
    {
        // Arrange
        $queues = ['default'];
        $timeout = 300;
        $callback = fn(): null => null;

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
