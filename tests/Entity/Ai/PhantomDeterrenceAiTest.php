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

use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Packet\AddActorPacket;
use Bedriox\Protocol\Packet\MobArmorEquipmentPacket;
use Bedriox\Protocol\Packet\MobEquipmentPacket;
use Bedriox\Server\Entity\AbstractEntity;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiPlayerSnapshot;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\TargetAwareAiWorldView;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Spawn\EntitySpawnService;
use Bedriox\Server\Entity\Vanilla\CatEntity;
use Bedriox\Server\Entity\Vanilla\OcelotEntity;
use Bedriox\Server\Entity\Vanilla\PhantomEntity;
use Bedriox\Server\Runtime\BedrockWorldEventPacketEncoder;
use Bedriox\Server\Simulation\Event\EntityActorSpawned;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhantomDeterrenceAiTest extends TestCase
{
    public function testCurrentCatalogFactorySpawnEggAndActorProjectionAgreeOnPhantomIdentity(): void
    {
        $catalog = BedrockDataSet::bundled()->entityTypeRegistry();
        $network = $catalog->definitionForIdentifier('minecraft:phantom');
        self::assertSame(58, $network->networkRuntimeId());
        self::assertTrue($network->summonable());
        self::assertTrue($network->spawnEggAdvertised());
        self::assertSame('minecraft:phantom_spawn_egg', $network->spawnEggItemIdentifier());
        self::assertSame($network, $catalog->entityForSpawnEgg('minecraft:phantom_spawn_egg'));

        $definitions = EntityDefinitionRegistry::fromData($catalog);
        $registration = $definitions->require(VanillaEntityType::PHANTOM);
        self::assertSame('minecraft:phantom', $registration->definition->networkIdentifier);
        self::assertTrue($registration->definition->persistent);
        self::assertSame(0.0, $registration->definition->gravity);
        self::assertTrue($registration->definition->burnsInDaylight);

        $outcome = (new EntitySpawnService(new EntityRegistry(), $definitions))->spawn(new EntitySpawnRequest(
            VanillaEntityType::PHANTOM,
            SpawnCause::SPAWN_EGG,
            'world',
            new Position(4.5, 72.0, 8.5),
            45.0,
            -15.0,
        ));
        self::assertTrue($outcome->succeeded(), $outcome->failure ?? 'unknown');
        $phantom = $outcome->entity;
        self::assertInstanceOf(PhantomEntity::class, $phantom);
        self::assertTrue($phantom->isPersistent());

        $directed = (new BedrockWorldEventPacketEncoder())->encode(
            new EntityActorSpawned($phantom, ['viewer']),
            [],
        );
        self::assertCount(4, $directed);
        self::assertSame(['viewer', 'viewer', 'viewer', 'viewer'], array_map(
            static fn($packet): string => $packet->sessionId,
            $directed,
        ));
        self::assertInstanceOf(AddActorPacket::class, $directed[0]->packet);
        self::assertInstanceOf(MobArmorEquipmentPacket::class, $directed[1]->packet);
        self::assertInstanceOf(MobEquipmentPacket::class, $directed[2]->packet);
        self::assertInstanceOf(MobEquipmentPacket::class, $directed[3]->packet);
        self::assertSame('minecraft:phantom', $directed[0]->packet->identifier);
        self::assertSame(-15.0, $directed[0]->packet->pitch);
        self::assertSame(45.0, $directed[0]->packet->yaw);

        $decoded = AddActorPacket::decode($directed[0]->packet->encode());
        self::assertSame('minecraft:phantom', $decoded->identifier);
        self::assertSame($phantom->getRuntimeId(), $decoded->runtimeEntityId->toSignedBits());
    }

    /** @param class-string<CatEntity|OcelotEntity> $felineClass */
    #[DataProvider('felineProvider')]
    public function testQualifiedFelinePreemptsTargetPursuitAndForcesFlightAway(string $felineClass): void
    {
        $phantom = new PhantomEntity(self::uuid(1), 58, 'world', new Position(0.0, 70.0, 0.0));
        $feline = new $felineClass(self::uuid(2), 2, 'world', new Position(2.0, 70.0, 0.0));
        $target = new AiPlayerSnapshot('target', 'world', new Position(10.0, 70.0, 0.0));
        $world = new PhantomAiWorld($target, [$feline]);

        $phantom->tickAi(new AiTickContext(2, $world), true);

        self::assertLessThan(0.0, $phantom->getMotion()->x);
        self::assertNull($phantom->aiRuntime()->takeMeleeIntent(2));
    }

    public function testPhantomResumesNormalTargetPursuitAfterFelineLeavesRange(): void
    {
        $phantom = new PhantomEntity(self::uuid(3), 58, 'world', new Position(0.0, 70.0, 0.0));
        $cat = new CatEntity(self::uuid(4), 4, 'world', new Position(2.0, 70.0, 0.0));
        $world = new PhantomAiWorld(
            new AiPlayerSnapshot('target', 'world', new Position(10.0, 74.0, 0.0)),
            [$cat],
        );
        $phantom->tickAi(new AiTickContext(2, $world), true);
        self::assertLessThan(0.0, $phantom->getMotion()->x);

        $world->entities = [];
        $phantom->tickAi(new AiTickContext(4, $world), true);
        self::assertGreaterThan(0.0, $phantom->getMotion()->x);
        self::assertGreaterThan(0.0, $phantom->getMotion()->y);
    }

    public function testFelineArrivalPreemptsAnAlreadyRunningPursuit(): void
    {
        $phantom = new PhantomEntity(self::uuid(10), 58, 'world', new Position(0.0, 70.0, 0.0));
        $world = new PhantomAiWorld(
            new AiPlayerSnapshot('target', 'world', new Position(10.0, 70.0, 0.0)),
            [],
        );
        $phantom->tickAi(new AiTickContext(2, $world), true);
        self::assertGreaterThan(0.0, $phantom->getMotion()->x);

        $world->entities = [new OcelotEntity(self::uuid(11), 11, 'world', new Position(2.0, 70.0, 0.0))];
        $phantom->tickAi(new AiTickContext(4, $world), true);

        self::assertLessThan(0.0, $phantom->getMotion()->x);
        self::assertNull($phantom->aiRuntime()->takeMeleeIntent(4));
    }

    public function testPursuitStopsWhenItsTargetDisappears(): void
    {
        $phantom = new PhantomEntity(self::uuid(12), 58, 'world', new Position(0.0, 70.0, 0.0));
        $world = new PhantomAiWorld(
            new AiPlayerSnapshot('target', 'world', new Position(10.0, 70.0, 0.0)),
            [],
        );
        $phantom->tickAi(new AiTickContext(2, $world), true);
        self::assertGreaterThan(0.0, $phantom->getMotion()->x);

        $world->target = null;
        $phantom->aiRuntime()->memory()->forget(VanillaAiMemories::nearestPlayer());
        $phantom->tickAi(new AiTickContext(3, $world), true);

        self::assertSame(0.0, $phantom->getMotion()->x);
        self::assertSame(0.0, $phantom->getMotion()->y);
        self::assertSame(0.0, $phantom->getMotion()->z);
    }

    public function testPhantomEmitsBoundedMeleeIntentWhenTargetIsInReachWithoutFeline(): void
    {
        $phantom = new PhantomEntity(self::uuid(5), 58, 'world', new Position(0.0, 70.0, 0.0));
        $world = new PhantomAiWorld(
            new AiPlayerSnapshot('target', 'world', new Position(1.0, 70.0, 0.0)),
            [],
        );

        $phantom->tickAi(new AiTickContext(2, $world), true);
        $intent = $phantom->aiRuntime()->takeMeleeIntent(2);

        self::assertNotNull($intent);
        self::assertSame('target', $intent->targetPlayerId);
        self::assertSame(2.5, $intent->maximumReach);
        self::assertSame(6.0, $intent->damage);
    }

    public function testDeterrenceIgnoresDeadForeignWorldAndOutOfRangeFelines(): void
    {
        $phantom = new PhantomEntity(self::uuid(6), 58, 'world', new Position(0.0, 70.0, 0.0));
        $dead = new OcelotEntity(self::uuid(7), 7, 'world', new Position(2.0, 70.0, 0.0));
        $dead->damage(100.0);
        $foreign = new CatEntity(self::uuid(8), 8, 'other', new Position(2.0, 70.0, 0.0));
        $far = new OcelotEntity(self::uuid(9), 9, 'world', new Position(17.0, 70.0, 0.0));
        $world = new PhantomAiWorld(
            new AiPlayerSnapshot('target', 'world', new Position(10.0, 70.0, 0.0)),
            [$dead, $foreign, $far],
        );

        $phantom->tickAi(new AiTickContext(2, $world), true);

        self::assertGreaterThan(0.0, $phantom->getMotion()->x);
    }

    /** @return iterable<string, array{class-string<CatEntity|OcelotEntity>}> */
    public static function felineProvider(): iterable
    {
        yield 'cat' => [CatEntity::class];
        yield 'ocelot' => [OcelotEntity::class];
    }

    private static function uuid(int $suffix): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $suffix);
    }
}

