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

namespace Bedriox\Server\Tests\Simulation;

use Bedriox\Api\Entity\Skin;
use Bedriox\Api\World\Position as ApiPosition;
use Bedriox\Api\World\World;
use Bedriox\Api\World\WorldDimension;
use Bedriox\Server\Simulation\WorldSimulation;
use PHPUnit\Framework\TestCase;

final class HumanNavigationTest extends TestCase
{
    public function testNavigationIntentMovesUntilArrivalThenCompletes(): void
    {
        $simulation = new WorldSimulation(worldId: 'world');
        $world = new World('world', 1);
        $human = $simulation->spawnHuman(
            new ApiPosition(0.0, 64.0, 0.0, 0.0, 0.0, $world, WorldDimension::OVERWORLD),
            'Guide',
            new Skin(
                'guide',
                64,
                64,
                str_repeat("\0", 64 * 64 * 4),
                '{"geometry":{"default":"geometry.humanoid.custom"}}',
            ),
        );
        $human->navigateTo(
            new ApiPosition(1.0, 64.0, 0.0, 0.0, 0.0, $world, WorldDimension::OVERWORLD),
            0.25,
        );

        $simulation->tick();
        self::assertGreaterThan(0.0, $human->getMotion()->x);
        $human->getController()->teleport(new ApiPosition(0.75, 64.0, 0.0));
        $simulation->tick();
        self::assertFalse($human->getController()->isNavigating());
        self::assertSame(0.0, $human->getMotion()->x);
    }
}
