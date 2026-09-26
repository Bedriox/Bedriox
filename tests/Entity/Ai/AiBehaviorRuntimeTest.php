<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Entity\Ai;

use Bedriox\Api\Entity\MobActivationState;
use Bedriox\Server\Entity\AbstractEntity;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\AiControl;
use Bedriox\Server\Entity\Ai\AiGoal;
use Bedriox\Server\Entity\Ai\AiMemoryStore;
use Bedriox\Server\Entity\Ai\AiMemoryType;
use Bedriox\Server\Entity\Ai\AiSensor;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\AiWorldView;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\ZombieEntity;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AiBehaviorRuntimeTest extends TestCase
{
    public function testMemoryExpiryIsLazyBoundedAndDeterministic(): void
    {
        $memory = new AiMemoryStore();
        $target = new AiMemoryType(0, 'bedriox:target');
        $memory->put($target, 42, 10);

        self::assertTrue($memory->contains($target, 9));
        self::assertSame(42, $memory->get($target, 9));
        self::assertFalse($memory->contains($target, 10));
        self::assertNull($memory->get($target, 10));

        $this->expectException(InvalidArgumentException::class);
        new AiMemoryType(AiMemoryStore::MAXIMUM_MEMORIES, 'bedriox:too_many');
    }

    public function testSensorsAreStaggeredAndConflictingGoalsDoNotRunTogether(): void
    {
        $trace = new AiTrace();
        $sensor = new TestSensor($trace, 2);
        $high = new TestGoal($trace, 'bedriox:high', 100, [AiControl::MOVE]);
        $low = new TestGoal($trace, 'bedriox:low', 10, [AiControl::MOVE]);
        $look = new TestGoal($trace, 'bedriox:look', 5, [AiControl::LOOK]);
        $mob = new ZombieEntity(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.0, 64.0, 0.0),
            new AiBehaviorDefinition([$sensor], [$low, $look, $high]),
        );
        $world = new EmptyAiWorldView();

        $first = $mob->tickAi(new AiTickContext(1, $world), true);
        self::assertSame(1, $first->sensorsRun);
        self::assertSame(3, $first->goalsEvaluated);
        self::assertSame(['sensor', 'start:high', 'start:look'], $trace->entries);

        $trace->entries = [];
        $second = $mob->tickAi(new AiTickContext(2, $world), true);
        self::assertSame(0, $second->sensorsRun);
        self::assertSame(2, $second->goalsTicked);
        self::assertSame(['tick:high', 'tick:look'], $trace->entries);
        self::assertNotContains('start:low', $trace->entries);
    }

    public function testSleepingOrGloballyDisabledAiStopsGoalsWithoutStoppingEntityLifecycle(): void
    {
        $trace = new AiTrace();
        $goal = new TestGoal($trace, 'bedriox:move', 1, [AiControl::MOVE]);
        $mob = new ZombieEntity(
            EntityUuid::random(),
            5,
            'world',
            new Position(0.0, 64.0, 0.0),
            new AiBehaviorDefinition(goals: [$goal]),
        );
        $context = new AiTickContext(1, new EmptyAiWorldView());
        $mob->tickAi($context, true);
        $mob->setActivationState(MobActivationState::SLEEPING, new AiTickContext(2, $context->world));

        self::assertContains('stop:move', $trace->entries);
        self::assertSame(0, $mob->tickAi(new AiTickContext(3, $context->world), true)->goalsTicked);
        self::assertTrue($mob->isAlive());

        $mob->setActivationState(MobActivationState::ACTIVE);
        self::assertSame(0, $mob->tickAi(new AiTickContext(4, $context->world), false)->goalsEvaluated);
        self::assertTrue($mob->isAlive());
    }

    public function testHigherPriorityGoalPreemptsAConflictingRunningGoal(): void
    {
        $trace = new AiTrace();
        $high = new EnabledTestGoal($trace, 'bedriox:high', 100, false);
        $low = new EnabledTestGoal($trace, 'bedriox:low', 10, true);
        $mob = new ZombieEntity(
            EntityUuid::random(),
            7,
            'world',
            new Position(0.0, 64.0, 0.0),
            new AiBehaviorDefinition(goals: [$low, $high]),
        );
        $world = new EmptyAiWorldView();

        $mob->tickAi(new AiTickContext(1, $world), true);
        self::assertSame(['start:low'], $trace->entries);

        $trace->entries = [];
        $high->enabled = true;
        $mob->tickAi(new AiTickContext(2, $world), true);

        self::assertSame(['stop:low', 'start:high'], $trace->entries);
    }
}

final class AiTrace
{
    /** @var list<string> */
    public array $entries = [];
}

final readonly class TestSensor implements AiSensor
{
    public function __construct(private AiTrace $trace, private int $interval) {}

    public function identifier(): string
    {
        return 'bedriox:test_sensor';
    }

    public function intervalTicks(): int
    {
        return $this->interval;
    }

    public function sense(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $this->trace->entries[] = 'sensor';
    }
}

final readonly class TestGoal implements AiGoal
{
    /** @param list<AiControl> $controls */
    public function __construct(
        private AiTrace $trace,
        private string $id,
        private int $goalPriority,
        private array $controls,
    ) {}

    public function identifier(): string
    {
        return $this->id;
    }

    public function priority(): int
    {
        return $this->goalPriority;
    }

    public function evaluationIntervalTicks(): int
    {
        return 1;
    }

    public function controls(): array
    {
        return $this->controls;
    }

    public function canStart(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        return true;
    }

    public function shouldContinue(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        return true;
    }

    public function start(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $this->trace->entries[] = 'start:' . substr($this->id, strlen('bedriox:'));
    }

    public function tick(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $this->trace->entries[] = 'tick:' . substr($this->id, strlen('bedriox:'));
    }

    public function stop(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $this->trace->entries[] = 'stop:' . substr($this->id, strlen('bedriox:'));
    }
}

final class EnabledTestGoal implements AiGoal
{
    public function __construct(
        private readonly AiTrace $trace,
        private readonly string $id,
        private readonly int $goalPriority,
        public bool $enabled,
    ) {}

    public function identifier(): string
    {
        return $this->id;
    }

    public function priority(): int
    {
        return $this->goalPriority;
    }

    public function evaluationIntervalTicks(): int
    {
        return 1;
    }

    public function controls(): array
    {
        return [AiControl::MOVE];
    }

    public function canStart(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        return $this->enabled;
    }

    public function shouldContinue(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        return $this->enabled;
    }

    public function start(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $this->trace->entries[] = 'start:' . substr($this->id, strlen('bedriox:'));
    }

    public function tick(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $this->trace->entries[] = 'tick:' . substr($this->id, strlen('bedriox:'));
    }

    public function stop(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $this->trace->entries[] = 'stop:' . substr($this->id, strlen('bedriox:'));
    }
}

final class EmptyAiWorldView implements AiWorldView
{
    public function nearbyEntities(AbstractMobEntity $entity, float $radius, int $limit): array
    {
        return [];
    }

    public function nearestPlayerDistanceSquared(AbstractMobEntity $entity): ?float
    {
        return null;
    }
}
