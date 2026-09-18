<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Player;

use Bedriox\Server\Player\Player;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class PlayerTest extends TestCase
{
    public function testSnapshotSeparatesMutableWorldStateFromConsumers(): void
    {
        $player = new Player(
            'session',
            17,
            new PlayerIdentity('identity', 'Player'),
            new Position(0.0, 64.0, 0.0),
            4,
            10,
            64.0,
        );
        $before = $player->snapshot();
        $player->movement->position = new Position(2.0, 64.0, 3.0);
        $player->movement->mode = MovementMode::WALKING;
        $player->movement->headYaw = 35.0;
        $player->movement->sneaking = true;
        $after = $player->snapshot();

        self::assertSame(17, $before->runtimeActorId);
        self::assertSame(0.0, $before->position->x);
        self::assertSame(MovementMode::STOPPED, $before->movementMode);
        self::assertSame(2.0, $after->position->x);
        self::assertSame(MovementMode::WALKING, $after->movementMode);
        self::assertSame(35.0, $after->headYaw);
        self::assertTrue($after->sneaking);
        self::assertFalse($after->sprinting);
    }
}
