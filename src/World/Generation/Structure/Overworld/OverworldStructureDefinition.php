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

namespace Bedriox\Server\World\Generation\Structure\Overworld;

final readonly class OverworldStructureDefinition
{
    /**
     * @param list<string> $variants
     * @param list<string> $biomeTerms
     */
    public function __construct(
        public OverworldStructureFamily $family,
        public int $spacingChunks,
        public int $separationChunks,
        public int $radius,
        public int $salt,
        public bool $underground,
        public bool $locatable,
        public array $variants,
        public array $biomeTerms = [],
    ) {
        if ($spacingChunks < 2 || $separationChunks < 0 || $separationChunks >= $spacingChunks) {
            throw new \InvalidArgumentException('Structure spacing and separation are invalid.');
        }
        if ($radius < 1 || $radius > 160) {
            throw new \InvalidArgumentException('Structure radius must be between 1 and 160 blocks.');
        }
        if ($variants === []) {
            throw new \InvalidArgumentException('A structure definition requires at least one variant.');
        }
    }

    public function supportsBiome(string $biome): bool
    {
        if ($this->biomeTerms === []) {
            return true;
        }
        foreach ($this->biomeTerms as $term) {
            if (str_contains($biome, $term)) {
                return true;
            }
        }

        return false;
    }
}
