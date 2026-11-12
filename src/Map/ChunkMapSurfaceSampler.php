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

namespace Bedriox\Server\Map;

use Bedriox\Data\BlockPropertyRegistry;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\World;

/** Revision-aware, loaded-chunk-only map surface cache with vanilla-style slope and water shading. */
final class ChunkMapSurfaceSampler implements MapTerrainSampler
{
    private const int MAXIMUM_CACHED_CHUNKS = 256;

    /** @var array<string, array{revision: int, samples: array<int, array{color: string, height: int, water: bool, depth: int}>, used: int}> */
    private array $chunks = [];
    private int $usage = 0;
    private readonly int $airState;

    public function __construct(
        private readonly World $world,
        private readonly BlockStateRegistry $states,
        private readonly BlockPropertyRegistry $properties,
    ) {
        $this->airState = $states->internalId(CanonicalBlockState::from('minecraft:air'))->value;
    }

    public function colorAt(int $x, int $z, int $scale = 1): string
    {
        if (!in_array($scale, [1, 2, 4, 8, 16], true)) {
            throw new \InvalidArgumentException('Map terrain scale is unsupported.');
        }
        $sample = $this->areaSample($x, $z, $scale);
        if ($sample === null) {
            return "\0\0\0\0";
        }
        $previous = $this->areaSample($x, $z - $scale, $scale);
        $parity = (intdiv($x, $scale) + intdiv($z, $scale)) & 1;
        $difference = $sample['water']
            ? $sample['depth'] * 0.1 + $parity * 0.2
            : ($sample['height'] - ($previous['height'] ?? $sample['height'])) * 4 / ($scale + 4)
                + ($parity - 0.5) * 0.4;
        $brightness = $sample['water']
            ? ($difference < 0.5 ? 255 : ($difference > 0.9 ? 180 : 220))
            : ($difference > 0.6 ? 255 : ($difference < -0.6 ? 180 : 220));

        return self::shade($sample['color'], $brightness);
    }

    public function invalidateColumn(int $x, int $z): void
    {
        $position = new ChunkPosition((int) floor($x / 16), (int) floor($z / 16));
        $key = $position->key();
        $entry = $this->chunks[$key] ?? null;
        $chunk = $this->world->loadedChunk($position);
        if ($entry === null || $chunk === null) {
            return;
        }
        $localX = (($x % 16) + 16) % 16;
        $localZ = (($z % 16) + 16) % 16;
        unset($entry['samples'][($localZ << 4) | $localX]);
        $entry['revision'] = $chunk->revision;
        $entry['used'] = ++$this->usage;
        $this->chunks[$key] = $entry;
    }

    /** @return null|array{color: string, height: float, water: bool, depth: float} */
    private function areaSample(int $x, int $z, int $scale): ?array
    {
        $counts = [];
        $colors = [];
        $height = 0;
        $depth = 0;
        $water = 0;
        for ($offsetX = 0; $offsetX < $scale; ++$offsetX) {
            for ($offsetZ = 0; $offsetZ < $scale; ++$offsetZ) {
                $sample = $this->columnSample($x + $offsetX, $z + $offsetZ);
                if ($sample === null) {
                    return null;
                }
                $key = bin2hex($sample['color']);
                $colors[$key] = $sample['color'];
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                $height += $sample['height'];
                $depth += $sample['depth'];
                $water += (int) $sample['water'];
            }
        }
        arsort($counts);
        $dominant = array_key_first($counts);
        $area = $scale * $scale;

        return $dominant === null ? null : [
            'color' => $colors[$dominant],
            'height' => $height / $area,
            'water' => $water > intdiv($area, 2),
            'depth' => $depth / $area,
        ];
    }

    /** @return null|array{color: string, height: int, water: bool, depth: int} */
    private function columnSample(int $x, int $z): ?array
    {
        $position = new ChunkPosition((int) floor($x / 16), (int) floor($z / 16));
        $chunk = $this->world->loadedChunk($position);
        if ($chunk === null) {
            return null;
        }
        $key = $position->key();
        $entry = $this->chunks[$key] ?? null;
        if ($entry === null || $entry['revision'] !== $chunk->revision) {
            $entry = ['revision' => $chunk->revision, 'samples' => [], 'used' => ++$this->usage];
        } else {
            $entry['used'] = ++$this->usage;
        }
        $localX = (($x % 16) + 16) % 16;
        $localZ = (($z % 16) + 16) % 16;
        $column = ($localZ << 4) | $localX;
        $sample = $entry['samples'][$column] ?? null;
        if ($sample === null) {
            $sample = $this->resolveColumn($chunk, $localX, $localZ);
            if ($sample === null) {
                return null;
            }
            $entry['samples'][$column] = $sample;
        }
        $this->chunks[$key] = $entry;
        $this->trim();

        return $sample;
    }

    /** @return null|array{color: string, height: int, water: bool, depth: int} */
    private function resolveColumn(Chunk $chunk, int $localX, int $localZ): ?array
    {
        for ($y = Chunk::MAX_Y; $y >= Chunk::MIN_Y; --$y) {
            $state = $chunk->blockStateAt($localX, $y, $localZ);
            if ($state->value === $this->airState) {
                continue;
            }
            $canonical = $this->states->state($state);
            $properties = $this->properties->propertiesForState($canonical);
            $hex = $properties->mapColor();
            if (str_ends_with($hex, '00')) {
                $hex = self::tintedMapColor($properties->tintMethod());
                if ($hex === null) {
                    continue;
                }
            }
            $rgba = hex2bin(substr($hex, 1));
            if (!is_string($rgba) || strlen($rgba) !== 4) {
                continue;
            }
            $water = $properties->tintMethod() === 'Water';
            $depth = 0;
            if ($water) {
                for ($below = $y; $below >= Chunk::MIN_Y; --$below) {
                    $belowState = $chunk->blockStateAt($localX, $below, $localZ);
                    $belowProperties = $this->properties->propertiesForState($this->states->state($belowState));
                    if ($belowProperties->tintMethod() !== 'Water') {
                        break;
                    }
                    ++$depth;
                }
            }

            return ['color' => $rgba, 'height' => $y, 'water' => $water, 'depth' => $depth];
        }

        return null;
    }

    private static function tintedMapColor(string $tintMethod): ?string
    {
        return match ($tintMethod) {
            'Grass' => '#7fb238ff',
            'Water' => '#4040ffff',
            'DefaultFoliage', 'BirchFoliage', 'EvergreenFoliage' => '#007c00ff',
            'RedStoneWire' => '#ff0000ff',
            default => null,
        };
    }

    private static function shade(string $rgba, int $brightness): string
    {
        return chr(intdiv(ord($rgba[0]) * $brightness, 255))
            . chr(intdiv(ord($rgba[1]) * $brightness, 255))
            . chr(intdiv(ord($rgba[2]) * $brightness, 255))
            . $rgba[3];
    }

    private function trim(): void
    {
        if (count($this->chunks) <= self::MAXIMUM_CACHED_CHUNKS) {
            return;
        }
        $oldestKey = null;
        $oldestUse = PHP_INT_MAX;
        foreach ($this->chunks as $key => $entry) {
            if ($entry['used'] < $oldestUse) {
                $oldestKey = $key;
                $oldestUse = $entry['used'];
            }
        }
        if ($oldestKey !== null) {
            unset($this->chunks[$oldestKey]);
        }
    }
}
