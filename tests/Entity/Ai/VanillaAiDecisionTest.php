<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Entity\Ai;

use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\AiPlayerSnapshot;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\Sensor\NearestPlayerSensor;
use Bedriox\Server\Entity\Ai\TargetAwareAiWorldView;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Entity\Vanilla\ZombieEntity;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class VanillaAiDecisionTest extends TestCase
{
    public function testNearestPlayerSensorUsesStaggeredCadenceAndExpiringMemory(): void
    {
        $world = new TargetAiWorldView(new AiPlayerSnapshot(
            'player-one',
            'world',
            new Position(4.0, 64.0, 0.0),
        ));
        $mob = new ZombieEntity(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.0, 64.0, 0.0),
            new AiBehaviorDefinition(sensors: [
                new NearestPlayerSensor('bedriox:test_nearest_player', 3, 32.0, 5),
            ]),
        );

        $mob->tickAi(new AiTickContext(1, $world), true);
        self::assertSame(0, $world->targetQueries);
        $mob->tickAi(new AiTickContext(2, $world), true);
        self::assertSame(1, $world->targetQueries);
        self::assertInstanceOf(
            AiPlayerSnapshot::class,
            $mob->aiRuntime()->memory()->get(VanillaAiMemories::nearestPlayer(), 2),
        );

        $world->target = null;
        for ($tick = 3; $tick <= 6; ++$tick) {
            $mob->tickAi(new AiTickContext($tick, $world), true);
        }
        self::assertSame(2, $world->targetQueries);
        self::assertInstanceOf(
            AiPlayerSnapshot::class,
            $mob->aiRuntime()->memory()->get(VanillaAiMemories::nearestPlayer(), 6),
        );
        self::assertNull($mob->aiRuntime()->memory()->get(VanillaAiMemories::nearestPlayer(), 7));
    }

    public function testHurtCowPreemptsIdleMovementAndFleesFromRememberedPlayer(): void
    {
        $world = new TargetAiWorldView(new AiPlayerSnapshot(
            'player-one',
            'world',
            new Position(5.0, 64.0, 0.0),
        ));
        $cow = new CowEntity(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.0, 64.0, 0.0),
        );

        for ($tick = 1; $tick <= 9; ++$tick) {
            $cow->tickAi(new AiTickContext($tick, $world), true);
        }
        self::assertSame(0.0, $cow->getMotion()->x);

        $cow->damage(1.0);
        $cow->tickAi(new AiTickContext(10, $world), true);

        self::assertLessThan(0.0, $cow->getMotion()->x);
        self::assertEqualsWithDelta(0.18, abs($cow->getMotion()->x), 0.000_001);
    }

    public function testZombieChasesThenEmitsOneCooldownBoundedMeleeIntent(): void
    {
        $world = new TargetAiWorldView(new AiPlayerSnapshot(
            'player-one',
            'world',
            new Position(10.0, 64.0, 0.0),
        ));
        $zombie = new ZombieEntity(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.0, 64.0, 0.0),
        );

        for ($tick = 1; $tick <= 9; ++$tick) {
            $zombie->tickAi(new AiTickContext($tick, $world), true);
        }
        self::assertGreaterThan(0.0, $zombie->getMotion()->x);

        $world->target = new AiPlayerSnapshot('player-one', 'world', new Position(1.0, 64.0, 0.0));
        for ($tick = 10; $tick <= 19; ++$tick) {
            $zombie->tickAi(new AiTickContext($tick, $world), true);
        }
        $intent = $zombie->aiRuntime()->takeMeleeIntent(19);
        self::assertNotNull($intent);
        self::assertSame('player-one', $intent->targetPlayerId);
        self::assertSame(19, $intent->createdAtTick);
        self::assertSame(3.0, $intent->damage);
        self::assertSame(0.0, $zombie->getMotion()->x);

        $zombie->tickAi(new AiTickContext(20, $world), true);
        self::assertNull($zombie->aiRuntime()->takeMeleeIntent(20));
    }
}

final class TargetAiWorldView implements TargetAwareAiWorldView
{
    public int $targetQueries = 0;

    public function __construct(public ?AiPlayerSnapshot $target) {}

    public function nearbyEntities(AbstractMobEntity $entity, float $radius, int $limit): array
    {
        return [];
    }

    public function nearestPlayerDistanceSquared(AbstractMobEntity $entity): ?float
    {
        return $this->target?->distanceSquaredTo($entity->internalPosition());
    }

    public function nearestPlayer(AbstractMobEntity $entity, float $radius): ?AiPlayerSnapshot
    {
        ++$this->targetQueries;
        if ($this->target === null
            || $this->target->worldName !== $entity->getWorldName()
            || $this->target->distanceSquaredTo($entity->internalPosition()) > $radius ** 2) {
            return null;
        }

        return $this->target;
    }
}
