<?php

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
