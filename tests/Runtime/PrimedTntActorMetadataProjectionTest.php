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

use Bedriox\Protocol\Packet\ActorFlag;
use Bedriox\Protocol\Packet\ActorMetadata;
use Bedriox\Protocol\Packet\AddActorPacket;
use Bedriox\Protocol\Packet\MoveActorAbsolutePacket;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\Misc\PrimedTntEntity;
use Bedriox\Server\Runtime\BedrockLivingActorProjector;
use Bedriox\Server\Runtime\BedrockWorldEventPacketEncoder;
use Bedriox\Server\Simulation\Event\EntityActorMoved;
use Bedriox\Server\Simulation\Event\EntityActorSpawned;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class PrimedTntActorMetadataProjectionTest extends TestCase
{
    public function testProjectsIgnitedFuseAndUnderwaterVariantWithoutCollision(): void
    {
        $entity = new PrimedTntEntity(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.5, 64.0, 0.5),
            47,
            true,
        );
        $projector = new BedrockLivingActorProjector();
        $metadata = $projector->metadata($entity);
        $flags = self::metadata($metadata, 0)->value;

        self::assertIsInt($flags);
        self::assertNotSame(0, $flags & ActorFlag::Ignited->mask());
        self::assertSame(0, $flags & ActorFlag::HasCollision->mask());
        self::assertSame(1, self::metadata($metadata, 2)->value);
        self::assertSame(47, self::metadata($metadata, 55)->value);
        self::assertSame([], $projector->spawnAttributes($entity));
        self::assertSame([], $projector->equipmentPackets($entity, null));
    }

    public function testSpawnAndMovementUseTheBedrockCenterOffset(): void
    {
        $entity = new PrimedTntEntity(
            EntityUuid::random(),
            2,
            'world',
            new Position(4.5, 64.0, 8.5),
        );
        $encoder = new BedrockWorldEventPacketEncoder();
        $spawnPackets = $encoder->encode(new EntityActorSpawned($entity, ['viewer']), []);
        $spawn = $spawnPackets[0]->packet;

        self::assertCount(1, $spawnPackets);
        self::assertInstanceOf(AddActorPacket::class, $spawn);
        self::assertSame('minecraft:tnt', $spawn->identifier);
        self::assertSame(64.49, $spawn->y);

        $entity->moveTo('world', new Position(5.5, 63.0, 8.5), 0.0, 0.0);
        $movement = $encoder->entityMovementPackets(new EntityActorMoved($entity, 1, ['viewer']));
        self::assertInstanceOf(MoveActorAbsolutePacket::class, $movement[0]);
        self::assertSame(63.49, $movement[0]->y);
    }

    /** @param list<ActorMetadata> $metadata */
    private static function metadata(array $metadata, int $id): ActorMetadata
    {
        foreach ($metadata as $entry) {
            if ($entry->id === $id) {
                return $entry;
            }
        }
        self::fail("Missing actor metadata {$id}.");
    }
}
