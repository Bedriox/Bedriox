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

namespace Bedriox\Server\Tests\Simulation;

use Bedriox\Api\Entity\EntityDamageCause;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\Event\Entity\EntityDamageEvent;
use Bedriox\Api\Event\Player\PlayerDamageEvent;
use Bedriox\Api\Player\GameMode;
use Bedriox\Api\TranslatableMessage;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Entity\WorldEntityEnvironment;
use Bedriox\Server\Gameplay\Block\BlockCatalog;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerInventoryEntry;
use Bedriox\Server\Player\PlayerInventoryStackState;
use Bedriox\Server\Player\PlayerInventoryState;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\Simulation\DamageCause;
use Bedriox\Server\Simulation\Event\EntityActorDamaged;
use Bedriox\Server\Simulation\Event\PlayerDamaged;
use Bedriox\Server\Simulation\Event\PlayerDied;
use Bedriox\Server\Simulation\PluginGameplayEventBridge;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\Collision\BlockCollisionRegistry;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;
use Throwable;

final class SuffocationSimulationTest extends TestCase
{
    public function testEnvironmentRequiresAnOpaqueFullCubeIntersectingTheEyeVolume(): void
    {
        [$world, $states, $palette, $generation, $collisions, $data] = self::world();
        $environment = new WorldEntityEnvironment(
            $world,
            $states,
            $collisions,
            $palette->air,
            $generation->state('minecraft:water'),
            $data->blockPropertyRegistry(),
        );
        $position = new Position(0.5, 64.0, 0.5);

        $world->setBlockState(0, 65, 0, $generation->state('minecraft:stone'));
        self::assertTrue($environment->isSuffocatingAt($position, 1.62, 0.6));
        self::assertFalse($environment->isSuffocatingAt($position, 0.4, 0.6));

        $world->setBlockState(0, 65, 0, self::state($states, 'minecraft:glass'));
        self::assertFalse($environment->isSuffocatingAt($position, 1.62, 0.6));

        $world->setBlockState(0, 65, 0, self::state($states, 'minecraft:acacia_slab'));
        self::assertFalse($environment->isSuffocatingAt($position, 1.62, 0.6));
    }

    public function testPlayerTakesOneUnarmoredDamageEveryTenTicksWithoutArmorWear(): void
    {
        [$simulation, $commands, $world, $generation] = self::simulation();
        $bootstrap = self::bootstrap(health: 20.0, armor: [
            new PlayerInventoryEntry(0, new PlayerInventoryStackState('minecraft:iron_helmet', 1)),
        ]);
        $world->setBlockState(0, 65, 0, $generation->state('minecraft:stone'));
        self::assertTrue($simulation->enqueue($commands->join(
            'player',
            'identity',
            'Player',
            10,
            $bootstrap,
        )));
        $simulation->tick();

        $damageEvents = [];
        for ($tick = 2; $tick <= 20; ++$tick) {
            foreach ($simulation->tick()->events as $event) {
                if ($event instanceof PlayerDamaged && $event->cause === DamageCause::Suffocation) {
                    $damageEvents[] = $event;
                }
            }
        }

        self::assertCount(2, $damageEvents);
        self::assertSame(18.0, $simulation->snapshot()->players[0]->health);
        self::assertSame(0, $simulation->snapshot()->players[0]->armor[0]?->damage);
    }

    public function testCreativePlayerIsImmuneAndCancelledDamageDoesNotMutateHealth(): void
    {
        [$dispatcher, $bridge] = self::events();
        $causes = [];
        $dispatcher->register('Test', PlayerDamageEvent::class, static function (PlayerDamageEvent $event) use (&$causes): void {
            $causes[] = $event->cause;
            $event->cancel();
        });
        [$simulation, $commands, $world, $generation] = self::simulation($bridge);
        $world->setBlockState(0, 65, 0, $generation->state('minecraft:stone'));
        $simulation->enqueue($commands->join('survival', 'survival-id', 'Survival', 10));
        $simulation->enqueue($commands->join('creative', 'creative-id', 'Creative', 20));
        $simulation->tick();
        $simulation->enqueue($commands->changeGameMode('creative', GameMode::CREATIVE));
        $simulation->tick();

        for ($tick = 3; $tick <= 20; ++$tick) {
            $simulation->tick();
        }

        self::assertSame(['suffocation', 'suffocation'], $causes);
        self::assertSame(20.0, $simulation->snapshot()->players[0]->health);
        self::assertSame(20.0, $simulation->snapshot()->players[1]->health);
    }

