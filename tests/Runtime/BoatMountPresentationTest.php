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

use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Protocol\Packet\SetActorDataPacket;
use Bedriox\Server\Entity\Mount\MountSeatOffset;
use Bedriox\Server\Entity\Mount\MountViewConstraint;
use Bedriox\Server\Runtime\BedrockWorldEventPacketEncoder;
use Bedriox\Server\Simulation\Event\ActorMounted;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\PlayerSnapshot;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\VerticalState;
use PHPUnit\Framework\TestCase;

final class BoatMountPresentationTest extends TestCase
{
    public function testBoatViewConstraintProjectsToPlayerSeatMetadata(): void
    {
        $event = new ActorMounted(
            20,
            10,
            MountSeat::DRIVER,
            new MountSeatOffset(0.2, 1.020_01, 0.0),
            true,
            new PlayerSnapshot(
                'player',
                'identity',
                'Player',
                new Position(0.0, 64.0, 0.0),
                0.0,
                0.0,
                MovementMode::STOPPED,
                1,
                VerticalState::GROUNDED,
                0.0,
                runtimeActorId: 10,
                riding: true,
            ),
            ['player'],
            MountViewConstraint::boat(),
        );

        $packets = (new BedrockWorldEventPacketEncoder())->encode($event, []);
        self::assertInstanceOf(SetActorDataPacket::class, $packets[0]->packet);
        $metadata = [];
        foreach ($packets[0]->packet->metadata as $entry) {
            $metadata[$entry->id] = $entry->value;
        }

        self::assertSame(1, $metadata[57]);
        self::assertSame(90.0, $metadata[58]);
        self::assertSame(0, $metadata[59]);
        self::assertSame(-90.0, $metadata[60]);
    }
}
