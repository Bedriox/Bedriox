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

namespace Bedriox\Server\World\Generation\Biome;

use Bedriox\Server\World\Biome;
use InvalidArgumentException;
use RuntimeException;

/** Generated seven-dimensional nearest-range biome target catalog from the verified native diagnostic. */
final class OverworldBiomeTargetCatalog
{
    private const string MAGIC = 'BDXBT001';
    private const int HEADER_SIZE = 44;
    private const int RECORD_SIZE = 30;
    private const int TARGET_COUNT = 7_594;
    private const int MAXIMUM_BYTES = 512 * 1024;
    private const string SHA256 = '98a72e647cd0a3a21a141005eff4945064c03423dbc2bfd6b1e13d2d911905e2';

    /** @var list<array{int, int, int, int, int, int, int, int, int, int, int, int, int, int, int}> */
    private array $targets;
    /** @var array<int, Biome> */
    private array $biomesById = [];
    /** @var array<string, Biome> */
    private array $resolved = [];

    /** @param array<int, string> $identifiersById */
    public function __construct(array $identifiersById)
    {
        $path = __DIR__ . '/overworld-biome-targets.bin';
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || strlen($bytes) > self::MAXIMUM_BYTES
            || hash('sha256', $bytes) !== self::SHA256 || substr($bytes, 0, 8) !== self::MAGIC) {
            throw new RuntimeException('Generated overworld biome-target catalog is missing or invalid.');
        }
        $count = unpack('Vcount', substr($bytes, 8, 4));
        if (!is_array($count) || ($count['count'] ?? null) !== self::TARGET_COUNT
            || strlen($bytes) !== self::HEADER_SIZE + self::TARGET_COUNT * self::RECORD_SIZE) {
            throw new RuntimeException('Generated overworld biome-target catalog has invalid geometry.');
        }
        $targets = [];
        for ($offset = self::HEADER_SIZE; $offset < strlen($bytes); $offset += self::RECORD_SIZE) {
            $values = unpack('v15', substr($bytes, $offset, self::RECORD_SIZE));
            if (!is_array($values)) {
                throw new RuntimeException('Generated overworld biome-target catalog is truncated.');
            }
            $raw = array_map(
                static fn(mixed $value): int => is_int($value)
                    ? $value
                    : throw new RuntimeException('Generated overworld biome-target record contains a non-integer.'),
                array_values($values),
            );
            $signed = static fn(int $value): int => $value < 0x8000 ? $value : $value - 0x1_0000;
            $target = [
                $raw[0], $signed($raw[1]), $signed($raw[2]), $signed($raw[3]), $signed($raw[4]),
                $signed($raw[5]), $signed($raw[6]), $signed($raw[7]), $signed($raw[8]), $signed($raw[9]),
                $signed($raw[10]), $signed($raw[11]), $signed($raw[12]), $signed($raw[13]), $signed($raw[14]),
            ];
            $targets[] = $target;
            $biomeId = $target[0];
            $identifier = $identifiersById[$biomeId] ?? null;
            if (!is_string($identifier)) {
                throw new InvalidArgumentException("Biome target references unadmitted runtime ID {$biomeId}.");
            }
            $this->biomesById[$biomeId] ??= new Biome($identifier);
        }
        $this->targets = $targets;
    }

    public function nearest(
        int $temperature,
        int $humidity,
        int $continentalness,
        int $erosion,
        int $depth,
        int $weirdness,
        int $offset,
    ): Biome {
        $key = $temperature . ':' . $humidity . ':' . $continentalness . ':' . $erosion . ':'
            . $depth . ':' . $weirdness . ':' . $offset;
        $cached = $this->resolved[$key] ?? null;
        if ($cached instanceof Biome) {
            return $cached;
        }
        $bestId = -1;
        $bestDistance = PHP_INT_MAX;
        foreach ($this->targets as $target) {
            $delta = $temperature < $target[1] ? $target[1] - $temperature
                : ($temperature > $target[2] ? $temperature - $target[2] : 0);
            $distance = $delta * $delta;
            $delta = $humidity < $target[3] ? $target[3] - $humidity
                : ($humidity > $target[4] ? $humidity - $target[4] : 0);
            $distance += $delta * $delta;
            $delta = $continentalness < $target[5] ? $target[5] - $continentalness
                : ($continentalness > $target[6] ? $continentalness - $target[6] : 0);
            $distance += $delta * $delta;
            $delta = $erosion < $target[7] ? $target[7] - $erosion
                : ($erosion > $target[8] ? $erosion - $target[8] : 0);
            $distance += $delta * $delta;
            $delta = $depth < $target[9] ? $target[9] - $depth : ($depth > $target[10] ? $depth - $target[10] : 0);
            $distance += $delta * $delta;
            $delta = $weirdness < $target[11] ? $target[11] - $weirdness
                : ($weirdness > $target[12] ? $weirdness - $target[12] : 0);
            $distance += $delta * $delta;
            $delta = $offset < $target[13] ? $target[13] - $offset
                : ($offset > $target[14] ? $offset - $target[14] : 0);
            $distance += $delta * $delta;
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestId = $target[0];
            }
        }

        $biome = $this->biomesById[$bestId]
            ?? throw new RuntimeException('Overworld biome target catalog did not resolve a biome.');
        if (count($this->resolved) >= 32_768) {
            $this->resolved = [];
        }

        return $this->resolved[$key] = $biome;
    }
}
