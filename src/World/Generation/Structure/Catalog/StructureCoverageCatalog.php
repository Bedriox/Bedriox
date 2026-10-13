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

/** Structures intentionally owned by the built-in generator. */
final class StructureCoverageCatalog
{
    /** @return non-empty-list<StructureCoverageEntry> */
    public static function entries(): array
    {
        $overworld = [DimensionType::OVERWORLD];
        $nether = [DimensionType::NETHER];
        $end = [DimensionType::END];

        return [
            new StructureCoverageEntry('village', StructureCategory::STRUCTURE, $overworld, ['plains', 'desert', 'savanna', 'taiga', 'snowy']),
            new StructureCoverageEntry('dungeon', StructureCategory::STRUCTURE, $overworld, ['standard']),
            new StructureCoverageEntry('mineshaft', StructureCategory::STRUCTURE, $overworld, ['oak', 'badlands']),
            new StructureCoverageEntry('stronghold', StructureCategory::STRUCTURE, $overworld, ['standard']),
            new StructureCoverageEntry('nether_fortress', StructureCategory::STRUCTURE, $nether, ['standard']),
            new StructureCoverageEntry('bastion_remnant', StructureCategory::STRUCTURE, $nether, ['bridge', 'hoglin_stable', 'housing', 'treasure']),
            new StructureCoverageEntry('end_city', StructureCategory::STRUCTURE, $end, ['standard']),
            new StructureCoverageEntry('end_ship', StructureCategory::STRUCTURE, $end, ['standard']),
            new StructureCoverageEntry('end_exit_portal', StructureCategory::INFRASTRUCTURE, $end, ['standard']),
            new StructureCoverageEntry('obsidian_pillar', StructureCategory::INFRASTRUCTURE, $end, ['caged', 'uncaged']),
            new StructureCoverageEntry('end_gateway', StructureCategory::INFRASTRUCTURE, $end, ['standard']),
            new StructureCoverageEntry('end_spawn_platform', StructureCategory::INFRASTRUCTURE, $end, ['standard']),
        ];
    }

    /** @return array<string, StructureCoverageEntry> */
    public static function indexed(): array
    {
        $indexed = [];
        foreach (self::entries() as $entry) {
            if (isset($indexed[$entry->identifier])) {
                throw new \LogicException("Duplicate structure coverage identifier {$entry->identifier}.");
            }
            $indexed[$entry->identifier] = $entry;
        }

        return $indexed;
    }
}