    public function testLivingEntityDamageIsCancellableAndUsesSuffocationCause(): void
    {
        [$dispatcher, $bridge] = self::events();
        $causes = [];
        $dispatcher->register('Test', EntityDamageEvent::class, static function (EntityDamageEvent $event) use (&$causes): void {
            $causes[] = $event->cause;
            $event->cancel();
        });
        [$simulation, , $world, $generation] = self::simulation($bridge);
        $world->setBlockState(0, 65, 0, $generation->state('minecraft:stone'));
        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::COW,
            SpawnCause::COMMAND,
            'world',
            new Position(0.5, 64.0, 0.5),
        ));
        self::assertInstanceOf(CowEntity::class, $spawn->entity);

        for ($tick = 1; $tick <= 10; ++$tick) {
            self::assertCount(0, array_filter(
                $simulation->tick()->events,
                static fn(object $event): bool => $event instanceof EntityActorDamaged,
            ));
        }

        self::assertSame([EntityDamageCause::SUFFOCATION], $causes);
        self::assertSame(10.0, $spawn->entity->getHealth());
    }

    public function testFatalSuffocationUsesInWallDeathTranslation(): void
    {
        [$simulation, $commands, $world, $generation] = self::simulation();
        $world->setBlockState(0, 65, 0, $generation->state('minecraft:stone'));
        $simulation->enqueue($commands->join(
            'player',
            'identity',
            'Player',
            10,
            self::bootstrap(health: 1.0),
        ));
        $simulation->tick();

        $death = null;
        for ($tick = 2; $tick <= 10; ++$tick) {
            foreach ($simulation->tick()->events as $event) {
                if ($event instanceof PlayerDied) {
                    $death = $event;
                }
            }
        }

        self::assertInstanceOf(PlayerDied::class, $death);
        self::assertSame(DamageCause::Suffocation, $death->cause);
        self::assertInstanceOf(TranslatableMessage::class, $death->deathMessage);
        self::assertSame('death.attack.inWall', $death->deathMessage->key);
    }

    /**
     * @return array{
     *     WorldSimulation,
     *     SimulationCommandFactory,
     *     World,
     *     GenerationBlockPalette
     * }
     */
    private static function simulation(?PluginGameplayEventBridge $events = null): array
    {
        [$world, $states, $palette, $generation, $collisions, $data] = self::world();
        $blocks = BlockCatalog::vanilla($states, $data->blockItemMappingRegistry());
        $items = ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            $blocks,
            $data->creativeInventoryRegistry(),
            $data->blockItemMappingRegistry(),
        );

        return [
            new WorldSimulation(
                worldId: 'world',
                blockWorld: $world,
                blockPalette: $palette,
                itemCatalog: $items,
                blockCatalog: $blocks,
                blockStateRegistry: $states,
                blockCollisionRegistry: $collisions,
                blockProperties: $data->blockPropertyRegistry(),
                pluginEvents: $events,
                entityAiEnabled: false,
                spawnAnimals: false,
                spawnMonsters: false,
            ),
            new SimulationCommandFactory(),
            $world,
            $generation,
        ];
    }

    /**
     * @return array{
     *     World,
     *     BlockStateRegistry,
     *     FixedFlatBlockPalette,
     *     GenerationBlockPalette,
     *     BlockCollisionRegistry,
     *     BedrockDataSet
     * }
     */
    private static function world(): array
    {
        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $generation = GenerationBlockPalette::fromRegistry($states);
        $world = new World(
            new WorldMetadata('world', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $world->chunk(new ChunkPosition(0, 0));

        return [
            $world,
            $states,
            $palette,
            $generation,
            BlockCollisionRegistry::forGenerationPalette($states, $generation, $data->voxelShapeRegistry()),
            $data,
        ];
    }

    private static function state(BlockStateRegistry $states, string $identifier): \Bedriox\Server\World\Block\InternalBlockStateId
    {
        foreach ($states->states() as $state) {
            if ($state->identifier() === $identifier) {
                return $states->internalId($state);
            }
        }

        self::fail("Expected bundled block state $identifier.");
    }

    /** @param list<PlayerInventoryEntry> $armor */
    private static function bootstrap(float $health, array $armor = []): PlayerBootstrap
    {
        return new PlayerBootstrap(
            new PlayerIdentity('identity', 'Player'),
            'world',
            new Position(0.5, 64.0, 0.5),
            0.0,
            0.0,
            new PlayerInventoryState([], 0, armor: $armor),
            1,
            2,
            health: $health,
        );
    }

    /** @return array{EventDispatcher, PluginGameplayEventBridge} */
    private static function events(): array
    {
        $dispatcher = new EventDispatcher(
            new SuffocationPluginRuntimeControl(),
            new PluginExecutionContext(),
            new PluginActionBuffer(),
            new PluginOwnershipRegistry(),
        );

        return [$dispatcher, new PluginGameplayEventBridge($dispatcher)];
    }
}

final class SuffocationPluginRuntimeControl implements PluginRuntimeControl
{
    public function isEnabled(string $plugin): bool
    {
        return true;
    }

    public function version(string $plugin): string
    {
        return '1.0.0';
    }

    public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void {}
}
