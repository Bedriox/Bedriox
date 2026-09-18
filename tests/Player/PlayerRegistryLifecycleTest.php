<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Player;

use Bedriox\Server\Player\Player;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerRegistry;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class PlayerRegistryLifecycleTest extends TestCase
{
    public function testRemovalClearsEveryIndexBeforeRepeatedReuse(): void
    {
        $registry = new PlayerRegistry(1);

        for ($cycle = 0; $cycle < 50; ++$cycle) {
            $session = $cycle % 2 === 0 ? 'session-a' : 'session-b';
            $player = new Player(
                $session,
                77,
                new PlayerIdentity('stable-identity', 'Player'),
                new Position(0.0, 64.0, 0.0),
                4,
                $cycle,
                64.0,
            );
            $registry->add($player);
            self::assertSame($player, $registry->player($session));
            self::assertTrue($registry->hasSession($session));
            self::assertTrue($registry->hasIdentity('stable-identity'));
            self::assertTrue($registry->hasActorId(77));

            self::assertSame($player, $registry->remove($session));
            self::assertSame(0, $registry->count());
            self::assertFalse($registry->hasSession($session));
            self::assertFalse($registry->hasIdentity('stable-identity'));
            self::assertFalse($registry->hasActorId(77));
            self::assertSame([], $registry->recipients());
            self::assertSame([], $registry->snapshots());
        }
    }
}
