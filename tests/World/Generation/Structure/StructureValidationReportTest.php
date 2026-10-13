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

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Generation\Structure\Catalog\StructureCoverageCatalog;
use Bedriox\Server\World\Generation\Structure\Location\StructureValidationReport;
use PHPUnit\Framework\TestCase;

final class StructureValidationReportTest extends TestCase
{
    public function testSeedZeroReportCoversEveryDeclaredFamilyDimensionAndVariant(): void
    {
        $data = BedrockDataSet::bundled();
        $entries = (new StructureValidationReport(
            0,
            new BlockStateRegistry($data->blockStateRegistry()->states()),
        ))->entries();
        $keys = [];
        foreach ($entries as $entry) {
            $location = $entry->location;
            $key = implode(':', [$location->dimension->value, $location->identifier, $location->variant]);
            self::assertArrayNotHasKey($key, $keys);
            $keys[$key] = true;
            self::assertSame($location->teleportCommand(), $entry->toArray()['teleport']);
        }

        foreach (StructureCoverageCatalog::entries() as $coverage) {
            foreach ($coverage->dimensions as $dimension) {
                self::assertTrue(
                    array_any($entries, static fn($entry): bool =>
                        $entry->location->identifier === $coverage->identifier
                        && $entry->location->dimension === $dimension),
                    "Missing {$coverage->identifier} in {$dimension->value}.",
                );
            }
            foreach ($coverage->variants as $variant) {
                self::assertTrue(
                    array_any($entries, static fn($entry): bool =>
                        $entry->location->identifier === $coverage->identifier
                        && $entry->location->variant === $variant),
                    "Missing {$coverage->identifier}/{$variant}.",
                );
            }
        }
    }
}
