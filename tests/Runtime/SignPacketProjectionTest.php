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

use Bedriox\Protocol\Packet\BlockActorDataPacket;
use Bedriox\Protocol\Packet\OpenSignPacket;
use Bedriox\Protocol\Packet\SignBlockActorData;
use Bedriox\Server\Runtime\BedrockWorldEventPacketEncoder;
use Bedriox\Server\Simulation\Event\BlockEntityChanged;
use Bedriox\Server\Simulation\Event\SignEditorOpened;
use Bedriox\Server\World\BlockEntity\SignBlockEntity;
use Bedriox\Server\World\BlockEntity\SignText;
use Bedriox\Server\World\BlockPosition;
use PHPUnit\Framework\TestCase;

final class SignPacketProjectionTest extends TestCase
{
    public function testEditorOpenIsDirectedOnlyToOwningSession(): void
    {
        $directed = (new BedrockWorldEventPacketEncoder())->encode(
            new SignEditorOpened('editor', new BlockPosition(2, 70, -4), false),
            [],
        );

        self::assertCount(1, $directed);
        self::assertSame('editor', $directed[0]->sessionId);
        self::assertInstanceOf(OpenSignPacket::class, $directed[0]->packet);
        self::assertFalse($directed[0]->packet->front);
    }

    public function testSignStateChangeProjectsTypedBlockActorSnapshot(): void
    {
        $sign = new SignBlockEntity(
            new BlockPosition(2, 70, -4),
            SignText::fromBlob('hello', 0xffff0000, true),
            SignText::fromBlob('back'),
        );

        $directed = (new BedrockWorldEventPacketEncoder())->encode(
            new BlockEntityChanged($sign, ['editor', 'peer']),
            [],
        );

        self::assertCount(2, $directed);
        foreach ($directed as $packet) {
            self::assertInstanceOf(BlockActorDataPacket::class, $packet->packet);
            $data = SignBlockActorData::decode($packet->packet->networkNbt);
            self::assertSame('hello', $data->front->text);
            self::assertTrue($data->front->glowing);
        }
    }

    public function testHangingSignProjectsHangingBlockActorIdentity(): void
    {
        $sign = SignBlockEntity::empty(new BlockPosition(2, 70, -4), hanging: true);
        $directed = (new BedrockWorldEventPacketEncoder())->encode(
            new BlockEntityChanged($sign, ['editor']),
            [],
        );

        self::assertCount(1, $directed);
        self::assertInstanceOf(BlockActorDataPacket::class, $directed[0]->packet);
        self::assertSame(
            'HangingSign',
            SignBlockActorData::decode($directed[0]->packet->networkNbt)->identifier,
        );
    }
}
