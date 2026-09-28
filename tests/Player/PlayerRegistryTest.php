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

namespace Bedriox\Server\Tests\Player;

use Bedriox\Server\Player\Player;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerRegistry;
use Bedriox\Server\Simulation\Position;
use LogicException;
use PHPUnit\Framework\TestCase;

final class PlayerRegistryTest extends TestCase
{
    public function testIndexesSnapshotsRecipientsAndCompleteRemoval(): void
    {
        $registry = new PlayerRegistry(2);
        $bravo = $this->player('b-session', 'identity-b', 'Bravo');
        $alpha = $this->player('a-session', 'identity-a', 'Alpha');
        $registry->add($bravo);
        $registry->add($alpha);

        self::assertSame($bravo, $registry->player('b-session'));
        self::assertTrue($registry->hasIdentity('identity-a'));
        self::assertSame(['a-session'], $registry->recipients('b-session'));
        self::assertSame(['a-session', 'b-session'], array_map(
            static fn($snapshot): string => $snapshot->sessionId,
            $registry->snapshots(),
        ));

        self::assertSame($bravo, $registry->remove('b-session'));
        self::assertNull($registry->player('b-session'));
        self::assertFalse($registry->hasIdentity('identity-b'));
        self::assertNull($registry->remove('b-session'));
    }

    public function testDuplicateSessionIdentityAndCapacityFailWithoutChangingIndexes(): void
    {
        $registry = new PlayerRegistry(1);
        $registry->add($this->player('session', 'identity', 'Player'));

        foreach ([
            $this->player('session', 'other', 'Duplicate session', 4),
            $this->player('other', 'identity', 'Duplicate identity', 4),
            $this->player('other', 'other', 'Duplicate actor', 3),
            $this->player('other', 'other', 'Over capacity', 4),
        ] as $player) {
            try {
                $registry->add($player);
                self::fail('Invalid registry insertion succeeded.');
            } catch (LogicException) {
            }
        }

        self::assertSame(1, $registry->count());
        self::assertSame(['session'], $registry->recipients());
    }

    private function player(string $sessionId, string $identity, string $name, ?int $actorId = null): Player
    {
        return new Player(
            $sessionId,
            $actorId ?? match ($sessionId) {
                'a-session' => 1,
                'b-session' => 2,
                'session' => 3,
                default => 4,
            },
            new PlayerIdentity($identity, $name),
            new Position(0.0, 64.0, 0.0),
            4,
            0,
            64.0,
        );
    }
}
