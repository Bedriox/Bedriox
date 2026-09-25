<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Protocol\Packet\Packet;
use Bedriox\Protocol\Packet\TextPacket;
use Bedriox\Server\Runtime\PlayerConnectionDirectory;
use PHPUnit\Framework\TestCase;

final class PlayerConnectionDirectoryTest extends TestCase
{
    public function testAHandleFollowsTheCurrentSessionAndRejectsStaleDisconnects(): void
    {
        $directory = new PlayerConnectionDirectory();
        $connection = $directory->connection('PLAYER-UUID');
        $sent = [];
        $swings = 0;

        self::assertFalse($connection->isConnected());
        $directory->connect(
            'player-uuid',
            'session-one',
            static fn(): bool => true,
            static function (Packet $packet, bool $immediate) use (&$sent): bool {
                $sent[] = [$packet, $immediate];

                return true;
            },
            swingArm: static function () use (&$swings): bool {
                ++$swings;

                return true;
            },
        );

        self::assertTrue($connection->isConnected());
        $packet = TextPacket::tip('tip');
        self::assertTrue($connection->sendPacket($packet, true));
        self::assertSame([[$packet, true]], $sent);
        self::assertTrue($connection->swingArm());
        self::assertSame(1, $swings);

        $directory->connect(
            'player-uuid',
            'session-two',
            static fn(): bool => true,
            static fn(Packet $packet, bool $immediate): bool => true,
        );
        $directory->disconnect('player-uuid', 'session-one');
        self::assertTrue($connection->isConnected());
        $directory->disconnect('player-uuid', 'session-two');
        self::assertFalse($connection->isConnected());
        self::assertFalse($connection->sendPacket($packet));
        self::assertFalse($connection->swingArm());
    }
}
