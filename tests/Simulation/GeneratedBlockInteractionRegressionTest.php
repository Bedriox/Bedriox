<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Simulation;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Gameplay\Block\BlockCatalog;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Simulation\BlockBreakAction;
use Bedriox\Server\Simulation\Event\BlockChanged;
use Bedriox\Server\Simulation\Event\PlayerMoved;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\BlockPosition;
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
}
