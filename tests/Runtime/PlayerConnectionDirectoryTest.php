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

use Bedriox\Api\Effect\EffectCause;
use Bedriox\Api\Effect\EffectInstance;
use Bedriox\Api\Effect\EffectType;
use Bedriox\Api\Entity\Value\EmoteId;
use Bedriox\Api\Entity\Value\Motion;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\World\Position;
use Bedriox\Protocol\Packet\Packet;
use Bedriox\Protocol\Packet\TextPacket;
use Bedriox\Server\Runtime\PlayerConnectionDirectory;
use LogicException;
use PHPUnit\Framework\TestCase;

final class PlayerConnectionDirectoryTest extends TestCase
{
    public function testSharedActorActionsRemainSessionBoundAndTyped(): void
    {
        $directory = new PlayerConnectionDirectory();
        $received = [];
        $accept = static function (string $action, mixed ...$values) use (&$received): bool {
            $received[] = [$action, ...$values];

            return true;
        };
        $directory->connect(
            'player-uuid',
            'session-one',
            static fn(): bool => true,
            static fn(Packet $packet, bool $immediate): bool => true,
            setMotion: static fn(Motion $motion): bool => $accept('motion', $motion),
            playEmote: static fn(EmoteId $emote): bool => $accept('emote', $emote),
            setRotation: static fn(float $yaw, float $pitch): bool => $accept('rotation', $yaw, $pitch),
            setNameTag: static fn(string $nameTag): bool => $accept('name', $nameTag),
            setNameTagVisible: static fn(bool $visible): bool => $accept('visible', $visible),
            setImmobile: static fn(bool $immobile): bool => $accept('immobile', $immobile),
            setInvisible: static fn(bool $invisible): bool => $accept('invisible', $invisible),
            setScale: static fn(float $scale): bool => $accept('scale', $scale),
            setGravityEnabled: static fn(bool $enabled): bool => $accept('gravity', $enabled),
        );
        $actions = $directory->actions('player-uuid');
        $motion = new Motion(0.1, 0.2, 0.3);
        $emote = new EmoteId('00000000-0000-4000-8000-000000000001');

        $actions->setMotion($motion);
        $actions->playEmote($emote);
        $actions->setRotation(90.0, 10.0);
        $actions->setNameTag('Guide');
        $actions->setNameTagVisible(true);
        $actions->setImmobile(true);
        $actions->setInvisible(true);
        $actions->setScale(1.25);
        $actions->setGravityEnabled(false);

        self::assertSame([
            ['motion', $motion],
            ['emote', $emote],
            ['rotation', 90.0, 10.0],
            ['name', 'Guide'],
            ['visible', true],
            ['immobile', true],
            ['invisible', true],
            ['scale', 1.25],
            ['gravity', false],
        ], $received);

        $directory->disconnect('player-uuid', 'session-one');
        $this->expectException(LogicException::class);
        $actions->setMotion($motion);
    }

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

    public function testEffectActionsRejectAReplacementSession(): void
    {
        $directory = new PlayerConnectionDirectory();
        $directory->connect(
            'player-uuid',
            'session-one',
            static fn(): bool => true,
            static fn(Packet $packet, bool $immediate): bool => true,
            addEffect: static fn(EffectInstance $effect, EffectCause $cause): bool => true,
        );
        $actions = $directory->effectActions('player-uuid');
        $directory->connect(
            'player-uuid',
            'session-two',
            static fn(): bool => true,
            static fn(Packet $packet, bool $immediate): bool => true,
            addEffect: static fn(EffectInstance $effect, EffectCause $cause): bool => true,
        );

        $this->expectException(LogicException::class);
        $actions->add(new EffectInstance(EffectType::SPEED, 20), EffectCause::PLUGIN);
    }
}
