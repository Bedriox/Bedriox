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

use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Protocol\Packet\AddActorPacket;
use Bedriox\Protocol\Packet\BlockSyncType;
use Bedriox\Protocol\Packet\MoveActorAbsolutePacket;
use Bedriox\Protocol\Packet\RemoveActorPacket;
use Bedriox\Protocol\Packet\SetActorDataPacket;
use Bedriox\Protocol\Packet\UpdateBlockSyncedPacket;
use Bedriox\Server\Entity\Block\FallingBlockEntity;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Runtime\BedrockChunkPacketSerializer;
use Bedriox\Server\Runtime\BedrockWorldEventPacketEncoder;
use Bedriox\Server\Simulation\Event\FallingBlockActorMoved;
use Bedriox\Server\Simulation\Event\FallingBlockActorRemoved;
use Bedriox\Server\Simulation\Event\FallingBlockActorSettled;
use Bedriox\Server\Simulation\Event\FallingBlockActorSpawned;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\BlockPosition;
use PHPUnit\Framework\TestCase;

final class FallingBlockPacketProjectionTest extends TestCase
{
    public function testSpawnProjectsTheExactBlockNetworkRuntimeId(): void
    {
        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $translator = new BlockNetworkTranslator($states, $data->blockStateRegistry());
        $encoder = new BedrockWorldEventPacketEncoder(new BedrockChunkPacketSerializer($translator));
        $sand = CanonicalBlockState::from('minecraft:sand');
        $entity = new FallingBlockEntity(
            '00000000-0000-4000-8000-000000000001',
            77,
            VanillaEntityDefinitions::fallingBlock(),
            'world',
            new Position(1.5, 70.0, 2.5),
            $sand,
        );

        $source = new BlockPosition(1, 70, 2);
        $air = $states->internalId(CanonicalBlockState::from('minecraft:air'));
        $directed = $encoder->encode(new FallingBlockActorSpawned($entity, $source, $air, ['viewer']), []);

        self::assertCount(2, $directed);
        self::assertSame('viewer', $directed[0]->sessionId);
        self::assertInstanceOf(UpdateBlockSyncedPacket::class, $directed[0]->packet);
        self::assertSame(BlockSyncType::CREATE, $directed[0]->packet->syncType);
        self::assertSame(77, $directed[0]->packet->runtimeEntityId->toSignedBits());
        self::assertInstanceOf(AddActorPacket::class, $directed[1]->packet);
        self::assertSame('minecraft:falling_block', $directed[1]->packet->identifier);
        $metadata = [];
        foreach ($directed[1]->packet->metadata as $entry) {
            $metadata[$entry->id] = $entry->value;
        }
        self::assertSame(
            $translator->toNetwork($states->internalId($sand))->signed(),
            $metadata[2] ?? null,
        );
        self::assertSame(
            $directed[1]->packet->encode(),
            AddActorPacket::decode($directed[1]->packet->encode())->encode(),
        );

        $movement = $encoder->encode(new FallingBlockActorMoved($entity, 1, ['viewer']), []);
        self::assertNotEmpty($movement);
        self::assertInstanceOf(MoveActorAbsolutePacket::class, $movement[0]->packet);
        self::assertEqualsWithDelta(70.49, $movement[0]->packet->y, 0.000_001);

        $entity->settleUntil(4);
        $settled = $encoder->encode(new FallingBlockActorSettled(
            $entity,
            new BlockPosition(1, 64, 2),
            $states->internalId($sand),
            ['viewer'],
        ), []);
        self::assertCount(4, $settled);
        self::assertInstanceOf(MoveActorAbsolutePacket::class, $settled[0]->packet);
        self::assertInstanceOf(UpdateBlockSyncedPacket::class, $settled[2]->packet);
        self::assertSame(BlockSyncType::DESTROY, $settled[2]->packet->syncType);
        self::assertInstanceOf(SetActorDataPacket::class, $settled[3]->packet);

        $removed = $encoder->encode(new FallingBlockActorRemoved($entity, ['viewer']), []);
        self::assertCount(1, $removed);
        self::assertInstanceOf(RemoveActorPacket::class, $removed[0]->packet);
    }
}
