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

use Bedriox\Api\Event\Block\BlockInteractionType;
use Bedriox\Api\Event\Block\BlockStateChangedEvent;
use Bedriox\Api\Event\Block\BlockStateChangeReason;
use Bedriox\Api\Event\Block\PlayerInteractBlockEvent;
use Bedriox\Api\Event\Block\PlayerInteractedBlockEvent;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Block;
use Bedriox\Api\World\BlockFace;
use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\Position;
use PHPUnit\Framework\TestCase;

final class BlockBehaviorEventTest extends TestCase
{
    public function testInteractionIntentIsCancellableAndCommitIsObservational(): void
    {
        $player = self::player();
        $block = new Block(new BlockPosition(1, 64, 2), 'minecraft:oak_button');
        $intent = new PlayerInteractBlockEvent(
            $player,
            $block,
            BlockInteractionType::USE,
            BlockFace::NORTH,
            0.25,
            0.5,
            0.75,
        );

        self::assertFalse($intent->isCancelled());
        $intent->cancel();
        self::assertTrue($intent->isCancelled());

        $committed = new PlayerInteractedBlockEvent(
            $player,
            $block,
            BlockInteractionType::USE,
            BlockFace::NORTH,
            0.25,
            0.5,
            0.75,
        );
        self::assertInstanceOf(PostEvent::class, $committed);
    }

    public function testStateChangeReasonsAreStablePluginValues(): void
    {
        self::assertSame('neighbor_update', BlockStateChangeReason::NEIGHBOR_UPDATE->value);
        self::assertSame('fluid_flow', BlockStateChangeReason::FLUID_FLOW->value);
        self::assertSame('fluid_reaction', BlockStateChangeReason::FLUID_REACTION->value);
        self::assertSame('tool_transform', BlockStateChangeReason::TOOL_TRANSFORM->value);

        $position = new BlockPosition(4, 70, 8);
        $event = new BlockStateChangedEvent(
            new Block($position, 'minecraft:oak_fence_gate', ['open_bit' => 0]),
            new Block($position, 'minecraft:oak_fence_gate', ['open_bit' => 1]),
            BlockStateChangeReason::INTERACTION,
        );
        self::assertInstanceOf(PostEvent::class, $event);
        self::assertSame(0, $event->previous->properties['open_bit']);
        self::assertSame(1, $event->current->properties['open_bit']);
    }

    public function testHitCoordinatesMustBeNormalized(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PlayerInteractBlockEvent(
            self::player(),
            new Block(new BlockPosition(0, 64, 0), 'minecraft:stone'),
            BlockInteractionType::USE,
            BlockFace::UP,
            1.01,
            0.5,
            0.5,
        );
    }

    private static function player(): Player
    {
        return new Player(
            'Builder',
            '00000000-0000-4000-8000-000000000001',
            new Position(0.5, 64.0, 0.5),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
        );
    }
}
