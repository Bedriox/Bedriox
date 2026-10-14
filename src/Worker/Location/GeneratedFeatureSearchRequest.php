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

namespace Bedriox\Server\Worker\Location;

use Bedriox\Api\World\DimensionType;

final readonly class GeneratedFeatureSearchRequest
{
    /** @param list<string> $excludedLocations */
    public function __construct(
        public GeneratedFeatureSearchMode $mode,
        public DimensionType $dimension,
        public string $identifier,
        public int $seed,
        public int $originX,
        public int $originY,
        public int $originZ,
        public array $excludedLocations = [],
    ) {
        if (!preg_match('/^minecraft:[a-z0-9_]{1,96}$/D', $identifier)
            || $originX < -30_000_000 || $originX > 30_000_000
            || $originY < -2_048 || $originY > 2_048
            || $originZ < -30_000_000 || $originZ > 30_000_000
            || count($excludedLocations) > 32) {
            throw new \InvalidArgumentException('Generated feature search request is outside its bounds.');
        }
        foreach ($excludedLocations as $location) {
            if (preg_match('/^-?\d+:-?\d+$/D', $location) !== 1 || strlen($location) > 32) {
                throw new \InvalidArgumentException('Generated feature search exclusion is invalid.');
            }
        }
    }

    public function excluding(int $x, int $z): self
    {
        $excluded = $this->excludedLocations;
        $chunkX = $x >= 0 ? intdiv($x, 16) : -intdiv(-$x + 15, 16);
        $chunkZ = $z >= 0 ? intdiv($z, 16) : -intdiv(-$z + 15, 16);
        $excluded[] = $chunkX . ':' . $chunkZ;

        return new self(
            $this->mode,
            $this->dimension,
            $this->identifier,
            $this->seed,
            $this->originX,
            $this->originY,
            $this->originZ,
            array_values(array_unique($excluded)),
        );
    }
}
