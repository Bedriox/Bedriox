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

use Bedriox\Api\Entity\Skin;
use Bedriox\Protocol\Packet\ActorFlag;
use Bedriox\Protocol\Packet\AddPlayerPacket;
use Bedriox\Protocol\Packet\PlayerListAddPacket;
use Bedriox\Protocol\Packet\PlayerListRemovePacket;
use Bedriox\Protocol\Packet\PlayerSkinPacket;
use Bedriox\Protocol\Packet\RemoveActorPacket;
use Bedriox\Server\Entity\Human\HumanEntityFactory;
use Bedriox\Server\Runtime\BedrockWorldEventPacketEncoder;
use Bedriox\Server\Simulation\Event\EntityActorRemoved;
use Bedriox\Server\Simulation\Event\EntityActorSpawned;
use Bedriox\Server\Simulation\Event\HumanSkinChanged;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class HumanActorProjectionTest extends TestCase
{
    public function testHumanUsesRetailPlayerOrderingAndMetadataBaseline(): void
    {
        $human = (new HumanEntityFactory())->create(
            '00000000-0000-4000-8000-000000000010',
            50,
            'world',
            new Position(0.5, 64.0, 0.5),
            'Guide',
            new Skin(
                'guide',
                64,
                64,
                str_repeat("\0", 64 * 64 * 4),
                '{"geometry":{"default":"geometry.humanoid.custom"}}',
            ),
        );
        $packets = (new BedrockWorldEventPacketEncoder())->encode(
            new EntityActorSpawned($human, ['viewer'], true),
            [],
        );

        self::assertInstanceOf(PlayerListAddPacket::class, $packets[0]->packet);
        self::assertTrue($packets[0]->packet->entries[0]->trustedSkin);
        self::assertInstanceOf(AddPlayerPacket::class, $packets[1]->packet);
        self::assertSame(64.0, $packets[1]->packet->y, 'Initial human spawn uses the authoritative feet position.');
        self::assertInstanceOf(PlayerListRemovePacket::class, $packets[count($packets) - 1]->packet);
        $metadata = $packets[1]->packet->metadata;
        self::assertCount(14, $metadata);
        self::assertSame([0, 1, 3, 4, 7, 37, 38, 42, 53, 54, 81, 92, 120, 130], array_column($metadata, 'id'));
        self::assertSame(1, $metadata[10]->value);
        self::assertSame(130, $metadata[13]->id);
        self::assertIsInt($metadata[0]->value);
        self::assertNotSame(0, $metadata[0]->value & ActorFlag::CanClimb->mask());
        self::assertNotSame(0, $metadata[0]->value & ActorFlag::HasGravity->mask());
    }

    public function testLateViewerSkinChangeAndRemovalKeepTheHumanLifecycleComplete(): void
    {
        $human = (new HumanEntityFactory())->create(
            '00000000-0000-4000-8000-000000000011',
            51,
            'world',
            new Position(0.5, 64.0, 0.5),
            'Guide',
            self::skin('first'),
        );
        $encoder = new BedrockWorldEventPacketEncoder();
        $late = $encoder->encode(new EntityActorSpawned($human, ['late'], true), []);
        self::assertInstanceOf(PlayerListAddPacket::class, $late[0]->packet);
        self::assertInstanceOf(AddPlayerPacket::class, $late[1]->packet);
        self::assertInstanceOf(PlayerListRemovePacket::class, $late[count($late) - 1]->packet);

        $human->applySkin(self::skin('second'));
        $skin = $encoder->encode(new HumanSkinChanged($human, ['late']), []);
        self::assertCount(1, $skin);
        self::assertInstanceOf(PlayerSkinPacket::class, $skin[0]->packet);
        self::assertSame('second', $skin[0]->packet->newSkinName);
        self::assertTrue($skin[0]->packet->trustedSkin);

        $removed = $encoder->encode(new EntityActorRemoved($human, ['late']), []);
        self::assertCount(1, $removed);
        self::assertInstanceOf(RemoveActorPacket::class, $removed[0]->packet);
        self::assertSame(51, $removed[0]->packet->actorUniqueId);
    }

    private static function skin(string $id): Skin
    {
        return new Skin(
            $id,
            64,
            64,
            str_repeat("\0", 64 * 64 * 4),
            '{"geometry":{"default":"geometry.humanoid.custom"}}',
        );
    }
}
