<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Simulation;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Simulation\BlockBreakAction;
use Bedriox\Server\Simulation\Event\ChatBroadcast;
use Bedriox\Server\Simulation\Event\EmotePerformed;
use Bedriox\Server\Simulation\Event\MovementCorrected;
use Bedriox\Server\Simulation\Event\PlayerDisconnected;
use Bedriox\Server\Simulation\Event\PlayerJoined;
use Bedriox\Server\Simulation\Event\PlayerMoved;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\SimulationLimits;
use Bedriox\Server\Simulation\VerticalState;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class MultiplayerLifecycleTest extends TestCase
{
    private const string ALPHA_EMOTE = '00112233-4455-6677-8899-aabbccddeeff';
    private const string BRAVO_EMOTE = '10213243-5465-7687-98a9-bacbdcedfe0f';

    public function testTwoPlayerLifecyclePublishesBidirectionalEventsInDeterministicOrder(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();
        self::assertTrue($world->enqueue($factory->join('alpha', 'identity-alpha', 'Alpha', 101)));
        self::assertTrue($world->enqueue($factory->join('bravo', 'identity-bravo', 'Bravo', 202)));

        $joins = $world->tick()->events;
        self::assertCount(2, $joins);
        self::assertInstanceOf(PlayerJoined::class, $joins[0]);
        self::assertSame('alpha', $joins[0]->player->sessionId);
        self::assertSame([], $joins[0]->existingPeers);
        self::assertSame(['alpha'], $joins[0]->recipients());
        self::assertInstanceOf(PlayerJoined::class, $joins[1]);
        self::assertSame('bravo', $joins[1]->player->sessionId);
        self::assertSame(['alpha'], array_map(
            static fn($player): string => $player->sessionId,
            $joins[1]->existingPeers,
        ));
        self::assertSame(['alpha', 'bravo'], $joins[1]->recipients());

        self::assertTrue($world->enqueue($factory->chat('alpha', 1, 'from alpha')));
        self::assertTrue($world->enqueue($factory->chat('bravo', 1, 'from bravo')));
        self::assertTrue($world->enqueue($factory->emote('alpha', self::ALPHA_EMOTE)));
        self::assertTrue($world->enqueue($factory->emote('bravo', self::BRAVO_EMOTE)));
        self::assertTrue($world->enqueue($factory->move(
            'bravo',
            1,
            0.0,
            64.0,
            1.0,
            20.0,
            0.0,
            MovementMode::WALKING,
            deltaZ: 1.0,
        )));
        self::assertTrue($world->enqueue($factory->move(
            'alpha',
            1,
            1.0,
            64.0,
            0.0,
            10.0,
            0.0,
            MovementMode::WALKING,
            deltaX: 1.0,
        )));

        $activity = $world->tick()->events;
        self::assertSame([
            ChatBroadcast::class,
            ChatBroadcast::class,
            EmotePerformed::class,
            EmotePerformed::class,
            PlayerMoved::class,
            PlayerMoved::class,
        ], array_map(static fn(object $event): string => $event::class, $activity));
        self::assertSame(['alpha', 'bravo'], $activity[0]->recipients());
        self::assertSame(['alpha', 'bravo'], $activity[1]->recipients());
        self::assertSame(['bravo'], $activity[2]->recipients());
        self::assertSame(['alpha'], $activity[3]->recipients());
        self::assertInstanceOf(PlayerMoved::class, $activity[4]);
        self::assertSame('bravo', $activity[4]->player->sessionId);
        self::assertSame(['alpha'], $activity[4]->recipients());
        self::assertInstanceOf(PlayerMoved::class, $activity[5]);
        self::assertSame('alpha', $activity[5]->player->sessionId);
        self::assertSame(['bravo'], $activity[5]->recipients());

        self::assertTrue($world->enqueue($factory->disconnect('bravo')));
        self::assertTrue($world->enqueue($factory->disconnect('alpha')));
        $disconnects = $world->tick()->events;
        self::assertCount(2, $disconnects);
        self::assertInstanceOf(PlayerDisconnected::class, $disconnects[0]);
        self::assertSame('bravo', $disconnects[0]->sessionId);
        self::assertSame(202, $disconnects[0]->runtimeActorId);
        self::assertSame(['alpha'], $disconnects[0]->recipients());
        self::assertInstanceOf(PlayerDisconnected::class, $disconnects[1]);
        self::assertSame('alpha', $disconnects[1]->sessionId);
        self::assertSame(101, $disconnects[1]->runtimeActorId);
        self::assertSame([], $disconnects[1]->recipients());
        self::assertSame([], $world->snapshot()->players);
    }

    public function testSameIdentitySessionAndActorCanReconnectRepeatedlyAfterAuthoritativeRemoval(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();

        for ($cycle = 0; $cycle < 50; ++$cycle) {
            $session = $cycle % 2 === 0 ? 'session-a' : 'session-b';
            self::assertTrue($world->enqueue($factory->join($session, 'stable-identity', 'Player', 77)));
            $joined = $world->tick()->events;
            self::assertCount(1, $joined);
            self::assertInstanceOf(PlayerJoined::class, $joined[0]);
            self::assertSame($session, $joined[0]->player->sessionId);
            self::assertSame(77, $joined[0]->player->runtimeActorId);

            self::assertTrue($world->enqueue($factory->disconnect($session)));
            $removed = $world->tick()->events;
            self::assertCount(1, $removed);
            self::assertInstanceOf(PlayerDisconnected::class, $removed[0]);
            self::assertSame('stable-identity', $removed[0]->identity);
            self::assertSame(77, $removed[0]->runtimeActorId);
            self::assertSame([], $world->snapshot()->players);
            self::assertSame(0, $world->queuedCommands());
            self::assertSame(0, $world->queuedBytes());
        }
    }

    public function testSimultaneousMovementIsCoalescedBoundedAndDrainedInFirstArrivalOrder(): void
    {
        $limits = new SimulationLimits(
            maximumPlayers: 8,
            maximumQueuedCommands: 8,
            maximumQueuedBytes: 1024,
            maximumCommandsPerTick: 8,
        );
        $factory = new SimulationCommandFactory($limits);
        $first = new WorldSimulation($limits);
        $second = new WorldSimulation($limits);
        $arrivalOrder = ['player-7', 'player-1', 'player-5', 'player-3', 'player-0', 'player-6', 'player-2', 'player-4'];

        for ($i = 0; $i < 8; ++$i) {
            $join = $factory->join("player-$i", "identity-$i", "Player$i", 1000 + $i);
            self::assertTrue($first->enqueue($join));
            self::assertTrue($second->enqueue($join));
        }
        self::assertEquals($first->tick(), $second->tick());

        foreach ([$first, $second] as $world) {
            foreach ($arrivalOrder as $session) {
                for ($sequence = 1; $sequence <= 100; ++$sequence) {
                    self::assertTrue($world->enqueue($factory->move(
                        $session,
                        $sequence,
                        1.0,
                        64.0,
                        0.0,
                        (float) $sequence,
                        0.0,
                        MovementMode::WALKING,
                        deltaX: 1.0,
                    )));
                }
            }
            self::assertSame(8, $world->queuedCommands());
            self::assertLessThanOrEqual(1024, $world->queuedBytes());
        }

        $firstTick = $first->tick();
        $secondTick = $second->tick();
        self::assertEquals($firstTick, $secondTick);
        self::assertSame(8, $firstTick->processedCommands);
        self::assertSame($arrivalOrder, array_map(
            static function (object $event): string {
                self::assertInstanceOf(PlayerMoved::class, $event);

                return $event->player->sessionId;
            },
            $firstTick->events,
        ));
        foreach ($firstTick->events as $event) {
            self::assertInstanceOf(PlayerMoved::class, $event);
            self::assertSame(100, $event->player->movementSequence);
            self::assertCount(7, $event->recipients());
        }
        self::assertSame(0, $first->queuedCommands());
        self::assertSame(0, $first->queuedBytes());
        self::assertEquals($first->snapshot(), $second->snapshot());
    }

    public function testTerrainCorrectionIsIsolatedAndPublishedToTheOtherPlayer(): void
    {
        [$blocks, $palette] = self::flatWorld('multiplayer-collision');
        $blocks->setBlockState(1, 64, 0, $palette->grassBlock);
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation(blockWorld: $blocks, blockPalette: $palette);
        $world->enqueue($factory->join('alpha', 'identity-alpha', 'Alpha'));
        $world->enqueue($factory->join('bravo', 'identity-bravo', 'Bravo'));
        $world->tick();

        $world->enqueue($factory->move(
            'alpha',
            1,
            2.0,
            64.0,
            1.0,
            0.0,
            0.0,
            MovementMode::WALKING,
        ));
        $event = $world->tick()->events[0];

        self::assertInstanceOf(MovementCorrected::class, $event);
        self::assertSame(['bravo'], $event->peerSessionIds);
        self::assertEqualsWithDelta(0.7, $event->authoritativePlayer->position->x, 0.000001);
        $players = $world->snapshot()->players;
        self::assertSame('alpha', $players[0]->sessionId);
        self::assertEqualsWithDelta(0.7, $players[0]->position->x, 0.000001);
        self::assertSame('bravo', $players[1]->sessionId);
        self::assertEquals(new Position(0.0, 64.0, 0.0), $players[1]->position);
    }

    public function testSharedSupportMutationRefreshesEveryPlayer(): void
    {
        [$blocks, $palette] = self::flatWorld('multiplayer-support');
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation(
            spawn: new Position(0.5, 64.0, 0.5),
            blockWorld: $blocks,
            blockPalette: $palette,
        );
        $world->enqueue($factory->join('alpha', 'identity-alpha', 'Alpha'));
        $world->enqueue($factory->join('bravo', 'identity-bravo', 'Bravo'));
        $world->tick();
        $support = new BlockPosition(0, 63, 0);
        $world->enqueue($factory->breakBlock('alpha', 1, BlockBreakAction::Start, $support, 1));
        $world->tick();
        $world->enqueue($factory->breakBlock('alpha', 2, BlockBreakAction::Complete, $support, 1));
        $world->tick();

        foreach ($world->snapshot()->players as $player) {
            self::assertSame(VerticalState::AIRBORNE, $player->verticalState);
        }
    }

    /** @return array{World, FixedFlatBlockPalette} */
    private static function flatWorld(string $name): array
    {
        $palette = FixedFlatBlockPalette::fromRegistry(new BlockStateRegistry(
            BedrockDataSet::bundled()->blockStateRegistry()->states(),
        ));

        return [
            new World(
                new WorldMetadata($name, 0),
                new FlatWorldGenerator($palette),
                new ChunkRepository(4),
            ),
            $palette,
        ];
    }
}
