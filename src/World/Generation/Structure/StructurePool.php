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

namespace Bedriox\Server\World\Generation\Structure;

use InvalidArgumentException;

final readonly class StructurePool
{
    private int $totalWeight;

    /** @param list<StructurePoolEntry> $entries */
    public function __construct(public array $entries)
    {
        if ($entries === [] || count($entries) > 1_024) {
            throw new InvalidArgumentException('Structure pool must contain between 1 and 1024 entries.');
        }
        $total = 0;
        foreach ($entries as $entry) {
            $total += $entry->weight;
            if ($total > 1_000_000_000) {
                throw new InvalidArgumentException('Structure pool total weight exceeds its bound.');
            }
        }
        $this->totalWeight = $total;
    }

    public function choose(int $worldSeed, string $discriminator): StructureTemplate
    {
        $digest = hash('sha256', $worldSeed . ':' . $discriminator, true);
        $remaining = self::unsignedInt($digest) % $this->totalWeight;
        foreach ($this->entries as $entry) {
            if ($remaining < $entry->weight) {
                return $entry->template;
            }
            $remaining -= $entry->weight;
        }

        throw new \LogicException('Weighted structure-pool selection exhausted valid entries.');
    }

    private static function unsignedInt(string $bytes): int
    {
        return ord($bytes[0]) | (ord($bytes[1]) << 8) | (ord($bytes[2]) << 16) | (ord($bytes[3]) << 24);
    }
}