final class PhantomAiWorld implements TargetAwareAiWorldView
{
    /** @param list<AbstractEntity> $entities */
    public function __construct(
        public ?AiPlayerSnapshot $target,
        public array $entities,
    ) {}

    public function nearbyEntities(AbstractMobEntity $entity, float $radius, int $limit): array
    {
        return array_slice(array_values(array_filter(
            $this->entities,
            static fn(AbstractEntity $candidate): bool => $candidate !== $entity
                && $candidate->getWorldName() === $entity->getWorldName()
                && $candidate->internalPosition()->distanceTo($entity->internalPosition()) ** 2 <= $radius ** 2,
        )), 0, $limit);
    }

    public function nearestPlayerDistanceSquared(AbstractMobEntity $entity): ?float
    {
        return $this->nearestPlayer($entity, 128.0)?->distanceSquaredTo($entity->internalPosition());
    }

    public function nearestPlayer(AbstractMobEntity $entity, float $radius): ?AiPlayerSnapshot
    {
        if ($this->target === null || !$this->target->damageable
            || $this->target->worldName !== $entity->getWorldName()
            || $this->target->distanceSquaredTo($entity->internalPosition()) > $radius ** 2) {
            return null;
        }

        return $this->target;
    }

    public function nearestPlayerHolding(
        AbstractMobEntity $entity,
        float $radius,
        array $itemIdentifiers,
    ): ?AiPlayerSnapshot {
        $target = $this->nearestPlayer($entity, $radius);

        return $target !== null && in_array($target->heldItemIdentifier, $itemIdentifiers, true)
            ? $target
            : null;
    }
}
