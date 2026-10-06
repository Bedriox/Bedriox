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

namespace Bedriox\Server\Tests\Gameplay\End;

use Bedriox\Api\World\BlockFace;
use Bedriox\Server\Entity\Persistence\TransientEntityPersistenceStore;
use Bedriox\Server\Entity\Spawn\Structure\EndCityShulkerPopulationState;
use Bedriox\Server\Entity\Vanilla\End\ShulkerAttachmentResolver;
use Bedriox\Server\Entity\Vanilla\End\ShulkerEntity;
use Bedriox\Server\Gameplay\End\EndGatewayRuntime;
use Bedriox\Server\Gameplay\End\EndGatewayStateRepository;
use Bedriox\Server\Gameplay\Projectile\ProjectileRegistry;
use Bedriox\Server\Gameplay\Projectile\ProjectileType;
use Bedriox\Server\Gameplay\Projectile\ShulkerBulletGuidance;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class EndGatewayAndShulkerTest extends TestCase
{
    public function testGatewayActivationPreservesAnAcceptedCustomInnerPosition(): void
    {
        $runtime = new EndGatewayRuntime();
        $inner = new \Bedriox\Server\World\BlockPosition(12, 82, -9);

        $link = $runtime->activateAt(4, $inner);

        self::assertSame($inner, $link->inner);
        self::assertSame($inner, $runtime->links()[0]->inner);
        self::assertNotSame($inner, $link->outer);
    }

    public function testEndBossBarIsRepublishedOnlyToAReconnectedViewer(): void
    {
        $states = new \Bedriox\Server\World\Block\BlockStateRegistry(
            \Bedriox\Data\BedrockDataSet::bundled()->blockStateRegistry()->states(),
        );
        $palette = \Bedriox\Server\World\Block\FixedFlatBlockPalette::fromRegistry($states);
        $world = new \Bedriox\Server\World\World(
            new \Bedriox\Server\World\WorldMetadata('end', 42),
            new \Bedriox\Server\World\EndWorldGenerator(42, $states),
            new \Bedriox\Server\World\ChunkRepository(8),
            dimension: \Bedriox\Api\World\WorldDimension::END,
        );
        $world->retainChunk(new \Bedriox\Server\World\ChunkPosition(0, 0));
        $simulation = new \Bedriox\Server\Simulation\WorldSimulation(
            spawn: new Position(0.5, 76.0, 0.5),
            blockWorld: $world,
            blockPalette: $palette,
            blockStateRegistry: $states,
            entityAiEnabled: false,
            worldId: 'end',
            dimension: \Bedriox\Api\World\WorldDimension::END,
        );
        $commands = new \Bedriox\Server\Simulation\SimulationCommandFactory();
        $simulation->enqueue($commands->join('first', 'identity-one', 'One'));
        $firstTick = $simulation->tick();
        $firstCreates = array_values(array_filter(
            $firstTick->events,
            static fn($event): bool => $event instanceof \Bedriox\Server\Simulation\Event\EnderDragonBossBarChanged
                && $event->action === \Bedriox\Server\Simulation\Event\EnderDragonBossBarAction::CREATE,
        ));
        self::assertCount(1, $firstCreates);
        self::assertSame(['first'], $firstCreates[0]->recipientSessionIds);
        $dragonSpawnIndex = null;
        $bossCreateIndex = null;
        foreach ($firstTick->events as $index => $event) {
            if ($event instanceof \Bedriox\Server\Simulation\Event\EntityActorSpawned
                && $event->entity instanceof \Bedriox\Server\Entity\Vanilla\End\EnderDragonEntity) {
                $dragonSpawnIndex = $index;
            }
            if ($event instanceof \Bedriox\Server\Simulation\Event\EnderDragonBossBarChanged
                && $event->action === \Bedriox\Server\Simulation\Event\EnderDragonBossBarAction::CREATE) {
                $bossCreateIndex = $index;
            }
        }
        self::assertNotNull($dragonSpawnIndex);
        self::assertNotNull($bossCreateIndex);
        self::assertLessThan($bossCreateIndex, $dragonSpawnIndex);

        $simulation->enqueue($commands->disconnect('first'));
        $simulation->tick();
        $simulation->enqueue($commands->join('second', 'identity-one', 'One'));
        $reconnected = $simulation->tick();
        $reconnectCreates = array_values(array_filter(
            $reconnected->events,
            static fn($event): bool => $event instanceof \Bedriox\Server\Simulation\Event\EnderDragonBossBarChanged
                && $event->action === \Bedriox\Server\Simulation\Event\EnderDragonBossBarAction::CREATE,
        ));
        self::assertCount(1, $reconnectCreates);
        self::assertSame(['second'], $reconnectCreates[0]->recipientSessionIds);
        $dragonSpawnIndex = null;
        $bossCreateIndex = null;
        foreach ($reconnected->events as $index => $event) {
            if ($event instanceof \Bedriox\Server\Simulation\Event\EntityActorSpawned
                && $event->entity instanceof \Bedriox\Server\Entity\Vanilla\End\EnderDragonEntity) {
                $dragonSpawnIndex = $index;
            }
            if ($event instanceof \Bedriox\Server\Simulation\Event\EnderDragonBossBarChanged
                && $event->action === \Bedriox\Server\Simulation\Event\EnderDragonBossBarAction::CREATE) {
                $bossCreateIndex = $index;
            }
        }
        self::assertNotNull($dragonSpawnIndex);
        self::assertNotNull($bossCreateIndex);
        self::assertLessThan($bossCreateIndex, $dragonSpawnIndex);
    }

    public function testEndProjectilesApplyTheirWorldRuntimeImpactEffects(): void
    {
        $states = new \Bedriox\Server\World\Block\BlockStateRegistry(
            \Bedriox\Data\BedrockDataSet::bundled()->blockStateRegistry()->states(),
        );
        $palette = \Bedriox\Server\World\Block\FixedFlatBlockPalette::fromRegistry($states);
        $world = new \Bedriox\Server\World\World(
            new \Bedriox\Server\World\WorldMetadata('end-projectiles', 42),
            new \Bedriox\Server\World\EndWorldGenerator(42, $states),
            new \Bedriox\Server\World\ChunkRepository(8),
            dimension: \Bedriox\Api\World\WorldDimension::END,
        );
        $world->retainChunk(new \Bedriox\Server\World\ChunkPosition(0, 0));
        $simulation = new \Bedriox\Server\Simulation\WorldSimulation(
            spawn: new Position(0.5, 76.0, 0.5),
            blockWorld: $world,
            blockPalette: $palette,
            blockStateRegistry: $states,
            entityAiEnabled: false,
            worldId: 'end-projectiles',
            dimension: \Bedriox\Api\World\WorldDimension::END,
        );
        $commands = new \Bedriox\Server\Simulation\SimulationCommandFactory();
        $simulation->enqueue($commands->join('viewer', 'identity-one', 'One'));
        $simulation->tick();

        $projectileProperty = new \ReflectionProperty($simulation, 'projectiles');
        /** @var ProjectileRegistry $projectiles */
        $projectiles = $projectileProperty->getValue($simulation);
        $bullet = $projectiles->spawnShulkerBullet(
            self::uuid(9),
            9,
            new Position(0.5, 76.8, -0.2),
            new Position(0.5, 76.8, 0.5),
        );
        $targetProperty = new \ReflectionProperty($simulation, 'shulkerBulletTargets');
        $targetProperty->setValue($simulation, [$bullet->runtimeEntityId => 'identity-one']);

        $events = [];
        for ($tick = 0; $tick < 8; ++$tick) {
            array_push($events, ...$simulation->tick()->events);
        }

        self::assertNotEmpty(array_filter(
            $events,
            static fn($event): bool => $event instanceof \Bedriox\Server\Simulation\Event\PlayerDamaged,
        ));
        self::assertNotEmpty(array_filter(
            $events,
            static fn($event): bool => $event instanceof \Bedriox\Server\Simulation\Event\PlayerEffectChanged,
        ));
        self::assertNull($projectiles->get($bullet->runtimeEntityId));

        $fireball = $projectiles->spawnDragonFireball(
            self::uuid(10),
            10,
            new Position(0.5, 76.8, -2.0),
            new Position(0.5, 76.8, 0.5),
        );
        $events = [];
        for ($tick = 0; $tick < 8; ++$tick) {
            array_push($events, ...$simulation->tick()->events);
        }
        self::assertNotEmpty(array_filter(
            $events,
            static fn($event): bool => $event instanceof \Bedriox\Server\Simulation\Event\AreaEffectCloudSpawned,
        ));
        self::assertNotEmpty(array_filter(
            $events,
            static fn($event): bool => $event instanceof \Bedriox\Server\Simulation\Event\ParticleSpawned,
        ));
        self::assertNull($projectiles->get($fireball->runtimeEntityId));
    }

    public function testGatewayLinksPersistAndTransferInBothDirectionsWithCooldown(): void
    {
        $store = new class implements TransientEntityPersistenceStore {
            /** @var array<string, string> */
            public array $values = [];
            public function loadTransientEntities(string $namespace): ?string
            {
                return $this->values[$namespace] ?? null;
            }
            public function saveTransientEntities(string $namespace, ?string $payload): void
            {
                if ($payload === null) {
                    unset($this->values[$namespace]);
                } else {
                    $this->values[$namespace] = $payload;
                }
            }
        };
        $runtime = new EndGatewayRuntime(new EndGatewayStateRepository($store));
        $link = $runtime->activate(3);
        $outbound = $runtime->contact('player:one', $link->inner, 100);
        self::assertNotNull($outbound);
        self::assertEqualsWithDelta(2.0, hypot(
            $outbound->destination->x - ($link->outer->x + 0.5),
            $outbound->destination->z - ($link->outer->z + 0.5),
        ), 0.000001);
        self::assertSame($link->outer->y + 1.0, $outbound->destination->y);
        self::assertNull($runtime->contact('player:one', $link->inner, 101));
        $return = $runtime->contact('player:one', $link->outer, $outbound->cooldownUntilTick);
        self::assertNotNull($return);
        self::assertEqualsWithDelta(2.0, hypot(
            $return->destination->x - ($link->inner->x + 0.5),
            $return->destination->z - ($link->inner->z + 0.5),
        ), 0.000001);

        $restored = new EndGatewayRuntime(new EndGatewayStateRepository($store));
        self::assertEquals([$link], $restored->links());

        $entityTransfer = $restored->contactVolume(
            'entity:test',
            new Position($link->inner->x + 0.5, (float) $link->inner->y, $link->inner->z + 0.5),
            0.6,
            1.8,
            200,
        );
        self::assertNotNull($entityTransfer);
        self::assertEqualsWithDelta(2.0, hypot(
            $entityTransfer->destination->x - ($link->outer->x + 0.5),
            $entityTransfer->destination->z - ($link->outer->z + 0.5),
        ), 0.000001);
    }

    public function testShulkerStatePersistsAndBulletGuidanceIsBounded(): void
    {
        $shulker = new ShulkerEntity(self::uuid(1), 1, 'end', new Position(0.5, 70.0, 0.5));
        $shulker->setAttachmentFace(BlockFace::NORTH);
        $shulker->setPeekAmount(100);
        $copy = new ShulkerEntity(self::uuid(2), 2, 'end', new Position(0.5, 70.0, 0.5));
        $copy->restorePersistenceState(null, $shulker->persistenceSchemaVersion(), $shulker->persistenceData());
        self::assertSame(BlockFace::NORTH, $copy->getAttachmentFace());
        self::assertSame(100, $copy->getPeekAmount());
        self::assertFalse($copy->isGravityEnabled());

        $registry = new ProjectileRegistry(firstEntityId: 8_000);
        $bullet = $registry->spawnShulkerBullet(
            $shulker->getUniqueId(),
            $shulker->getRuntimeId(),
            new Position(0.5, 70.5, 0.5),
            new Position(5.5, 72.0, 5.5),
        );
        self::assertSame(ProjectileType::SHULKER_BULLET, $bullet->type);
        self::assertEqualsWithDelta(0.30, hypot(hypot($bullet->motion->x, $bullet->motion->z), $bullet->motion->y), 0.000001);
        $steered = ShulkerBulletGuidance::steer($bullet, new Position(-5.0, 75.0, 2.0));
        self::assertLessThan($bullet->motion->x, $steered->motion->x);
    }

    public function testEndCityPopulationClaimsAreDurableAndStrictlyBounded(): void
    {
        $state = new EndCityShulkerPopulationState();
        $claim = 'end_city:2:-3:70:-94:0';
        $state->claim($claim);
        self::assertTrue($state->contains($claim));
        self::assertTrue(EndCityShulkerPopulationState::decode($state->encode())->contains($claim));
    }

    public function testShulkerAttachmentRetainsSupportedFaceAndRelocatesWhenSupportBreaks(): void
    {
        $resolver = new ShulkerAttachmentResolver();
        $solid = static fn(int $x, int $y, int $z): bool => $x === -1 && $y === 68 && $z === -1;
        $clear = static fn(int $x, int $y, int $z): bool => $x === -1 && $y === 69 && $z === -1;

        $relocated = $resolver->resolve(new Position(0.5, 70.0, 0.5), BlockFace::DOWN, $clear, $solid);
        self::assertNotNull($relocated);
        self::assertEquals(new Position(-0.5, 69.0, -0.5), $relocated->position);
        self::assertSame(BlockFace::DOWN, $relocated->face);

        $retained = $resolver->resolve(
            new Position(2.5, 70.0, 2.5),
            BlockFace::NORTH,
            static fn(): bool => true,
            static fn(int $x, int $y, int $z): bool => $x === 2 && $y === 70 && $z === 1,
        );
        self::assertNotNull($retained);
        self::assertSame(BlockFace::NORTH, $retained->face);
        self::assertEquals(new Position(2.5, 70.0, 2.5), $retained->position);
    }

    private static function uuid(int $suffix): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $suffix);
    }
}
