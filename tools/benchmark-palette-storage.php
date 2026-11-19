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

use Bedriox\Server\World\PackedPaletteIndices;
use Bedriox\Server\World\PackedPaletteWords;
use Bedriox\Server\World\SubChunkBlockStorage;

require dirname(__DIR__) . '/vendor/autoload.php';

$iterations = filter_var($argv[1] ?? 200, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 10_000],
]);
if (!is_int($iterations)) {
    fwrite(STDERR, "Usage: php tools/benchmark-palette-storage.php [iterations:1-10000]\n");
    exit(2);
}

$results = [];
foreach ([2, 4, 8, 16, 32, 64, 256] as $paletteSize) {
    $bits = PackedPaletteWords::bitsForPaletteSize($paletteSize);
    $indices = '';
    for ($offset = 0; $offset < SubChunkBlockStorage::BLOCK_COUNT; ++$offset) {
        $indices .= chr($offset % $paletteSize);
    }
    $started = hrtime(true);
    for ($iteration = 0; $iteration < $iterations; ++$iteration) {
        $packed = PackedPaletteIndices::pack($indices, $bits, validate: false);
    }
    $packNanoseconds = hrtime(true) - $started;
    $checksum = 0;
    $started = hrtime(true);
    for ($iteration = 0; $iteration < $iterations; ++$iteration) {
        for ($offset = 0; $offset < SubChunkBlockStorage::BLOCK_COUNT; ++$offset) {
            $checksum += PackedPaletteIndices::trustedIndexAt($packed, $bits, $offset);
        }
    }
    $lookupNanoseconds = hrtime(true) - $started;
    $started = hrtime(true);
    for ($iteration = 0; $iteration < $iterations; ++$iteration) {
        $legacyNetworkWords = PackedPaletteWords::packNetworkOrder(
            PackedPaletteIndices::unpack($packed, $bits),
            $bits,
        );
    }
    $legacyProjectionNanoseconds = hrtime(true) - $started;
    $started = hrtime(true);
    for ($iteration = 0; $iteration < $iterations; ++$iteration) {
        $directNetworkWords = PackedPaletteWords::packNetworkOrderFromPackedMemory($packed, $bits, $bits);
    }
    $directProjectionNanoseconds = hrtime(true) - $started;
    if ($directNetworkWords !== $legacyNetworkWords) {
        throw new RuntimeException('Direct network projection differs from the legacy projection.');
    }
    $packedBytes = strlen($packed);
    $results[] = [
        'palette_size' => $paletteSize,
        'bits_per_entry' => $bits,
        'legacy_index_bytes' => SubChunkBlockStorage::BLOCK_COUNT,
        'packed_index_bytes' => $packedBytes,
        'bytes_saved' => SubChunkBlockStorage::BLOCK_COUNT - $packedBytes,
        'percent_saved' => round(
            (SubChunkBlockStorage::BLOCK_COUNT - $packedBytes) * 100 / SubChunkBlockStorage::BLOCK_COUNT,
            2,
        ),
        'pack_microseconds' => round($packNanoseconds / $iterations / 1_000, 2),
        'lookup_nanoseconds' => round(
            $lookupNanoseconds / ($iterations * SubChunkBlockStorage::BLOCK_COUNT),
            2,
        ),
        'legacy_projection_microseconds' => round($legacyProjectionNanoseconds / $iterations / 1_000, 2),
        'direct_projection_microseconds' => round($directProjectionNanoseconds / $iterations / 1_000, 2),
        'projection_percent_faster' => round(
            ($legacyProjectionNanoseconds - $directProjectionNanoseconds) * 100 / $legacyProjectionNanoseconds,
            2,
        ),
        'checksum' => $checksum,
    ];
}

fwrite(STDOUT, json_encode([
    'iterations' => $iterations,
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
