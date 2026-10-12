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

use Bedriox\Api\TranslatableMessage;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Entity\Vanilla\Misc\PrimedTntEntity;
use Bedriox\Server\Gameplay\Block\BlockCatalog;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerInventoryEntry;
use Bedriox\Server\Player\PlayerInventoryStackState;
use Bedriox\Server\Player\PlayerInventoryState;
use Bedriox\Server\Simulation\Event\BlockChanged;
use Bedriox\Server\Simulation\Event\EntityExplosionPresented;
use Bedriox\Server\Simulation\Event\PlayerDied;
use Bedriox\Server\Simulation\Event\TntPrimed;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\Collision\BlockCollisionRegistry;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class TntSimulationTest extends TestCase
{
    public function testFlintAndSteelPrimesTntAndCommitsTheAuthoritativeBlockChange(): void
    {
        [$simulation, $world, $states, $commands] = self::simulation();

        self::assertTrue($simulation->enqueue($commands->placeBlock(
            'one',
            1,
            new BlockPosition(2, 64, 0),
            1,
            0,
            0,
            0.5,
            0.5,
            0.5,
        )));
        $result = $simulation->tick();

        self::assertSame('minecraft:air', $states->state($world->blockStateAt(2, 64, 0))->identifier());
        self::assertCount(1, array_filter(
            $simulation->entityRuntime()->registry()->all(),
            static fn(object $entity): bool => $entity instanceof PrimedTntEntity,
        ));
        self::assertCount(1, array_filter(
            $result->events,
            static fn(object $event): bool => $event instanceof BlockChanged,
        ));
        self::assertCount(1, array_filter(
            $result->events,
            static fn(object $event): bool => $event instanceof TntPrimed,
        ));
        self::assertSame(
            1,
            $simulation->authoritativePlayer('identity-one')?->inventory->selectedStack()?->damage,
        );
    }

    public function testTntExplosionUsesBlockResistanceAndPrimesAdjacentTnt(): void
    {
        [$simulation, $world, $states, $commands, $tnt] = self::simulation();
        $world->setBlockState(3, 64, 0, $tnt);

        self::assertTrue($simulation->enqueue($commands->placeBlock(
            'one',
            1,
            new BlockPosition(2, 64, 0),
            1,
            0,
            0,
            0.5,
            0.5,
            0.5,
        )));
        $simulation->tick();

        $exploded = false;
        $deathMessage = null;
        for ($tick = 0; $tick < PrimedTntEntity::DEFAULT_FUSE_TICKS + 2; ++$tick) {
            foreach ($simulation->tick()->events as $event) {
                $exploded = $exploded || $event instanceof EntityExplosionPresented;
                if ($event instanceof PlayerDied) {
                    $deathMessage = $event->deathMessage;
                }
            }
        }

        self::assertTrue($exploded);
        self::assertInstanceOf(TranslatableMessage::class, $deathMessage);
        self::assertSame('death.attack.explosion', $deathMessage->key);
        self::assertSame(['One'], $deathMessage->parameters);
        self::assertSame('minecraft:air', $states->state($world->blockStateAt(3, 64, 0))->identifier());
        self::assertNotEmpty(array_filter(
            $simulation->entityRuntime()->registry()->all(),
            static fn(object $entity): bool => $entity instanceof PrimedTntEntity,
        ));
    }

    /**
     * @return array{WorldSimulation, World, BlockStateRegistry, SimulationCommandFactory, InternalBlockStateId}
     */
    private static function simulation(): array
    {
        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $flat = FixedFlatBlockPalette::fromRegistry($states);
        $generation = GenerationBlockPalette::fromRegistry($states);
        $world = new World(
            new WorldMetadata('tnt-test', 37),
            new FlatWorldGenerator($flat),
            new ChunkRepository(4),
        );
        $blocks = BlockCatalog::vanilla($states, $data->blockItemMappingRegistry());
        $tnt = $states->internalId($blocks->type('minecraft:tnt')->state);
        $world->chunk(new ChunkPosition(0, 0));
        $world->setBlockState(2, 64, 0, $tnt);
        $items = ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            $blocks,
            $data->creativeInventoryRegistry(),
            $data->blockItemMappingRegistry(),
        );
        $simulation = new WorldSimulation(
            blockWorld: $world,
            blockPalette: $flat,
            itemCatalog: $items,
            blockCatalog: $blocks,
            blockStateRegistry: $states,
            blockCollisionRegistry: BlockCollisionRegistry::forGenerationPalette($states, $generation),
            blockProperties: $data->blockPropertyRegistry(),
        );
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join(
            'one',
            'identity-one',
            'One',
            bootstrap: new PlayerBootstrap(
                new PlayerIdentity('identity-one', 'One'),
                'tnt-test',
                new Position(0.5, 64.0, 0.5),
                0.0,
                0.0,
                new PlayerInventoryState([
                    new PlayerInventoryEntry(
                        0,
                        new PlayerInventoryStackState('minecraft:flint_and_steel', 1),
                    ),
                ], 0),
                1,
                1,
            ),
        )));
        $simulation->tick();

        return [$simulation, $world, $states, $commands, $tnt];
    }
}
