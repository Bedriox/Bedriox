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

namespace Bedriox\Server\Tests\World\Storage\LevelDb;

use Bedriox\Protocol\Packet\SignBlockActorData;
use Bedriox\Server\World\BlockEntity\BlockEntityCollection;
use Bedriox\Server\World\BlockEntity\SignBlockEntity;
use Bedriox\Server\World\BlockEntity\SignText;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Storage\LevelDb\PersistentBlockEntityCodec;
use PHPUnit\Framework\TestCase;

final class SignBlockEntityPersistenceTest extends TestCase
{
    public function testSignSidesAndPresentationSurviveStorageRoundTrip(): void
    {
        $position = new BlockPosition(1, 64, 2);
        $sign = new SignBlockEntity(
            $position,
            SignText::fromBlob("front\ntext", 0xff11aa22, true),
            SignText::fromBlob('back', 0xff000000),
            true,
            'session-1',
            true,
            19,
        );
        $codec = new PersistentBlockEntityCodec();

        $decoded = $codec->decode(
            $codec->encode(new BlockEntityCollection(new ChunkPosition(0, 0), [$sign])),
            new ChunkPosition(0, 0),
        )->at($position);

        self::assertInstanceOf(SignBlockEntity::class, $decoded);
        self::assertEquals($sign->front, $decoded->front);
        self::assertEquals($sign->back, $decoded->back);
        self::assertTrue($decoded->waxed);
        self::assertNull($decoded->editorSessionId);
        self::assertNull($decoded->editorFront);
        self::assertNull($decoded->editorRuntimeActorId);
    }

    public function testNetworkProjectionUsesTypedModernSignShape(): void
    {
        $position = new BlockPosition(-4, 70, 9);
        $sign = new SignBlockEntity(
            $position,
            SignText::fromBlob('hello', 0xffabcdef, true),
            SignText::fromBlob('world'),
            editorSessionId: 'editor',
            editorFront: true,
            editorRuntimeActorId: 73,
        );

        $decoded = SignBlockActorData::decode((new PersistentBlockEntityCodec())->encodeNetworkEntity($sign));

        self::assertSame(-4, $decoded->position->x);
        self::assertSame('hello', $decoded->front->text);
        self::assertSame(-5517841, $decoded->front->color);
        self::assertTrue($decoded->front->glowing);
        self::assertSame('world', $decoded->back->text);
        self::assertSame(73, $decoded->lockedForEditingBy);
    }

    public function testHangingSignKeepsItsPersistentAndNetworkIdentity(): void
    {
        $position = new BlockPosition(3, 80, -7);
        $sign = SignBlockEntity::empty($position, hanging: true);
        $codec = new PersistentBlockEntityCodec();

        $decoded = $codec->decode(
            $codec->encode(new BlockEntityCollection(new ChunkPosition(0, -1), [$sign])),
            new ChunkPosition(0, -1),
        )->at($position);

        self::assertInstanceOf(SignBlockEntity::class, $decoded);
        self::assertTrue($decoded->hanging);
        self::assertSame('HangingSign', SignBlockActorData::decode($codec->encodeNetworkEntity($sign))->identifier);
    }
}
