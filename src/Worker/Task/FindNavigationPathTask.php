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

namespace Bedriox\Server\Worker\Task;

use Bedriox\Server\Entity\Navigation\GroundPathfinder;
use Bedriox\Server\Worker\Navigation\NavigationPathCodec;
use Bedriox\Server\Worker\Navigation\NavigationSearchRequestCodec;
use Bedriox\Server\Worker\WorkerTaskHandler;

final class FindNavigationPathTask implements WorkerTaskHandler
{
    public function execute(string $payload): string
    {
        $request = (new NavigationSearchRequestCodec())->decode($payload);
        $deadline = hrtime(true) + ($request->maximumRuntimeMilliseconds * 1_000_000);
        $path = (new GroundPathfinder())->find(
            $request->snapshot,
            $request->start,
            $request->target,
            $request->maximumVisitedNodes,
            $deadline,
        );

        return (new NavigationPathCodec())->encode($path);
    }
}
