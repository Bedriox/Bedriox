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
use Bedriox\Server\Gameplay\Block\BlockCatalog;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Simulation\Event\BlockEntityChanged;
use Bedriox\Server\Simulation\Event\BlockPlacementCorrected;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\BlockEntity\ShelfBlockEntity;
use Bedriox\Server\World\BlockEntity\SignBlockEntity;
use Bedriox\Server\World\BlockEntity\SignText;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class SignShelfSimulationTest extends TestCase
{
    public function testSignEditPreservesAuthoritativeAppearanceAndClearsOwnership(): void
    {
        [$simulation, $world, $states] = self::simulation();
        $position = new BlockPosition(1, 64, 0);
        $world->setBlockState($position->x, $position->y, $position->z, $states->internalId(
            CanonicalBlockState::from('minecraft:standing_sign', ['ground_sign_direction' => 0]),
        ));
        $factory = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($factory->join('player', 'identity', 'Player')));
        $simulation->tick();
        $runtimeActorId = $simulation->snapshot()->players[0]->runtimeActorId;
        $world->setBlockEntity(new SignBlockEntity(
            $position,
            SignText::fromBlob('old', 0xff336699, true),
            SignText::fromBlob('back'),
            editorSessionId: 'player',
            editorFront: true,
            editorRuntimeActorId: $runtimeActorId,
        ));

        self::assertTrue($simulation->enqueue($factory->updateSignText(
            'player',
            $position,
            SignText::fromBlob('new', 0xff000000, false),
            SignText::fromBlob('spoofed back', 0xffffffff, true),
        )));
        $event = $simulation->tick()->events[0];

        self::assertInstanceOf(BlockEntityChanged::class, $event);
        $stored = $world->blockEntityAt($position);
        self::assertInstanceOf(SignBlockEntity::class, $stored);
        self::assertSame('new', $stored->front->blob());
        self::assertSame(0xff336699, $stored->front->color);
        self::assertTrue($stored->front->glowing);
        self::assertSame('back', $stored->back->blob());
        self::assertNull($stored->editorSessionId);
        self::assertNull($stored->editorRuntimeActorId);
    }

    public function testShelfExchangeCommitsWorldAndHeldInventoryTogether(): void
    {
        [$simulation, $world, $states] = self::simulation();
        $position = new BlockPosition(1, 64, 0);
        $state = BedrockDataSet::bundled()->blockItemMappingRegistry()->mappingForItem('minecraft:oak_shelf');
        $world->setBlockState($position->x, $position->y, $position->z, $states->internalId($state->blockState()));
        $world->setBlockEntity(ShelfBlockEntity::empty($position));
        $factory = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($factory->join('player', 'identity', 'Player')));
        $simulation->tick();
        self::assertTrue($simulation->enqueue($factory->giveItem('player', 'minecraft:stone', 5)));
        $simulation->tick();

        self::assertTrue($simulation->enqueue($factory->placeBlock(
            'player',
            1,
            $position,
            1,
            0,
            0,
            0.5,
            0.5,
            0.5,
        )));
        $events = $simulation->tick()->events;

        self::assertInstanceOf(BlockPlacementCorrected::class, $events[0]);
        self::assertSame('shelf_exchanged', $events[0]->reason);
        $stored = $world->blockEntityAt($position);
        self::assertInstanceOf(ShelfBlockEntity::class, $stored);
        $stack = $stored->inventory->stackAt(1);
        self::assertNotNull($stack);
        self::assertSame('minecraft:stone', $stack->identifier);
        self::assertSame(5, $stack->count);
        self::assertNull($simulation->snapshot()->players[0]->selectedStack);
    }

    /** @return array{WorldSimulation, World, BlockStateRegistry} */
    private static function simulation(): array
    {
        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $blocks = BlockCatalog::vanilla($states, $data->blockItemMappingRegistry());
        $world = new World(
            new WorldMetadata('sign-shelf-simulation', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $world->retainChunk(new ChunkPosition(0, 0));

        return [
            new WorldSimulation(
                blockWorld: $world,
                blockPalette: $palette,
                itemCatalog: ItemCatalog::vanilla(
                    $data->itemNetworkRegistry(),
                    $blocks,
                    $data->creativeInventoryRegistry(),
                    $data->blockItemMappingRegistry(),
                ),
                blockCatalog: $blocks,
                blockStateRegistry: $states,
            ),
            $world,
            $states,
        ];
    }
}
