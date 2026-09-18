<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Simulation;

use Bedriox\Server\Simulation\Event\ChatBroadcast;
use Bedriox\Server\Simulation\Event\CommandRejected;
use Bedriox\Server\Simulation\Event\EmotePerformed;
use Bedriox\Server\Simulation\Event\PlayerMoved;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use PHPUnit\Framework\TestCase;

final class EmoteSimulationTest extends TestCase
{
    private const string EMOTE_ID = '00112233-4455-6677-8899-aabbccddeeff';

    public function testEmotesArePeerOnlyAndLimitedToOnePerFiveTicks(): void
    {
        $commands = new SimulationCommandFactory();
        $world = new WorldSimulation();
        self::assertTrue($world->enqueue($commands->join('sender', 'sender-identity', 'Sender')));
        self::assertTrue($world->enqueue($commands->join('peer', 'peer-identity', 'Peer')));
        $world->tick();

        self::assertTrue($world->enqueue($commands->emote('sender', self::EMOTE_ID)));
        $accepted = $world->tick()->events;
        self::assertCount(1, $accepted);
        self::assertInstanceOf(EmotePerformed::class, $accepted[0]);
        self::assertSame(['peer'], $accepted[0]->recipients());

        self::assertTrue($world->enqueue($commands->emote('sender', self::EMOTE_ID)));
        self::assertTrue($world->enqueue($commands->chat('sender', 1, 'still works')));
        self::assertTrue($world->enqueue($commands->move(
            'sender',
            1,
            1.0,
            64.0,
            0.0,
            0.0,
            0.0,
            MovementMode::WALKING,
            deltaX: 1.0,
        )));
        $mixed = $world->tick()->events;
        self::assertCount(3, $mixed);
        self::assertInstanceOf(CommandRejected::class, $mixed[0]);
        self::assertSame('emote_rate', $mixed[0]->reason);
        self::assertInstanceOf(ChatBroadcast::class, $mixed[1]);
        self::assertInstanceOf(PlayerMoved::class, $mixed[2]);

        $world->tick();
        $world->tick();
        $world->tick();
        self::assertTrue($world->enqueue($commands->emote('sender', self::EMOTE_ID)));
        $afterCooldown = $world->tick()->events;
        self::assertCount(1, $afterCooldown);
        self::assertInstanceOf(EmotePerformed::class, $afterCooldown[0]);
    }
}
