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

namespace Bedriox\Server\Tests\World;

use Bedriox\Server\World\PackedPaletteIndices;
use Bedriox\Server\World\PackedPaletteWords;
use Bedriox\Server\World\SubChunkBlockStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PackedPaletteIndicesTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function widths(): iterable
    {
        foreach ([0, 1, 2, 3, 4, 5, 6, 8] as $bits) {
            yield $bits . '-bit' => [$bits];
        }
    }

    #[DataProvider('widths')]
    public function testRoundTripsEverySupportedWidth(int $bits): void
    {
        $values = '';
        $maximum = $bits === 0 ? 1 : 1 << $bits;
        for ($offset = 0; $offset < 4_096; ++$offset) {
            $values .= chr($offset % $maximum);
        }

        $packed = PackedPaletteIndices::pack($values, $bits);

        self::assertSame(PackedPaletteIndices::bytesFor($bits), strlen($packed));
        self::assertSame($values, PackedPaletteIndices::unpack($packed, $bits));
        foreach ([0, 1, 255, 2_047, 4_095] as $offset) {
            self::assertSame(ord($values[$offset]), PackedPaletteIndices::indexAt($packed, $bits, $offset));
        }
    }

    public function testReplacementDoesNotChangeAdjacentEntries(): void
    {
        $values = str_repeat("\x00", 4_096);
        $packed = PackedPaletteIndices::pack($values, 3);

        $packed = PackedPaletteIndices::replace($packed, 3, 511, 7);

        self::assertSame(0, PackedPaletteIndices::indexAt($packed, 3, 510));
        self::assertSame(7, PackedPaletteIndices::indexAt($packed, 3, 511));
        self::assertSame(0, PackedPaletteIndices::indexAt($packed, 3, 512));
    }

    public function testDirectNetworkProjectionMatchesTheLegacyTwoPassProjection(): void
    {
        foreach ([1, 2, 3, 4, 5, 6, 8] as $bits) {
            $mask = (1 << $bits) - 1;
            $indices = '';
            for ($offset = 0; $offset < SubChunkBlockStorage::BLOCK_COUNT; ++$offset) {
                $indices .= chr(($offset * 13 + 7) & $mask);
            }
            $packed = PackedPaletteIndices::pack($indices, $bits);

            self::assertSame(
                PackedPaletteWords::packNetworkOrder($indices, $bits),
                PackedPaletteWords::packNetworkOrderFromPackedMemory($packed, $bits, $bits),
                "Network projection differs at {$bits} bits.",
            );
        }
    }
}
