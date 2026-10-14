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

namespace Bedriox\Server\Tests\World\Generation\Ecology;

use Bedriox\Server\World\Generation\Ecology\OverworldEcologyContext;
use Bedriox\Server\World\Generation\Ecology\OverworldEcologyField;
use Bedriox\Server\World\Generation\Ecology\OverworldEcologyZone;
use Bedriox\Server\World\Generation\SeededNoise;
use PHPUnit\Framework\TestCase;

final class OverworldEcologyFieldTest extends TestCase
{
    public function testHardExclusionsProtectWaterStructuresCavesAndCliffs(): void
    {
        $field = new OverworldEcologyField(new SeededNoise(72_211));
        foreach ([
            self::context(waterDepth: 1),
            self::context(slope: 7),
            self::context(distanceToStructure: 4),
            self::context(distanceToCaveEntrance: 3),
        ] as $context) {
            $sample = $field->sample($context);
            self::assertSame(OverworldEcologyZone::Excluded, $sample->zone);
            self::assertSame(0, $sample->densityPermille);
        }
    }

    public function testDensityChangesContinuouslyAcrossOrdinaryTerrain(): void
    {
        $field = new OverworldEcologyField(new SeededNoise(72_211));
        $previous = null;
        for ($x = -256; $x <= 256; ++$x) {
            $sample = $field->sample(self::context(x: $x));
            if ($previous !== null) {
                self::assertLessThanOrEqual(30, abs($sample->densityPermille - $previous));
            }
            $previous = $sample->densityPermille;
        }
    }

    public function testExclusionThresholdsDoNotLeakTreesIntoWaterOrCliffs(): void
    {
        $field = new OverworldEcologyField(new SeededNoise(72_211));
        $ordinary = $field->sample(self::context(x: 54, z: -81, slope: 1));
        $steepButPlantable = $field->sample(self::context(x: 54, z: -81, slope: 6));
        $cliff = $field->sample(self::context(x: 54, z: -81, slope: 7));
        $submerged = $field->sample(self::context(x: 54, z: -81, waterDepth: 1));

        self::assertGreaterThan(0, $ordinary->densityPermille);
        self::assertGreaterThan(0, $steepButPlantable->densityPermille);
        self::assertLessThan($ordinary->densityPermille, $steepButPlantable->densityPermille);
        self::assertSame(OverworldEcologyZone::Excluded, $cliff->zone);
        self::assertSame(0, $cliff->densityPermille);
        self::assertSame(OverworldEcologyZone::Excluded, $submerged->zone);
        self::assertSame(0, $submerged->densityPermille);
    }

    public function testNeighboringHabitatMasksAreDeterministicAndContinuous(): void
    {
        $field = new OverworldEcologyField(new SeededNoise(917_443));
        $previous = $field->sample(self::context(x: -129, z: 207));
        for ($x = -128; $x <= 128; ++$x) {
            $current = $field->sample(self::context(x: $x, z: 207));
            self::assertEquals($current, $field->sample(self::context(x: $x, z: 207)));
            self::assertLessThanOrEqual(4_500, abs($current->habitatMask - $previous->habitatMask));
            $previous = $current;
        }
    }

    public function testRegionalFieldProducesClearingsWoodlandAndForestCores(): void
    {
        $field = new OverworldEcologyField(new SeededNoise(29_771));
        $zones = [];
        for ($z = -2_048; $z <= 2_048; $z += 32) {
            for ($x = -2_048; $x <= 2_048; $x += 32) {
                $zones[$field->sample(self::context(x: $x, z: $z))->zone->name] = true;
            }
        }

        self::assertArrayHasKey(OverworldEcologyZone::Clearing->name, $zones);
        self::assertArrayHasKey(OverworldEcologyZone::Woodland->name, $zones);
        self::assertArrayHasKey(OverworldEcologyZone::Core->name, $zones);
    }

    public function testPlacementRollAndAcceptanceAreDeterministic(): void
    {
        $field = new OverworldEcologyField(new SeededNoise(29_771));
        $sample = $field->sample(self::context(x: 91, z: -203));
        $roll = $field->placementRoll(91, -203);

        self::assertSame($roll, $field->placementRoll(91, -203));
        self::assertSame($roll < $sample->densityPermille, $sample->accepts($roll));
        self::assertFalse($sample->accepts(-1));
        self::assertFalse($sample->accepts(1_000));
    }

    private static function context(
        int $x = 0,
        int $z = 0,
        int $slope = 1,
        int $waterDepth = 0,
        int $distanceToStructure = 50,
        int $distanceToCaveEntrance = 50,
    ): OverworldEcologyContext {
        return new OverworldEcologyContext(
            $x,
            $z,
            560,
            $slope,
            $waterDepth,
            20,
            $distanceToStructure,
            $distanceToCaveEntrance,
        );
    }
}
