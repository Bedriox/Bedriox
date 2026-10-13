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

namespace Bedriox\Server\Tests\World\Generation\Structure;

use Bedriox\Server\World\Generation\Structure\Catalog\StructureCategory;
use Bedriox\Server\World\Generation\Structure\Catalog\StructureCoverageCatalog;
use PHPUnit\Framework\TestCase;

final class StructureCoverageCatalogTest extends TestCase
{
    public function testNarrowBuiltInContractIsUniqueAndExcludesDeferredFamilies(): void
    {
        $entries = StructureCoverageCatalog::indexed();

        self::assertCount(12, $entries);
        self::assertArrayNotHasKey('bonus_chest', $entries);
        foreach ([
            'village', 'dungeon', 'mineshaft', 'stronghold', 'nether_fortress', 'bastion_remnant', 'end_city', 'end_ship',
            'end_exit_portal', 'obsidian_pillar', 'end_gateway', 'end_spawn_platform',
        ] as $identifier) {
            self::assertArrayHasKey($identifier, $entries);
        }
        foreach (['abandoned_camp', 'woodland_mansion', 'ocean_monument', 'ancient_city', 'trial_chambers'] as $identifier) {
            self::assertArrayNotHasKey($identifier, $entries);
        }
    }

    public function testEveryEntryDeclaresAtLeastOneVariantAndDimension(): void
    {
        foreach (StructureCoverageCatalog::entries() as $entry) {
            self::assertNotEmpty($entry->dimensions, $entry->identifier);
            self::assertNotEmpty($entry->variants, $entry->identifier);
            self::assertContains($entry->category, StructureCategory::cases());
        }
    }
}
