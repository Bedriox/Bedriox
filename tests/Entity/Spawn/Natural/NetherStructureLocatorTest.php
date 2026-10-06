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

namespace Bedriox\Server\Tests\Entity\Spawn\Natural;

use Bedriox\Server\Entity\Spawn\Natural\NetherStructureLocator;
use Bedriox\Server\Entity\Spawn\Natural\NetherStructureType;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Generation\SeededNoise;
use PHPUnit\Framework\TestCase;

final class NetherStructureLocatorTest extends TestCase
{
    public function testOnlyGeneratedStructureFootprintsOwnSpawnPositions(): void
    {
        $seed = 12_345;
        $noise = new SeededNoise($seed ^ 0x4e455448);
        $regionX = 0;
        $regionZ = 0;
        for ($x = -4; $x <= 4; ++$x) {
            for ($z = -4; $z <= 4; ++$z) {
                if ($noise->chance($x, 0, $z, 1_701, 3) === 0) {
                    $regionX = $x;
                    $regionZ = $z;
                    break 2;
                }
            }
        }
        $centerX = $regionX * 384 + 96 + $noise->chance($regionX, 1, $regionZ, 1_709, 192);
        $centerZ = $regionZ * 384 + 96 + $noise->chance($regionX, 2, $regionZ, 1_717, 192);
        $baseY = 48 + $noise->chance($regionX, 3, $regionZ, 1_723, 23);
        $expected = $noise->chance($regionX, 4, $regionZ, 1_727, 4) === 0
            ? NetherStructureType::BASTION
            : NetherStructureType::FORTRESS;
        $locator = new NetherStructureLocator($seed);

        self::assertSame($expected, $locator->at(new Position($centerX + 0.5, $baseY + 1.0, $centerZ + 0.5)));
        $provenance = $locator->locate(new Position($centerX + 0.5, $baseY + 1.0, $centerZ + 0.5));
        self::assertNotNull($provenance);
        self::assertSame($regionX, $provenance->regionX);
        self::assertSame($regionZ, $provenance->regionZ);
        self::assertContains(
            $provenance->key(),
            array_map(
                static fn($structure): string => $structure->key(),
                $locator->intersectingChunk(new ChunkPosition(
                    (int) floor($centerX / 16.0),
                    (int) floor($centerZ / 16.0),
                )),
            ),
        );
        self::assertNull($locator->at(new Position($centerX + 20.5, $baseY + 1.0, $centerZ + 20.5)));
        self::assertNull($locator->at(new Position($centerX + 0.5, $baseY + 2.0, $centerZ + 0.5)));
    }
}
