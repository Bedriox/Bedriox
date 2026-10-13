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

namespace Bedriox\Server\World\Generation\Structure\Catalog;

use Bedriox\Api\World\DimensionType;

final readonly class StructureCoverageEntry
{
    /**
     * @param non-empty-list<DimensionType> $dimensions
     * @param non-empty-list<string>         $variants
     */
    public function __construct(
        public string $identifier,
        public StructureCategory $category,
        public array $dimensions,
        public array $variants,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $identifier) !== 1) {
            throw new \InvalidArgumentException('Structure coverage identifiers must be bounded lowercase names.');
        }
        if (count(array_unique(array_map(static fn(DimensionType $dimension): string => $dimension->value, $dimensions))) !== count($dimensions)
            || count(array_unique($variants)) !== count($variants)) {
            throw new \InvalidArgumentException('Structure coverage dimensions and variants must be unique.');
        }
        foreach ($variants as $variant) {
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $variant) !== 1) {
                throw new \InvalidArgumentException('Structure variant identifiers must be bounded lowercase names.');
            }
        }
    }
}
