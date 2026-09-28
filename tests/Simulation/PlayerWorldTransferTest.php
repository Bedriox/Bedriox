<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Simulation;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Simulation\Event\PlayerDisconnected;
use Bedriox\Server\Simulation\Event\PlayerJoined;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\VerticalState;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class PlayerWorldTransferTest extends TestCase
{
    public function testTransferMovesExactAggregateAndPublishesWorldLocalVisibility(): void
    {
        $factory = new SimulationCommandFactory();
        $source = self::world('survival');
        $destination = self::world('arena');
        self::join($source, $factory, 'alpha', 'identity-alpha', 101);
        self::join($source, $factory, 'bravo', 'identity-bravo', 202);
        self::join($destination, $factory, 'charlie', 'identity-charlie', 303);

        self::assertTrue($source->enqueue($factory->move(
            'alpha',
            1,
            4.0,
            64.0,
            4.0,
            20.0,
            5.0,
            MovementMode::WALKING,
            deltaX: 4.0,
            deltaZ: 4.0,
        )));
        self::assertSame(1, $source->queuedCommands());
        $departure = $source->detachPlayerForTransfer('alpha');

        self::assertNotNull($departure);
        self::assertSame(0, $source->queuedCommands());
        self::assertSame(['bravo'], array_map(
            static fn($player): string => $player->sessionId,
            $source->snapshot()->players,
        ));
        self::assertSame(['bravo'], array_map(
            static fn($player): string => $player->sessionId,
            $departure->previousPeers,
        ));
        self::assertNotEmpty($departure->events);
        $departureVisibility = $departure->events[count($departure->events) - 1];
        self::assertInstanceOf(PlayerDisconnected::class, $departureVisibility);
        self::assertSame(['bravo'], $departureVisibility->recipients());

        $arrival = $destination->attachTransferredPlayer(
            $departure->player,
            'arena',
            new Position(8.5, 64.0, -3.5),
            135.0,
            -30.0,
        );

        self::assertSame($departure->player, $arrival->player);
        self::assertSame('arena', $arrival->player->worldName());
        self::assertEquals(new Position(8.5, 64.0, -3.5), $arrival->player->movement->position);
        self::assertSame(135.0, $arrival->player->movement->yaw);
        self::assertSame(135.0, $arrival->player->movement->headYaw);
        self::assertSame(-30.0, $arrival->player->movement->pitch);
        self::assertSame(MovementMode::STOPPED, $arrival->player->movement->mode);
        self::assertSame(VerticalState::GROUNDED, $arrival->player->movement->verticalState);
        self::assertSame(0.0, $arrival->player->movement->fallDistance);
        self::assertSame(0.0, $arrival->player->movement->verticalVelocity);

        $arrivalVisibility = $arrival->events[0];
        self::assertInstanceOf(PlayerJoined::class, $arrivalVisibility);
        self::assertSame(['charlie'], array_map(
            static fn($player): string => $player->sessionId,
            $arrivalVisibility->existingPeers,
        ));
        self::assertSame(['alpha', 'charlie'], $arrivalVisibility->recipients());
        self::assertSame(['alpha', 'charlie'], array_map(
            static fn($player): string => $player->sessionId,
            $destination->snapshot()->players,
        ));
    }

    public function testTransferRejectsClosingSourceAndOccupiedDestinationWithoutMutation(): void
    {
        $factory = new SimulationCommandFactory();
        $source = self::world('survival');
        $destination = self::world('arena');
        self::join($source, $factory, 'alpha', 'identity-alpha', 101);
        self::join($destination, $factory, 'other', 'identity-alpha', 202);

        $player = $source->detachPlayerForTransfer('alpha');
        self::assertNotNull($player);
        self::assertFalse($destination->canAcceptTransferredPlayer($player->player));

        self::assertTrue($source->enqueue($factory->join('alpha', 'identity-alpha', 'Alpha', 101)));
        $source->tick();
        self::assertTrue($source->enqueue($factory->disconnect('alpha')));
        self::assertNull($source->detachPlayerForTransfer('alpha'));
    }

    private static function join(
        WorldSimulation $simulation,
        SimulationCommandFactory $factory,
        string $session,
        string $identity,
        int $runtimeActorId,
    ): void {
        self::assertTrue($simulation->enqueue($factory->join(
            $session,
            $identity,
            ucfirst($session),
            $runtimeActorId,
        )));
        self::assertInstanceOf(PlayerJoined::class, $simulation->tick()->events[0]);
    }

    private static function world(string $name): WorldSimulation
    {
        $palette = FixedFlatBlockPalette::fromRegistry(new BlockStateRegistry(
            BedrockDataSet::bundled()->blockStateRegistry()->states(),
        ));
        $world = new World(
            new WorldMetadata($name, 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        foreach ([-1, 0] as $chunkX) {
            foreach ([-1, 0] as $chunkZ) {
                $world->retainChunk(new ChunkPosition($chunkX, $chunkZ));
            }
        }

        return new WorldSimulation(blockWorld: $world, blockPalette: $palette);
    }
}
