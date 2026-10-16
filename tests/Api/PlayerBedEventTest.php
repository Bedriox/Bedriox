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

namespace Bedriox\Server\Tests\Api;

use Bedriox\Api\Event\Player\PlayerBedEnteredEvent;
use Bedriox\Api\Event\Player\PlayerBedEnterEvent;
use Bedriox\Api\Event\Player\PlayerBedEnterResult;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\TranslatableMessage;
use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\Position;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PlayerBedEventTest extends TestCase
{
    public function testPluginCanOverrideEnvironmentalDenialAndSuppressFeedback(): void
    {
        $event = new PlayerBedEnterEvent(
            $this->player(),
            new BlockPosition(4, 64, 6),
            PlayerBedEnterResult::NOT_NIGHT,
            new TranslatableMessage('tile.bed.noSleep'),
        );

        $event->setResult(PlayerBedEnterResult::ALLOWED);
        $event->setMessage(null);

        self::assertSame(PlayerBedEnterResult::NOT_NIGHT, $event->originalResult);
        self::assertSame(PlayerBedEnterResult::ALLOWED, $event->result());
        self::assertNull($event->message());
    }

    public function testPluginCannotOverrideStructurallyInvalidBed(): void
    {
        $event = new PlayerBedEnterEvent(
            $this->player(),
            new BlockPosition(4, 64, 6),
            PlayerBedEnterResult::INCOMPLETE,
            'This bed is incomplete.',
        );

        $this->expectException(InvalidArgumentException::class);
        $event->setResult(PlayerBedEnterResult::ALLOWED);
    }

    public function testEnteredEventIsAnImmutablePostEvent(): void
    {
        $event = new PlayerBedEnteredEvent($this->player(), new BlockPosition(4, 64, 6));

        self::assertInstanceOf(PostEvent::class, $event);
        self::assertSame(4, $event->bed->x);
    }

    private function player(): Player
    {
        return new Player(
            'Sleeper',
            '00000000-0000-0000-0000-000000000001',
            new Position(4.5, 65.0, 6.5),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
        );
    }
}
