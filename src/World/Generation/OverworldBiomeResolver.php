<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Generation;

use Bedriox\Server\World\Biome;

/** Resolves column biomes from terrain and climate without chunk-local state. */
final class OverworldBiomeResolver
{
    public function resolve(OverworldTerrainSample $sample): Biome
    {
        $height = $sample->surfaceHeight;
        $climate = $sample->climate;
        if ($height <= 53) {
            return Biome::deepOcean();
        }
        if ($height < OverworldTerrainSampler::SEA_LEVEL - 1) {
            return Biome::ocean();
        }
        if ($sample->riverStrength >= 420 && $height <= OverworldTerrainSampler::SEA_LEVEL + 1) {
            return Biome::river();
        }
        if ($height <= OverworldTerrainSampler::SEA_LEVEL && $climate->continentalness < 1_500) {
            return $sample->slope >= 5 ? Biome::stonyShore() : Biome::beach();
        }
        if ($height >= 128) {
            return Biome::jaggedPeaks();
        }
        if ($height >= 99 || ($height >= 86 && $climate->temperature < -9_000)) {
            return Biome::snowySlopes();
        }
        if ($height >= 83 || $sample->slope >= 8) {
            return Biome::hills();
        }
        if ($climate->temperature < -10_500) {
            return Biome::snowyPlains();
        }
        if ($climate->temperature < -3_500) {
            return Biome::taiga();
        }
        if ($climate->temperature > 8_000 && $climate->humidity < 0) {
            return Biome::desert();
        }
        if ($climate->temperature > 7_000 && $climate->humidity < 3_500) {
            return Biome::savanna();
        }
        if ($climate->humidity > 5_000) {
            return $climate->detail > 0 ? Biome::birchForest() : Biome::forest();
        }

        return Biome::plains();
    }
}
