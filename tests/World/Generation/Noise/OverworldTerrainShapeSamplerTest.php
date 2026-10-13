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

namespace Bedriox\Server\Tests\World\Generation\Noise;

use Bedriox\Server\World\Generation\Noise\OverworldTerrainShapeSampler;
use PHPUnit\Framework\TestCase;

final class OverworldTerrainShapeSamplerTest extends TestCase
{
    public function testObservedSeedZeroClimatePointMatchesReferenceShape(): void
    {
        $shape = (new OverworldTerrainShapeSampler())->sample(
            0.05167429521679878,
            -0.08093611150979996,
            -0.0959620252251625,
        );

        self::assertEqualsWithDelta(-0.518608808517456, $shape->offset, 0.00002);
        self::assertEqualsWithDelta(366.8849792480469, $shape->factor, 0.0002);
        self::assertEqualsWithDelta(0.0, $shape->jaggedness, 0.00002);
    }
}
