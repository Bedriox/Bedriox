<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace Bedriox\Server\Tests\Entity\Ai;

use Bedriox\Api\Entity\Value\PandaActivity;
use Bedriox\Api\Entity\Value\PandaGene;
use Bedriox\Server\Entity\AbstractEntity;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiMemoryStore;
use Bedriox\Server\Entity\Ai\AiPlayerSnapshot;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\Goal\AvoidUntrustedPlayerGoal;
use Bedriox\Server\Entity\Ai\Goal\PandaWanderGoal;
use Bedriox\Server\Entity\Ai\Goal\WorriedPandaAvoidThreatGoal;
use Bedriox\Server\Entity\Ai\PlayerIdentityAiWorldView;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\OcelotEntity;
use Bedriox\Server\Entity\Vanilla\PandaEntity;
use Bedriox\Server\Entity\Vanilla\ZombieEntity;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PandaOcelotQualificationTest extends TestCase
{
    #[DataProvider('recessiveGenes')]
    public function testRecessivePandaGenesRequireMatchingAlleles(PandaGene $gene): void
    {
        $heterozygous = self::panda($gene, PandaGene::NORMAL);
        $homozygous = self::panda($gene, $gene);

        self::assertSame(PandaGene::NORMAL, $heterozygous->getExpressedGene());
        self::assertSame($gene, $homozygous->getExpressedGene());
    }

    /** @return iterable<string, array{PandaGene}> */
    public static function recessiveGenes(): iterable
    {
        yield 'brown' => [PandaGene::BROWN];
        yield 'weak' => [PandaGene::WEAK];
    }

    public function testWeakGeneImmediatelyClampsHealthAndSurvivesPersistence(): void
    {
        $panda = self::panda(PandaGene::NORMAL, PandaGene::NORMAL);
        self::assertSame(20.0, $panda->getHealth());

        $panda->setGenes(PandaGene::WEAK, PandaGene::WEAK);

        self::assertSame(10.0, $panda->getMaximumHealth());
        self::assertSame(10.0, $panda->getHealth());
        $restored = self::panda(PandaGene::NORMAL, PandaGene::NORMAL, 2);
        $restored->restorePersistenceState(
            $panda->persistenceVariant(),
            $panda->persistenceSchemaVersion(),
            $panda->persistenceData(),
        );
        self::assertSame(PandaGene::WEAK, $restored->getExpressedGene());
        self::assertSame(10.0, $restored->getMaximumHealth());
        self::assertSame(10.0, $restored->getHealth());
    }

    #[DataProvider('scheduledActivities')]
    public function testPandaPersonalityActivityStartsOnItsBoundedScheduleAndReturnsToIdle(
        PandaGene $gene,
        bool $baby,
        bool $thundering,
        int $tick,
        PandaActivity $expected,
        int $duration,
    ): void {
        $panda = new PandaEntity(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.0, 64.0, 0.0),
            baby: $baby,
            mainGene: $gene,
            hiddenGene: $gene,
        );

        $panda->advanceTraitActivity($thundering, 1, $tick);
        self::assertSame($expected, $panda->getActivity());
        for ($elapsed = 0; $elapsed < $duration; ++$elapsed) {
            $panda->advanceTraitActivity(false, 1, $tick + $elapsed + 1);
        }
        self::assertSame(PandaActivity::IDLE, $panda->getActivity());
    }

    /** @return iterable<string, array{PandaGene, bool, bool, int, PandaActivity, int}> */
    public static function scheduledActivities(): iterable
    {
        yield 'worried thunder fear' => [
            PandaGene::WORRIED,
            false,
            true,
            20,
            PandaActivity::SCARED,
            200,
        ];
        yield 'playful roll' => [
            PandaGene::PLAYFUL,
            false,
            false,
            159,
            PandaActivity::ROLLING,
            40,
        ];
        yield 'lazy sitting' => [
            PandaGene::LAZY,
            false,
            false,
            239,
            PandaActivity::SITTING,
            100,
        ];
        yield 'baby sneeze' => [
            PandaGene::NORMAL,
            true,
            false,
            599,
            PandaActivity::SNEEZING,
            20,
        ];
    }

    public function testPersistedPandaActivityResumesForItsRemainingBoundedDuration(): void
    {
        $panda = new PandaEntity(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.0, 64.0, 0.0),
            mainGene: PandaGene::WORRIED,
            hiddenGene: PandaGene::WORRIED,
        );
        $panda->advanceTraitActivity(true, 1, 20);
        $panda->advanceTraitActivity(false, 20, 40);

        $restored = self::panda(PandaGene::NORMAL, PandaGene::NORMAL, 2);
        $restored->restorePersistenceState(
            $panda->persistenceVariant(),
            $panda->persistenceSchemaVersion(),
            $panda->persistenceData(),
        );
        self::assertSame(PandaActivity::SCARED, $restored->getActivity());
        for ($elapsed = 0; $elapsed < 180; ++$elapsed) {
            $restored->advanceTraitActivity(false, 1, 41 + $elapsed);
        }
        self::assertSame(PandaActivity::IDLE, $restored->getActivity());
    }

    public function testOnlyWorriedPandaAvoidsNearbyPlayerAndStopsOutsideBoundedRange(): void
    {
        $player = new AiPlayerSnapshot(EntityUuid::random(), 'world', new Position(2.0, 64.0, 0.0));
        $world = new PandaOcelotAiWorld([$player]);
        $memory = new AiMemoryStore();
        $memory->put(VanillaAiMemories::nearestPlayer(), $player, 40);
        $context = new AiTickContext(20, $world);
        $goal = new WorriedPandaAvoidThreatGoal();
        $worried = self::panda(PandaGene::WORRIED, PandaGene::WORRIED);

        self::assertTrue($goal->canStart($worried, $memory, $context));
        $goal->start($worried, $memory, $context);
        self::assertLessThan(0.0, $worried->getMotion()->x);
        self::assertFalse($goal->canStart(
            self::panda(PandaGene::NORMAL, PandaGene::NORMAL, 2),
            $memory,
            $context,
        ));

        $farPlayer = new AiPlayerSnapshot($player->playerId, 'world', new Position(8.01, 64.0, 0.0));
        $farMemory = new AiMemoryStore();
        $farMemory->put(VanillaAiMemories::nearestPlayer(), $farPlayer, 40);
        self::assertFalse($goal->shouldContinue(
            $worried,
            $farMemory,
            new AiTickContext(21, new PandaOcelotAiWorld([$farPlayer])),
        ));
        $goal->stop($worried, $farMemory, $context);
        self::assertSame(0.0, $worried->getMotion()->x);
    }

    public function testWorriedPandaAvoidsNearestHostileUsingBoundedDeterministicQuery(): void
    {
        $fartherPlayer = new AiPlayerSnapshot(
            EntityUuid::random(),
            'world',
            new Position(5.0, 64.0, 0.0),
        );
        $nearerZombie = new ZombieEntity(
            EntityUuid::random(),
            12,
            'world',
            new Position(-2.0, 64.0, 0.0),
        );
        $world = new PandaOcelotAiWorld([$fartherPlayer], [$nearerZombie]);
        $memory = new AiMemoryStore();
        $memory->put(VanillaAiMemories::nearestPlayer(), $fartherPlayer, 40);
        $panda = self::panda(PandaGene::WORRIED, PandaGene::WORRIED);
        $context = new AiTickContext(20, $world);
        $goal = new WorriedPandaAvoidThreatGoal();

        self::assertTrue($goal->canStart($panda, $memory, $context));
        $goal->start($panda, $memory, $context);

        self::assertGreaterThan(0.0, $panda->getMotion()->x);
        self::assertSame(32, $world->maximumNearbyLimit);
    }

    public function testWorriedPandaHostileDistanceTieUsesLowestRuntimeIdentity(): void
    {
        $higherRuntime = new ZombieEntity(
            EntityUuid::random(),
            20,
            'world',
            new Position(2.0, 64.0, 0.0),
        );
        $lowerRuntime = new ZombieEntity(
            EntityUuid::random(),
            10,
            'world',
            new Position(-2.0, 64.0, 0.0),
        );
        $world = new PandaOcelotAiWorld([], [$higherRuntime, $lowerRuntime]);
        $panda = self::panda(PandaGene::WORRIED, PandaGene::WORRIED);
        $goal = new WorriedPandaAvoidThreatGoal();
        $context = new AiTickContext(20, $world);

        $goal->start($panda, new AiMemoryStore(), $context);

        self::assertGreaterThan(0.0, $panda->getMotion()->x);
    }

    public function testPandaWanderSpeedReflectsNormalLazyAndPlayfulPersonality(): void
    {
        $goal = new PandaWanderGoal();
        $world = new PandaOcelotAiWorld([]);
        $speeds = [];
        foreach ([
            PandaGene::LAZY,
            PandaGene::NORMAL,
            PandaGene::PLAYFUL,
        ] as $runtimeId => $gene) {
            $panda = self::panda($gene, $gene, $runtimeId + 1);
            $memory = new AiMemoryStore();
            $context = new AiTickContext(40, $world);
            self::assertTrue($goal->canStart($panda, $memory, $context));
            $goal->start($panda, $memory, $context);
            $x = $memory->get(VanillaAiMemories::wanderMotionX(), 40);
            $z = $memory->get(VanillaAiMemories::wanderMotionZ(), 40);
            self::assertIsFloat($x);
            self::assertIsFloat($z);
            $speeds[$gene->value] = hypot($x, $z);
        }

        self::assertEqualsWithDelta(0.03, $speeds[PandaGene::LAZY->value], 0.000_001);
        self::assertEqualsWithDelta(0.06, $speeds[PandaGene::NORMAL->value], 0.000_001);
        self::assertEqualsWithDelta(0.075, $speeds[PandaGene::PLAYFUL->value], 0.000_001);
    }

    public function testMalformedPandaActivityTimerFailsClosed(): void
    {
        $panda = self::panda(PandaGene::NORMAL, PandaGene::NORMAL);
        $data = json_decode($panda->persistenceData(), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $data['activityTicks'] = 401;

        $this->expectException(InvalidArgumentException::class);
        $panda->restorePersistenceState(
            $panda->persistenceVariant(),
            $panda->persistenceSchemaVersion(),
            json_encode($data, JSON_THROW_ON_ERROR),
        );
    }

    public function testUntrustedOcelotFleesNearestPlayerButTrustedPlayerDoesNotTriggerAvoidance(): void
    {
        $trustedPlayer = new AiPlayerSnapshot(
            EntityUuid::random(),
            'world',
            new Position(2.0, 64.0, 0.0),
        );
        $ocelot = new OcelotEntity(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
        $world = new PandaOcelotAiWorld([$trustedPlayer]);
        $memory = new AiMemoryStore();
        $memory->put(VanillaAiMemories::nearestPlayer(), $trustedPlayer, 40);
        $context = new AiTickContext(20, $world);
        $goal = new AvoidUntrustedPlayerGoal('test:avoid', 1, 0.2, 12.0);

        self::assertTrue($goal->canStart($ocelot, $memory, $context));
        $goal->start($ocelot, $memory, $context);
        self::assertLessThan(0.0, $ocelot->getMotion()->x);

        $ocelot->setTrustedPlayerUniqueId($trustedPlayer->playerId);
        self::assertFalse($goal->shouldContinue($ocelot, $memory, $context));
        $goal->stop($ocelot, $memory, $context);
        self::assertSame(0.0, $ocelot->getMotion()->x);
    }

    public function testOcelotTrustIsExactCaseInsensitiveAndPersistenceRejectsMalformedIdentity(): void
    {
        $trustedPlayerId = EntityUuid::random();
        $ocelot = new OcelotEntity(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.0, 64.0, 0.0),
            trustedPlayerUniqueId: strtoupper($trustedPlayerId),
        );

        self::assertTrue($ocelot->trustsPlayer(strtolower($trustedPlayerId)));
        self::assertFalse($ocelot->trustsPlayer(EntityUuid::random()));
        $data = json_decode($ocelot->persistenceData(), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $data['trustedPlayerUniqueId'] = 'not-a-uuid';

        $this->expectException(InvalidArgumentException::class);
        $ocelot->restorePersistenceState(
            $ocelot->persistenceVariant(),
            $ocelot->persistenceSchemaVersion(),
            json_encode($data, JSON_THROW_ON_ERROR),
        );
    }

    private static function panda(PandaGene $main, PandaGene $hidden, int $runtimeId = 1): PandaEntity
    {
        return new PandaEntity(
            EntityUuid::random(),
            $runtimeId,
            'world',
            new Position(0.0, 64.0, 0.0),
            mainGene: $main,
            hiddenGene: $hidden,
        );
    }
}

final class PandaOcelotAiWorld implements PlayerIdentityAiWorldView
{
    public int $maximumNearbyLimit = 0;

    /**
     * @param list<AiPlayerSnapshot> $players
     * @param list<AbstractEntity> $entities
     */
    public function __construct(
        private readonly array $players,
        private readonly array $entities = [],
    ) {}

    public function nearbyEntities(AbstractMobEntity $entity, float $radius, int $limit): array
    {
        $this->maximumNearbyLimit = max($this->maximumNearbyLimit, $limit);

        return array_slice(array_values(array_filter(
            $this->entities,
            static fn(AbstractEntity $candidate): bool => $candidate !== $entity
                && $candidate->getWorldName() === $entity->getWorldName()
                && $candidate->internalPosition()->distanceTo($entity->internalPosition()) <= $radius,
        )), 0, $limit);
    }

    public function nearestPlayerDistanceSquared(AbstractMobEntity $entity): ?float
    {
        return $this->nearestPlayer($entity, 256.0)?->distanceSquaredTo($entity->internalPosition());
    }

    public function nearestPlayer(AbstractMobEntity $entity, float $radius): ?AiPlayerSnapshot
    {
        $nearest = null;
        $nearestDistance = null;
        foreach ($this->players as $player) {
            if ($player->worldName !== $entity->getWorldName()) {
                continue;
            }
            $distance = $player->distanceSquaredTo($entity->internalPosition());
            if ($distance <= $radius ** 2 && ($nearestDistance === null || $distance < $nearestDistance)) {
                $nearest = $player;
                $nearestDistance = $distance;
            }
        }

        return $nearest;
    }

    public function nearestPlayerHolding(
        AbstractMobEntity $entity,
        float $radius,
        array $itemIdentifiers,
    ): ?AiPlayerSnapshot {
        return null;
    }

    public function playerByIdentity(string $playerId): ?AiPlayerSnapshot
    {
        foreach ($this->players as $player) {
            if ($player->playerId === $playerId) {
                return $player;
            }
        }

        return null;
    }
}
