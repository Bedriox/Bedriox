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

namespace Bedriox\Server\Tests\Persistence\World;

use Bedriox\Server\Persistence\World\WorldChunkLoadPayloadCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorldChunkLoadPayloadCodecTest extends TestCase
{
    public function testTerrainAndOptionalEntityPayloadRoundTripExactly(): void
    {
        $codec = new WorldChunkLoadPayloadCodec();

        self::assertSame(
            ['chunk' => 'terrain', 'entities' => null],
            $codec->decode($codec->encode('terrain', null)),
        );
        self::assertSame(
            ['chunk' => 'terrain', 'entities' => "entities\x00snapshot"],
            $codec->decode($codec->encode('terrain', "entities\x00snapshot")),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function malformedPayloads(): iterable
    {
        yield 'empty' => [''];
        yield 'wrong magic' => ["WRONG!\x00\x00\x00\x01\x00\x00\x00\x00x"];
        yield 'truncated body' => ["BXWL\x00\x01\x00\x00\x00\x08\x00\x00\x00\x00tiny"];
        yield 'trailing bytes' => [(new WorldChunkLoadPayloadCodec())->encode('terrain', null) . 'x'];
    }

    #[DataProvider('malformedPayloads')]
    public function testMalformedFramingFailsClosed(string $payload): void
    {
        $this->expectException(RuntimeException::class);
        (new WorldChunkLoadPayloadCodec())->decode($payload);
    }
}
