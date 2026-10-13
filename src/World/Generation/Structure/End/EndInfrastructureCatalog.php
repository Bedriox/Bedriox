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

namespace Bedriox\Server\World\Generation\Structure\End;

/** Static terrain landmarks; gateways are intentionally owned by the durable dragon-victory lifecycle. */
final class EndInfrastructureCatalog
{
    /** @return list<EndInfrastructureLandmark> */
    public static function landmarks(): array
    {
        $landmarks = [
            new EndInfrastructureLandmark('exit_portal', 'end_exit_portal', 'standard', 0, 69, 0, 3, 5),
            new EndInfrastructureLandmark('spawn_platform', 'end_spawn_platform', 'standard', 100, 48, 0, 2, 1),
        ];
        foreach (self::pillarDefinitions() as $index => [$x, $z, $radius, $height]) {
            $landmarks[] = new EndInfrastructureLandmark(
                'pillar:' . $index,
                'obsidian_pillar',
                $radius >= 5 ? 'caged' : 'uncaged',
                $x,
                $height + 2,
                $z,
                $radius,
                $height - 44,
            );
        }

        return $landmarks;
    }

    /** @return list<array{int, int, int, int}> x, z, radius, top Y */
    public static function pillarDefinitions(): array
    {
        return [
            [42, 0, 3, 76], [34, 25, 3, 79], [13, 40, 4, 82], [-13, 40, 4, 85], [-34, 25, 4, 88],
            [-42, 0, 5, 91], [-34, -25, 5, 94], [-13, -40, 5, 97], [13, -40, 6, 100], [34, -25, 6, 103],
        ];
    }
}
