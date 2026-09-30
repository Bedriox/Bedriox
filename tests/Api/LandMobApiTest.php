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

use Bedriox\Api\Entity\EntityInteractionType;
use Bedriox\Api\Entity\EntityTargetReason;
use Bedriox\Api\Entity\WoolColor;
use Bedriox\Api\Event\Entity\EntityInteractedEvent;
use Bedriox\Api\Event\Entity\EntityShearedEvent;
use Bedriox\Api\Event\Entity\EntityShearEvent;
use Bedriox\Api\Event\Entity\EntityTargetChangedEvent;
use Bedriox\Api\Event\Entity\EntityTargetEvent;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position as ApiPosition;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Entity\Vanilla\SheepEntity;
use Bedriox\Server\Entity\Vanilla\SkeletonEntity;
use Bedriox\Server\Simulation\Position;
use LogicException;
use PHPUnit\Framework\TestCase;

final class LandMobApiTest extends TestCase
{
    public function testWoolColorsExposeAllCanonicalWoolIdentifiers(): void
    {
        self::assertCount(16, WoolColor::cases());
        self::assertSame('minecraft:white_wool', WoolColor::WHITE->woolIdentifier());
        self::assertSame('minecraft:light_gray_wool', WoolColor::LIGHT_GRAY->woolIdentifier());
        self::assertSame('minecraft:black_wool', WoolColor::BLACK->woolIdentifier());
    }

    public function testShearEventsExposeMutableProposalAndImmutableCommittedResult(): void
    {
        $player = $this->player();
        $sheep = $this->sheep();
        $shears = new ItemStack('minecraft:shears', 1);
        $whiteWool = new ItemStack('minecraft:white_wool', 2);
        $blackWool = new ItemStack('minecraft:black_wool', 3);
        $event = new EntityShearEvent($player, $sheep, $shears, [$whiteWool]);
        $state = $event->captureState();

        $event->setDrops([$blackWool]);
        $event->cancel();
        self::assertSame([$blackWool], $event->getDrops());
        self::assertTrue($event->isCancelled());

        $event->restoreState($state);
        self::assertSame([$whiteWool], $event->getDrops());
        self::assertFalse($event->isCancelled());

        $committed = new EntityShearedEvent($player, $sheep, $shears, [$blackWool]);
        self::assertSame([$blackWool], $committed->drops);
        self::assertSame($sheep, $committed->entity);
        self::assertSame($player, $committed->player);
    }

    public function testTargetEventsExposeTypedReasonAndRollbackMutableTarget(): void
    {
        $skeleton = new SkeletonEntity(
            '00000000-0000-4000-8000-000000000003',
            3,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
        $previous = new CowEntity(
            '00000000-0000-4000-8000-000000000004',
            4,
            'world',
            new Position(1.0, 64.0, 0.0),
        );
        $target = $this->sheep();
        $event = new EntityTargetEvent($skeleton, $previous, $target, EntityTargetReason::CLOSEST_PLAYER);
        $state = $event->captureState();

        $event->setTarget(null);
        $event->cancel();
        self::assertNull($event->target());
        self::assertTrue($event->isCancelled());

        $event->restoreState($state);
        self::assertSame($target, $event->target());
        self::assertFalse($event->isCancelled());

        $committed = new EntityTargetChangedEvent(
            $skeleton,
            $previous,
            $target,
            EntityTargetReason::RETALIATION,
        );
        self::assertSame($previous, $committed->previousTarget);
        self::assertSame($target, $committed->target);
        self::assertSame(EntityTargetReason::RETALIATION, $committed->reason);
    }

    public function testReadOnlyProposalRejectsMutation(): void
    {
        $event = new EntityShearEvent(
            $this->player(),
            $this->sheep(),
            new ItemStack('minecraft:shears', 1),
            [],
        );
        $event->setReadOnly(true);

        $this->expectException(LogicException::class);
        $event->setDrops([new ItemStack('minecraft:white_wool', 1)]);
    }

    public function testEntityInteractedEventExposesCommittedInteractionSnapshot(): void
    {
        $player = $this->player();
        $sheep = $this->sheep();
        $held = new ItemStack('minecraft:red_dye', 3);
        $event = new EntityInteractedEvent($player, $sheep, EntityInteractionType::ITEM_INTERACT, $held);

        self::assertInstanceOf(PostEvent::class, $event);
        self::assertSame($player, $event->player);
        self::assertSame($sheep, $event->entity);
        self::assertSame(EntityInteractionType::ITEM_INTERACT, $event->interaction);
        self::assertSame($held, $event->heldItem);
    }

    private function sheep(): SheepEntity
    {
        return new SheepEntity(
            '00000000-0000-4000-8000-000000000002',
            2,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
    }

    private function player(): Player
    {
        return new Player(
            'Player',
            '00000000-0000-4000-8000-000000000001',
            new ApiPosition(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory([], 0),
        );
    }
}
