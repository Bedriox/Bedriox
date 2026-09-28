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

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\World\Position;
use Bedriox\Protocol\Packet\Packet;
use Bedriox\Protocol\Packet\TextPacket;
use Bedriox\Server\Runtime\PlayerConnectionDirectory;
use LogicException;
use PHPUnit\Framework\TestCase;

final class PlayerConnectionDirectoryTest extends TestCase
{
    public function testHandlesRemainBoundToTheSessionThatCreatedThem(): void
    {
        $directory = new PlayerConnectionDirectory();
        $sent = [];
        $swings = 0;
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
        $connection = $directory->connection('PLAYER-UUID');

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
        self::assertFalse($connection->isConnected());
        $replacement = $directory->connection('PLAYER-UUID');
        self::assertTrue($replacement->isConnected());
        $directory->disconnect('player-uuid', 'session-two');
        self::assertFalse($replacement->isConnected());
        self::assertFalse($connection->sendPacket($packet));
        self::assertFalse($connection->swingArm());
    }

    public function testPlayerActionsRejectAReplacementSession(): void
    {
        $directory = new PlayerConnectionDirectory();
        $directory->connect(
            'player-uuid',
            'session-one',
            static fn(): bool => true,
            static fn(Packet $packet, bool $immediate): bool => true,
            teleport: static fn(Position $position): bool => true,
        );
        $actions = $directory->actions('player-uuid');
        $directory->connect(
            'player-uuid',
            'session-two',
            static fn(): bool => true,
            static fn(Packet $packet, bool $immediate): bool => true,
            teleport: static fn(Position $position): bool => true,
        );

        $this->expectException(LogicException::class);
        $actions->teleport(new Position(0.0, 64.0, 0.0));
    }

    public function testInventoryActionsRejectAReplacementSession(): void
    {
        $directory = new PlayerConnectionDirectory();
        $directory->connect(
            'player-uuid',
            'session-one',
            static fn(): bool => true,
            static fn(Packet $packet, bool $immediate): bool => true,
            giveItem: static fn(ItemStack $stack): bool => true,
        );
        $actions = $directory->inventoryActions('player-uuid');
        $directory->connect(
            'player-uuid',
            'session-two',
            static fn(): bool => true,
            static fn(Packet $packet, bool $immediate): bool => true,
            giveItem: static fn(ItemStack $stack): bool => true,
        );

        $this->expectException(LogicException::class);
        $actions->addMainItem(new ItemStack('minecraft:stone', 1));
    }

    public function testStackSizeResolverRejectsAReplacementSession(): void
    {
        $directory = new PlayerConnectionDirectory();
        $directory->connect(
            'player-uuid',
            'session-one',
            static fn(): bool => true,
            static fn(Packet $packet, bool $immediate): bool => true,
            maximumStackSize: static fn(ItemStack $stack): int => 64,
        );
        $maximumStackSize = $directory->maximumStackSize('player-uuid');
        $directory->connect(
            'player-uuid',
            'session-two',
            static fn(): bool => true,
            static fn(Packet $packet, bool $immediate): bool => true,
            maximumStackSize: static fn(ItemStack $stack): int => 64,
        );

        $this->expectException(LogicException::class);
        $maximumStackSize(new ItemStack('minecraft:stone', 1));
    }
}
