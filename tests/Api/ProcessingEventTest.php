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

use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Event\Processing\CampfireCookStartEvent;
use Bedriox\Api\Event\Processing\CauldronChangedEvent;
use Bedriox\Api\Event\Processing\CauldronChangeEvent;
use Bedriox\Api\Event\Processing\EnchantingOptionsEvent;
use Bedriox\Api\Event\Processing\FurnaceFuelConsumedEvent;
use Bedriox\Api\Event\Processing\FurnaceFuelConsumeEvent;
use Bedriox\Api\Event\Processing\FurnaceSmeltedEvent;
use Bedriox\Api\Event\Processing\FurnaceSmeltEvent;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Processing\CauldronChangeCause;
use Bedriox\Api\Processing\CauldronContentType;
use Bedriox\Api\Processing\EnchantingOption;
use Bedriox\Api\Processing\FurnaceFuelCause;
use Bedriox\Api\Processing\FurnaceType;
use Bedriox\Api\World\BlockPosition;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FurnaceFuelConsumeEvent::class)]
#[CoversClass(FurnaceSmeltEvent::class)]
#[CoversClass(CampfireCookStartEvent::class)]
#[CoversClass(EnchantingOptionsEvent::class)]
#[CoversClass(CauldronChangeEvent::class)]
final class ProcessingEventTest extends TestCase
{
    public function testFurnaceMutableValuesAreBoundedAndRestorable(): void
    {
        $position = new BlockPosition(1, 64, 2);
        $fuel = new FurnaceFuelConsumeEvent(
            $position,
            FurnaceType::FURNACE,
            new ItemStack('minecraft:coal', 1),
            FurnaceFuelCause::PROCESSING,
            1600,
        );
        $state = $fuel->captureState();
        $fuel->setBurnTicks(2000);
        $fuel->cancel();
        $fuel->restoreState($state);

        self::assertSame(1600, $fuel->burnTicks());
        self::assertFalse($fuel->isCancelled());

        $original = new ItemStack('minecraft:iron_ingot', 1);
        $smelt = new FurnaceSmeltEvent(
            $position,
            FurnaceType::FURNACE,
            new ItemStack('minecraft:raw_iron', 1),
            $original,
        );
        $replacement = new ItemStack('minecraft:gold_ingot', 1);
        $smelt->setResult($replacement);
        self::assertSame($replacement, $smelt->result());
    }

    public function testProcessingBoundsRejectUnsafeValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CampfireCookStartEvent(
            new BlockPosition(0, 64, 0),
            4,
            new ItemStack('minecraft:beef', 1),
            new ItemStack('minecraft:cooked_beef', 1),
            600,
        );
    }

    public function testEnchantingOptionsAreTypedAndLimitedToThree(): void
    {
        $option = new EnchantingOption(0, 5, 1, 123, ['minecraft:sharpness' => 1]);
        $event = new EnchantingOptionsEvent(
            self::player(),
            new BlockPosition(0, 64, 0),
            new ItemStack('minecraft:iron_sword', 1),
            [$option],
        );
        self::assertSame([$option], $event->options());

        $this->expectException(InvalidArgumentException::class);
        $event->setOptions([$option, $option, $option, $option]);
    }

    public function testCauldronOutcomeRequiresContentAndLevelToAgree(): void
    {
        $event = new CauldronChangeEvent(
            self::player(),
            new BlockPosition(0, 64, 0),
            CauldronContentType::EMPTY,
            0,
            CauldronContentType::WATER,
            3,
            CauldronChangeCause::BUCKET,
        );
        $event->setOutcome(CauldronContentType::EMPTY, 0);
        self::assertSame(CauldronContentType::EMPTY, $event->newContent());

        $this->expectException(InvalidArgumentException::class);
        $event->setOutcome(CauldronContentType::EMPTY, 1);
    }

    public function testCommittedEventsCarryThePostEventMarker(): void
    {
        $position = new BlockPosition(0, 64, 0);
        self::assertInstanceOf(PostEvent::class, new FurnaceFuelConsumedEvent(
            $position,
            FurnaceType::SMOKER,
            new ItemStack('minecraft:coal', 1),
            FurnaceFuelCause::PROCESSING,
            1600,
        ));
        self::assertInstanceOf(PostEvent::class, new FurnaceSmeltedEvent(
            $position,
            FurnaceType::BLAST_FURNACE,
            new ItemStack('minecraft:raw_iron', 1),
            new ItemStack('minecraft:iron_ingot', 1),
        ));
        self::assertInstanceOf(PostEvent::class, new CauldronChangedEvent(
            null,
            $position,
            CauldronContentType::EMPTY,
            0,
            CauldronContentType::WATER,
            1,
            CauldronChangeCause::PRECIPITATION,
        ));
    }

    private static function player(): Player
    {
        return new Player(
            'Alex',
            '00000000-0000-0000-0000-000000000001',
            new \Bedriox\Api\World\Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            new \Bedriox\Api\Inventory\Inventory(array_fill(0, 36, null), 0),
        );
    }
}
