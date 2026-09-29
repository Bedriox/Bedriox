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

namespace Bedriox\Server\Tests\Api;

use Bedriox\Api\Player\ExperienceChangeCause;
use Bedriox\Api\Player\ExperienceManager;
use Bedriox\Api\Player\ExperienceSnapshot;
use PHPUnit\Framework\TestCase;

final class ExperienceApiTest extends TestCase
{
    public function testManagerProjectsOneSnapshotAndForwardsBoundedIntent(): void
    {
        $requests = [];
        $manager = new ExperienceManager(
            new ExperienceSnapshot(10),
            static function (int $points, ExperienceChangeCause $cause) use (&$requests): void {
                $requests[] = [$points, $cause];
            },
        );

        self::assertSame(1, $manager->getLevel());
        self::assertEqualsWithDelta(1 / 3, $manager->getProgress(), 0.000001);
        $manager->addPoints(5, ExperienceChangeCause::ORB);
        $manager->removePoints(50, ExperienceChangeCause::ENCHANTING);

        self::assertSame([
            [15, ExperienceChangeCause::ORB],
            [0, ExperienceChangeCause::ENCHANTING],
        ], $requests);
    }
}
