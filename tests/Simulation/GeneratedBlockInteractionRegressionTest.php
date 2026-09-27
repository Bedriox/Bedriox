<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Simulation;

use Bedriox\Api\Player\GameMode;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Gameplay\Block\BlockCatalog;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Simulation\ArmSwingSource;
use Bedriox\Server\Simulation\BlockBreakAction;
use Bedriox\Server\Simulation\Event\ArmSwung;
use Bedriox\Server\Simulation\Event\BlockBreakStarted;
use Bedriox\Server\Simulation\Event\BlockChanged;
use Bedriox\Server\Simulation\Event\CommandRejected;
use Bedriox\Server\Simulation\Event\ItemEntitySpawned;
use Bedriox\Server\Simulation\Event\PlayerMoved;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\Collision\BlockCollisionRegistry;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class GeneratedBlockInteractionRegressionTest extends TestCase
{
    public function testGeneratedVegetationBreakUsesItsRegisteredGameplayDefinition(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $flat = FixedFlatBlockPalette::fromRegistry($states);
        $generation = GenerationBlockPalette::fromRegistry($states);
        $blocks = new World(
            new WorldMetadata('generated-interaction-regression', 0),
            new FlatWorldGenerator($flat),
            new ChunkRepository(4),
        );
        $position = new BlockPosition(1, 64, 0);
        $vegetation = $generation->state('minecraft:short_grass');
        $blocks->setBlockState($position->x, $position->y, $position->z, $vegetation);
        $catalog = BlockCatalog::vanilla($states);
        $simulation = new WorldSimulation(
            blockWorld: $blocks,
            blockPalette: $flat,
            itemCatalog: ItemCatalog::vanilla(BedrockDataSet::bundled()->itemNetworkRegistry(), $catalog),
            blockCatalog: $catalog,
            blockStateRegistry: $states,
        );
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('player', 'identity', 'Player')));
        $simulation->tick();

        self::assertTrue($simulation->enqueue($commands->breakBlock(
            'player',
            1,
            BlockBreakAction::Start,
            $position,
            1,
        )));
        $started = $simulation->tick()->events;
        self::assertNotEmpty($started);

        self::assertTrue($simulation->enqueue($commands->breakBlock(
            'player',
            2,
            BlockBreakAction::Complete,
            $position,
            1,
        )));
        $events = $simulation->tick()->events;
        $changed = array_values(array_filter($events, static fn($event): bool => $event instanceof BlockChanged));

        self::assertCount(1, $changed);
        self::assertSame($flat->air->value, $changed[0]->state->value);
        self::assertSame($flat->air->value, $blocks->blockStateAt($position->x, $position->y, $position->z)->value);
    }

    public function testTrulyUnknownGameplayDefinitionCorrectsPredictionWithoutCrashing(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $flat = FixedFlatBlockPalette::fromRegistry($states);
        $generation = GenerationBlockPalette::fromRegistry($states);
        $blocks = new World(
            new WorldMetadata('unknown-interaction-regression', 0),
            new FlatWorldGenerator($flat),
            new ChunkRepository(4),
        );
        $position = new BlockPosition(1, 64, 0);
        $vegetation = $generation->state('minecraft:short_grass');
        $blocks->setBlockState($position->x, $position->y, $position->z, $vegetation);
        $simulation = new WorldSimulation(
            blockWorld: $blocks,
            blockPalette: $flat,
            blockCatalog: BlockCatalog::vanilla(),
            blockStateRegistry: $states,
        );
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('player', 'identity', 'Player')));
        $simulation->tick();
        self::assertTrue($simulation->enqueue($commands->breakBlock(
            'player',
            1,
            BlockBreakAction::Start,
            $position,
            1,
        )));

        $event = $simulation->tick()->events[0];
        self::assertInstanceOf(BlockChanged::class, $event);
        self::assertSame($vegetation->value, $event->state->value);
        self::assertTrue($event->stopBreaking);
        self::assertSame($vegetation->value, $blocks->blockStateAt($position->x, $position->y, $position->z)->value);
    }

    public function testMappedCraftingTableCanBeBrokenAndDropsItsItem(): void
    {
        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $flat = FixedFlatBlockPalette::fromRegistry($states);
        $catalog = BlockCatalog::vanilla($states, $data->blockItemMappingRegistry());
        $items = ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            $catalog,
            $data->creativeInventoryRegistry(),
            $data->blockItemMappingRegistry(),
        );
        $position = new BlockPosition(1, 64, 0);
        $table = $states->internalId($data->blockItemMappingRegistry()
            ->mappingForItem('minecraft:crafting_table')->blockState());
        $blocks = new World(
            new WorldMetadata('mapped-crafting-table-regression', 0),
            new FlatWorldGenerator($flat),
            new ChunkRepository(4),
        );
        $blocks->setBlockState($position->x, $position->y, $position->z, $table);
        $simulation = new WorldSimulation(
            blockWorld: $blocks,
            blockPalette: $flat,
            itemCatalog: $items,
            blockCatalog: $catalog,
            blockStateRegistry: $states,
        );
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('player', 'identity', 'Player')));
        self::assertTrue($simulation->enqueue($commands->join('peer', 'peer-identity', 'Peer')));
        $simulation->tick();

        self::assertTrue($simulation->enqueue($commands->breakBlock(
            'player',
            1,
            BlockBreakAction::Start,
            $position,
            1,
        )));
        $started = $simulation->tick()->events;
        $swings = array_values(array_filter($started, static fn($event): bool => $event instanceof ArmSwung));
        self::assertCount(1, $swings);
        self::assertSame(ArmSwingSource::Mining, $swings[0]->source);
        self::assertSame(['peer'], $swings[0]->recipients());
        self::assertTrue($simulation->enqueue($commands->breakBlock(
            'player',
            2,
            BlockBreakAction::Complete,
            $position,
            1,
        )));
        $events = $simulation->tick()->events;
        $changed = array_values(array_filter($events, static fn($event): bool => $event instanceof BlockChanged));
        $drops = array_values(array_filter($events, static fn($event): bool => $event instanceof ItemEntitySpawned));

        self::assertCount(1, $changed);
        self::assertSame($flat->air->value, $changed[0]->state->value);
        self::assertSame($flat->air->value, $blocks->blockStateAt($position->x, $position->y, $position->z)->value);
        self::assertCount(1, $drops);
        self::assertSame('minecraft:crafting_table', $drops[0]->entity->stack->identifier);
        self::assertSame(1, $drops[0]->entity->stack->count);
    }

    public function testMappedBedrockRemainsProtectedInSurvivalButCanBeRemovedInCreative(): void
    {
        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $flat = FixedFlatBlockPalette::fromRegistry($states);
        $catalog = BlockCatalog::vanilla($states, $data->blockItemMappingRegistry());
        $position = new BlockPosition(1, 64, 0);
        $blocks = new World(
            new WorldMetadata('mapped-bedrock-regression', 0),
            new FlatWorldGenerator($flat),
            new ChunkRepository(4),
        );
        $blocks->setBlockState($position->x, $position->y, $position->z, $flat->bedrock);
        $simulation = new WorldSimulation(
            blockWorld: $blocks,
            blockPalette: $flat,
            blockCatalog: $catalog,
            blockStateRegistry: $states,
        );
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('player', 'identity', 'Player')));
        $simulation->tick();

        self::assertTrue($simulation->enqueue($commands->breakBlock(
            'player',
            1,
            BlockBreakAction::Start,
            $position,
            1,
        )));
        $survival = $simulation->tick()->events[0];
        self::assertInstanceOf(CommandRejected::class, $survival);
        self::assertSame('block_not_breakable', $survival->reason);

        self::assertTrue($simulation->enqueue($commands->changeGameMode('player', GameMode::CREATIVE)));
        $simulation->tick();
        self::assertTrue($simulation->enqueue($commands->breakBlock(
            'player',
            2,
            BlockBreakAction::Start,
            $position,
            1,
        )));
        self::assertInstanceOf(BlockBreakStarted::class, $simulation->tick()->events[0]);
        self::assertTrue($simulation->enqueue($commands->breakBlock(
            'player',
            3,
            BlockBreakAction::Complete,
            $position,
            1,
        )));
        $changed = $simulation->tick()->events[0];
        self::assertInstanceOf(BlockChanged::class, $changed);
        self::assertEquals($flat->air, $changed->state);
        self::assertSame($flat->air->value, $blocks->blockStateAt($position->x, $position->y, $position->z)->value);
    }

    public function testAuthoritativeMovementPassesThroughGeneratedVegetation(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $flat = FixedFlatBlockPalette::fromRegistry($states);
        $generation = GenerationBlockPalette::fromRegistry($states);
        $blocks = new World(
            new WorldMetadata('generated-movement-regression', 0),
            new FlatWorldGenerator($flat),
            new ChunkRepository(4),
        );
        $blocks->setBlockState(1, 64, 0, $generation->state('minecraft:short_grass'));
        self::retainOriginCollisionTerrain($blocks);
        $simulation = new WorldSimulation(
            blockWorld: $blocks,
            blockPalette: $flat,
            blockCollisionRegistry: BlockCollisionRegistry::forGenerationPalette($states, $generation),
        );
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('player', 'identity', 'Player')));
        $simulation->tick();
        self::assertTrue($simulation->enqueue($commands->move(
            'player',
            1,
            1.0,
            64.0,
            0.0,
            0.0,
            0.0,
            MovementMode::WALKING,
            deltaX: 1.0,
        )));

        $event = $simulation->tick()->events[0];
        self::assertInstanceOf(PlayerMoved::class, $event);
        self::assertSame(1.0, $event->player->position->x);
    }

    private static function retainOriginCollisionTerrain(World $world): void
    {
        foreach ([-1, 0] as $chunkX) {
            foreach ([-1, 0] as $chunkZ) {
                $world->retainChunk(new ChunkPosition($chunkX, $chunkZ));
            }
        }
    }
}
