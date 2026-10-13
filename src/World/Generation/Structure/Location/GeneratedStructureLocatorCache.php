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

namespace Bedriox\Server\World\Generation\Structure\Location;

use Bedriox\Server\World\Block\BlockStateRegistry;

/** Bounded per-loaded-world cache for the immutable locator samplers used by `/locate`. */
final class GeneratedStructureLocatorCache
{
    /** @var array<string, GeneratedStructureLocator> */
    private array $locators = [];

    /** @var list<string> */
    private array $order = [];

    public function __construct(private readonly BlockStateRegistry $states) {}

    public function get(string $worldId, int $loadGeneration, int $seed): GeneratedStructureLocator
    {
        $key = $worldId . ':' . $loadGeneration . ':' . $seed;
        $locator = $this->locators[$key] ?? null;
        if ($locator !== null) {
            return $locator;
        }
        $locator = new GeneratedStructureLocator($seed, $this->states);
        $this->locators[$key] = $locator;
        $this->order[] = $key;
        if (count($this->order) > 16) {
            $expired = array_shift($this->order);
            unset($this->locators[$expired]);
        }

        return $locator;
    }
}
