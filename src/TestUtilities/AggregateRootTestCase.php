<?php

declare(strict_types=1);

namespace EventSauce\EventSourcing\TestUtilities;

use DateTimeImmutable;
use EventSauce\Clock\TestClock;
use EventSauce\EventSourcing\AggregateRoot;
use EventSauce\EventSourcing\AggregateRootId;
use EventSauce\EventSourcing\AggregateRootRepository;
use EventSauce\EventSourcing\DefaultHeadersDecorator;
use EventSauce\EventSourcing\EventSourcedAggregateRootRepository;
use EventSauce\EventSourcing\InMemoryMessageRepository;
use EventSauce\EventSourcing\MessageConsumer;
use EventSauce\EventSourcing\MessageDecorator;
use EventSauce\EventSourcing\MessageDecoratorChain;
use EventSauce\EventSourcing\MessageDispatcher;
use EventSauce\EventSourcing\MessageRepository;
use EventSauce\EventSourcing\SynchronousMessageDispatcher;
use Exception;
use LogicException;
use PHPUnit\Framework\TestCase;
use Throwable;
use function assert;
use function get_class;
use function method_exists;
use function sprintf;

/**
 * @method handle(...$arguments)
 */
abstract class AggregateRootTestCase extends TestCase
{
    /**
     * @var InMemoryMessageRepository
     */
    protected $messageRepository;

    /**
     * @phpstan-var AggregateRootRepository<AggregateRoot>
     */
    protected AggregateRootRepository $repository;

    /**
     * @var Exception|null
     */
    private $caughtException;

    /**
     * @var object[]
     */
    private $expectedEvents = [];

    /**
     * @var Exception|null
     */
    private $theExpectedException;

    /**
     * @var TestClock
     */
    private $clock;

    /**
     * @var bool
     */
    private $assertedScenario = false;

    /**
     * @var AggregateRootId
     */
    protected $aggregateRootId;

    /**
     * @before
     */
    protected function setUpEventSauce(): void
    {
        $className = $this->aggregateRootClassName();
        $this->clock = new TestClock();
        $this->aggregateRootId = $this->newAggregateRootId();
        $this->messageRepository = new InMemoryMessageRepository();
        $dispatcher = $this->messageDispatcher();
        $decorator = $this->messageDecorator();
        $this->repository = $this->aggregateRootRepository(
            $className,
            $this->messageRepository,
            $dispatcher,
            $decorator
        );
        $this->expectedEvents = [];
        $this->assertedScenario = false;
        $this->theExpectedException = null;
        $this->caughtException = null;
    }

    protected function retrieveAggregateRoot(AggregateRootId $id): object
    {
        return $this->repository->retrieve($id);
    }

    protected function persistAggregateRoot(AggregateRoot $aggregateRoot): void
    {
        $this->repository->persist($aggregateRoot);
    }

    /**
     * @after
     */
    protected function assertScenario(): void
    {
        // @codeCoverageIgnoreStart
        if ($this->assertedScenario) {
            return;
        }
        // @codeCoverageIgnoreEnd

        try {
            $this->assertExpectedException($this->theExpectedException, $this->caughtException);
            $this->assertLastCommitEqualsEvents(...$this->expectedEvents);
            $this->messageRepository->purgeLastCommit();
        } finally {
            $this->assertedScenario = true;
            $this->theExpectedException = null;
            $this->caughtException = null;
        }
    }

    protected function aggregateRootId(): AggregateRootId
    {
        return $this->aggregateRootId;
    }

    abstract protected function newAggregateRootId(): AggregateRootId;

    /**
     * @phpstan-return class-string<AggregateRoot>
     */
    abstract protected function aggregateRootClassName(): string;

    /**
     * @return $this
     */
    protected function given(object ...$events)
    {
        $this->repository->persistEvents($this->aggregateRootId(), count($events), ...$events);
        $this->messageRepository->purgeLastCommit();

        return $this;
    }

    public function on(AggregateRootId $id): EventStager
    {
        return new EventStager($id, $this->messageRepository, $this->repository, $this);
    }

    /**
     * @param mixed[] $arguments
     *
     * @return $this
     */
    protected function when(...$arguments)
    {
        try {
            if ( ! method_exists($this, 'handle')) {
                throw new LogicException(sprintf('Class %s is missing a ::handle method.', get_class($this)));
            }

            $this->handle(...$arguments);
        } catch (Exception $exception) {
            $this->caughtException = $exception;
        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function then(object ...$events)
    {
        $this->expectedEvents = $events;

        return $this;
    }

    /**
     * @return $this
     */
    public function expectToFail(Exception $expectedException)
    {
        $this->theExpectedException = $expectedException;

        return $this;
    }

    /**
     * @return $this
     */
    protected function thenNothingShouldHaveHappened()
    {
        $this->expectedEvents = [];

        return $this;
    }

    protected function assertLastCommitEqualsEvents(object ...$events): void
    {
        self::assertEquals($events, $this->messageRepository->lastCommit(), 'Events are not equal.');
    }

    private function assertExpectedException(
        Exception $expectedException = null,
        Exception $caughtException = null
    ): void {
        if (null === $caughtException && null === $expectedException) {
            return;
        } elseif (null !== $expectedException && null === $caughtException) {
            throw FailedToDetectExpectedException::expectedException($expectedException);
        } elseif (null !== $caughtException && (null === $expectedException || get_class($expectedException) !== get_class(
                    $caughtException
                ))) {
            throw $caughtException;
        }

        assert($expectedException instanceof Throwable);
        assert($caughtException instanceof Throwable);
        self::assertEquals(get_class($expectedException), get_class($caughtException), 'Exception types should be equal.');
        self::assertEquals($expectedException->getMessage(), $caughtException->getMessage(), 'Exception messages should be equal.');
        self::assertEquals($expectedException->getCode(), $caughtException->getCode(), 'Exception messages should be equal.');
    }

    protected function currentTime(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    protected function clock(): TestClock
    {
        return $this->clock;
    }

    protected function messageDispatcher(): MessageDispatcher
    {
        return new SynchronousMessageDispatcher(
            new MessageConsumerThatSerializesMessages(), ...$this->consumers()
        );
    }

    /**
     * @return MessageConsumer[]
     */
    protected function consumers(): array
    {
        return [];
    }

    private function messageDecorator(): MessageDecorator
    {
        return new MessageDecoratorChain(new DefaultHeadersDecorator());
    }

    /**
     * @template T of AggregateRoot
     *
     * @phpstan-param class-string<T> $className
     *
     * @phpstan-return AggregateRootRepository<T>
     */
    protected function aggregateRootRepository(
        string $className,
        MessageRepository $repository,
        MessageDispatcher $dispatcher,
        MessageDecorator $decorator
    ): AggregateRootRepository {
        return new EventSourcedAggregateRootRepository(
            $className, $repository, $dispatcher, $decorator
        );
    }
}
