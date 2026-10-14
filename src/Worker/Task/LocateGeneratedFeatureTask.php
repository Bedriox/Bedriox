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

namespace Bedriox\Server\Worker\Task;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Worker\Location\GeneratedFeatureSearchCodec;
use Bedriox\Server\Worker\Location\GeneratedFeatureSearchMode;
use Bedriox\Server\Worker\WorkerTaskHandler;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Generation\Structure\Location\GeneratedBiomeLocator;
use Bedriox\Server\World\Generation\Structure\Location\GeneratedStructureLocator;
use Bedriox\Server\World\Generation\Structure\Location\LocatedFeature;

final class LocateGeneratedFeatureTask implements WorkerTaskHandler
{
    private const int MAXIMUM_CACHED_SEEDS = 8;

    private ?BlockStateRegistry $states = null;
    /** @var array<int, GeneratedBiomeLocator> */
    private array $biomeLocators = [];
    /** @var array<int, GeneratedStructureLocator> */
    private array $structureLocators = [];
    /** @var list<int> */
    private array $seedOrder = [];

    public function execute(string $payload): string
    {
        $codec = new GeneratedFeatureSearchCodec();
        $request = $codec->decodeRequest($payload);
        $states = $this->states ??= new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        if ($request->mode === GeneratedFeatureSearchMode::BIOME) {
            $locator = $this->biomeLocators[$request->seed] ??= new GeneratedBiomeLocator($request->seed, $states);
            $this->remember($request->seed);
            $feature = $locator->locate(
                $request->dimension,
                $request->identifier,
                $request->originX,
                $request->originY,
                $request->originZ,
            );
        } else {
            $locator = $this->structureLocators[$request->seed] ??= new GeneratedStructureLocator($request->seed, $states);
            $this->remember($request->seed);
            $excluded = array_fill_keys($request->excludedLocations, true);
            $location = $locator->locate(
                $request->dimension,
                substr($request->identifier, strlen('minecraft:')),
                $request->originX,
                $request->originZ,
                static function (int $x, int $z) use ($excluded): bool {
                    $chunkX = $x >= 0 ? intdiv($x, 16) : -intdiv(-$x + 15, 16);
                    $chunkZ = $z >= 0 ? intdiv($z, 16) : -intdiv(-$z + 15, 16);

                    return !isset($excluded[$chunkX . ':' . $chunkZ]);
                },
            );
            $feature = $location === null ? null : new LocatedFeature(
                $request->identifier,
                $location->x,
                $location->y,
                $location->z,
            );
        }

        return $codec->encodeResult($feature);
    }

    private function remember(int $seed): void
    {
        if (in_array($seed, $this->seedOrder, true)) {
            return;
        }
        $this->seedOrder[] = $seed;
        if (count($this->seedOrder) > self::MAXIMUM_CACHED_SEEDS) {
            $expired = array_shift($this->seedOrder);
            unset($this->biomeLocators[$expired], $this->structureLocators[$expired]);
        }
    }
}
