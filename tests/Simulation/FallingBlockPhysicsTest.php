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

use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Entity\Block\FallingBlockEntity;
use Bedriox\Server\Gameplay\Block\BlockCatalog;
use Bedriox\Server\Simulation\Event\FallingBlockActorMoved;
use Bedriox\Server\Simulation\Event\FallingBlockActorRemoved;
use Bedriox\Server\Simulation\Event\FallingBlockActorSettled;
use Bedriox\Server\Simulation\Event\FallingBlockActorSpawned;
use Bedriox\Server\Simulation\Event\PlayerDamaged;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\Block\VanillaBlockStates;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\Collision\BlockCollisionRegistry;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class FallingBlockPhysicsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function ordinaryFallingBlocks(): iterable
    {
        yield 'sand' => ['minecraft:sand'];
        yield 'gravel' => ['minecraft:gravel'];
        yield 'red sand' => ['minecraft:red_sand'];
    }

    #[DataProvider('ordinaryFallingBlocks')]
    public function testUnsupportedBlockFallsAndSettlesOnTheSurface(string $identifier): void
    {
        [$simulation, $world, $states, $palette, $stone] = self::fixture();
        $position = new BlockPosition(2, 67, 2);
        $support = new BlockPosition(2, 66, 2);
        $falling = $states->internalId(CanonicalBlockState::from($identifier));
        $world->setBlockState($support->x, $support->y, $support->z, $stone);
        $world->setBlockState($position->x, $position->y, $position->z, $falling);

        (new ReflectionMethod(WorldSimulation::class, 'setBlockStateAndSchedule'))->invoke(
            $simulation,
            $support,
            $palette->air,
        );

        $spawned = null;
        $spawnTick = $settledTick = $removedTick = null;
        $moved = false;
        for ($tick = 0; $tick < 80; ++$tick) {
            foreach ($simulation->tick()->events as $event) {
                if ($event instanceof FallingBlockActorSpawned) {
                    $spawned = $event->entity;
                    $spawnTick ??= $tick;
                    self::assertEquals($position, $event->sourcePosition);
                    self::assertSame($palette->air->value, $event->replacementState?->value);
                } elseif ($event instanceof FallingBlockActorMoved) {
                    $moved = true;
                } elseif ($event instanceof FallingBlockActorSettled) {
                    $settledTick = $tick;
                } elseif ($event instanceof FallingBlockActorRemoved) {
                    $removedTick = $tick;
                }
            }
        }

        self::assertInstanceOf(FallingBlockEntity::class, $spawned);
        self::assertSame('minecraft:air', $states->state($world->blockStateAt(2, 67, 2))->identifier());
        self::assertSame($identifier, $states->state($world->blockStateAt(2, 64, 2))->identifier());
        self::assertTrue($spawned->isRemoved());
        self::assertTrue($moved);
        self::assertIsInt($spawnTick);
        self::assertIsInt($settledTick);
        self::assertIsInt($removedTick);
        self::assertGreaterThan($spawnTick, $settledTick);
        self::assertSame(3, $removedTick - $settledTick);
    }

    public function testStackedSandCascadesFromTheBottomUp(): void
    {
        [$simulation, $world, $states, $palette, $stone] = self::fixture();
        $support = new BlockPosition(3, 65, 3);
        $sand = $states->internalId(CanonicalBlockState::from('minecraft:sand'));
        $world->setBlockState(3, 65, 3, $stone);
        $world->setBlockState(3, 66, 3, $sand);
        $world->setBlockState(3, 67, 3, $sand);

        (new ReflectionMethod(WorldSimulation::class, 'setBlockStateAndSchedule'))->invoke(
            $simulation,
            $support,
            $palette->air,
        );
        for ($tick = 0; $tick < 100; ++$tick) {
            $simulation->tick();
        }

        self::assertSame('minecraft:sand', $states->state($world->blockStateAt(3, 64, 3))->identifier());
        self::assertSame('minecraft:sand', $states->state($world->blockStateAt(3, 65, 3))->identifier());
        self::assertSame('minecraft:air', $states->state($world->blockStateAt(3, 66, 3))->identifier());
        self::assertSame('minecraft:air', $states->state($world->blockStateAt(3, 67, 3))->identifier());
    }

    public function testConcretePowderHardensWhenItTouchesWater(): void
    {
        [$simulation, $world, $states, $palette] = self::fixture();
        $position = new BlockPosition(5, 65, 5);
        $powder = $states->internalId(CanonicalBlockState::from('minecraft:white_concrete_powder'));
        $water = $states->internalId(VanillaBlockStates::water());
        $world->setBlockState(6, 65, 5, $water);

        (new ReflectionMethod(WorldSimulation::class, 'setBlockStateAndSchedule'))->invoke(
            $simulation,
            $position,
            $powder,
        );
        $simulation->tick();

        self::assertSame('minecraft:white_concrete', $states->state($world->blockStateAt(5, 65, 5))->identifier());
        self::assertSame($palette->air->value, $world->blockStateAt(5, 66, 5)->value);
    }

    public function testAnvilAppliesBoundedLandingDamage(): void
    {
        [$simulation, $world, $states] = self::fixture();
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('player', 'identity', 'Player')));
        $simulation->tick();
        $position = new BlockPosition(0, 72, 0);
        $anvil = $states->internalId(CanonicalBlockState::from(
            'minecraft:anvil',
            ['minecraft:cardinal_direction' => 'west'],
        ));
        (new ReflectionMethod(WorldSimulation::class, 'setBlockStateAndSchedule'))->invoke(
            $simulation,
            $position,
            $anvil,
        );

        $damageEvents = [];
        for ($tick = 0; $tick < 100; ++$tick) {
            foreach ($simulation->tick()->events as $event) {
                if ($event instanceof PlayerDamaged) {
                    $damageEvents[] = $event;
                }
            }
        }

        $player = $simulation->authoritativePlayer('identity');
        self::assertNotNull($player);
        self::assertNotEmpty($damageEvents);
        self::assertLessThan(20.0, $player->vitals->health);
        self::assertGreaterThanOrEqual(0.0, $player->vitals->health);
        self::assertSame(
            'west',
            $states->state($world->blockStateAt(0, 64, 0))
                ->properties()['minecraft:cardinal_direction'] ?? null,
        );
    }

    /** @return array{WorldSimulation, World, BlockStateRegistry, FixedFlatBlockPalette, InternalBlockStateId} */
    private static function fixture(): array
    {
        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $flat = FixedFlatBlockPalette::fromRegistry($states);
        $generation = GenerationBlockPalette::fromRegistry($states);
        $world = new World(
            new WorldMetadata('falling-block-test', 91),
            new FlatWorldGenerator($flat),
            new ChunkRepository(8),
        );
        $blocks = BlockCatalog::vanilla($states, $data->blockItemMappingRegistry());
        $simulation = new WorldSimulation(
            blockWorld: $world,
            blockPalette: $flat,
            blockCatalog: $blocks,
            blockStateRegistry: $states,
            blockCollisionRegistry: BlockCollisionRegistry::forGenerationPalette($states, $generation),
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
        );

        return [
            $simulation,
            $world,
            $states,
            $flat,
            $states->internalId(CanonicalBlockState::from('minecraft:stone')),
        ];
    }
}
