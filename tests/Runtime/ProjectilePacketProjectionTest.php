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

use Bedriox\Api\Potion\PotionType;
use Bedriox\Api\World\BlockFace;
use Bedriox\Protocol\Packet\ActorEventPacket;
use Bedriox\Protocol\Packet\ActorEventType;
use Bedriox\Protocol\Packet\MoveActorAbsoluteFlag;
use Bedriox\Protocol\Packet\MoveActorAbsolutePacket;
use Bedriox\Protocol\Packet\MoveActorDeltaPacket;
use Bedriox\Protocol\Packet\SetActorMotionPacket;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Gameplay\Projectile\Projectile;
use Bedriox\Server\Gameplay\Projectile\ProjectileState;
use Bedriox\Server\Gameplay\Projectile\ProjectileType;
use Bedriox\Server\Runtime\BedrockWorldEventPacketEncoder;
use Bedriox\Server\Simulation\Event\ProjectileMoved;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\BlockPosition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BedrockWorldEventPacketEncoder::class)]
final class ProjectilePacketProjectionTest extends TestCase
{
    public function testEveryFlyingArrowTickProjectsItsAuthoritativePosition(): void
    {
        $projectile = self::arrow(
            new Position(4.0, 66.0, 8.0),
            new EntityMotion(1.0, -0.1, 0.5),
            ageTicks: 3,
        );

        $packets = (new BedrockWorldEventPacketEncoder())->encode(
            new ProjectileMoved($projectile, ['viewer'], false),
            [],
        );

        self::assertCount(1, $packets);
        self::assertSame('viewer', $packets[0]->sessionId);
        self::assertInstanceOf(MoveActorDeltaPacket::class, $packets[0]->packet);
        self::assertSame(4.0, $packets[0]->packet->x);
        self::assertSame(66.0, $packets[0]->packet->y);
        self::assertSame(8.0, $packets[0]->packet->z);
        self::assertEqualsWithDelta(-5.111, $packets[0]->packet->pitch, 0.001);
        self::assertEqualsWithDelta(63.435, $packets[0]->packet->yaw, 0.001);
        self::assertEqualsWithDelta(63.435, $packets[0]->packet->headYaw, 0.001);
        self::assertTrue($packets[0]->packet->tick->equals(\Bedriox\Protocol\Value\UnsignedLong::fromInt(1)));
    }

    public function testEmbeddedArrowProjectsFinalPositionZeroMotionAndShake(): void
    {
        $projectile = self::arrow(
            new Position(4.875, 66.25, 8.0),
            new EntityMotion(0.0, 0.0, 0.0),
            ageTicks: 8,
            state: ProjectileState::EMBEDDED,
            embeddedBlock: new BlockPosition(5, 66, 8),
            embeddedFace: BlockFace::WEST,
        );

        $packets = (new BedrockWorldEventPacketEncoder())->encode(
            new ProjectileMoved($projectile, ['viewer'], true, true),
            [],
        );

        self::assertCount(3, $packets);
        self::assertInstanceOf(MoveActorAbsolutePacket::class, $packets[0]->packet);
        self::assertTrue($packets[0]->packet->hasFlag(MoveActorAbsoluteFlag::OnGround));
        self::assertInstanceOf(SetActorMotionPacket::class, $packets[1]->packet);
        self::assertSame(0.0, $packets[1]->packet->motionX);
        self::assertSame(0.0, $packets[1]->packet->motionY);
        self::assertSame(0.0, $packets[1]->packet->motionZ);
        self::assertInstanceOf(ActorEventPacket::class, $packets[2]->packet);
        self::assertSame(ActorEventType::ArrowShake, $packets[2]->packet->event);
    }

    private static function arrow(
        Position $position,
        EntityMotion $motion,
        int $ageTicks,
        ProjectileState $state = ProjectileState::FLYING,
        ?BlockPosition $embeddedBlock = null,
        ?BlockFace $embeddedFace = null,
    ): Projectile {
        return new Projectile(
            100,
            100,
            'owner',
            PotionType::WATER,
            false,
            $position,
            $motion,
            ageTicks: $ageTicks,
            type: ProjectileType::ARROW,
            state: $state,
            embeddedBlock: $embeddedBlock,
            embeddedFace: $embeddedFace,
        );
    }
}
