<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Server\Runtime\RuntimeLimits;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RuntimeLimitsTest extends TestCase
{
    public function testLargeSpawnRadiusUsesStreamingCapacityInsteadOfWholeViewCapacity(): void
    {
        $limits = new RuntimeLimits(
            maximumOutgoingPayloadsPerSession: 72,
            maximumChunkRadius: 32,
            preloadedChunkRadius: 32,
            maximumStreamingPacketsPerPoll: 64,
        );

        self::assertSame(32, $limits->preloadedChunkRadius);
    }

    public function testOutgoingCapacityMustCoverOneStreamingBatchAndHeadroom(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RuntimeLimits(
            maximumOutgoingPayloadsPerSession: 71,
            maximumStreamingPacketsPerPoll: 64,
        );
    }
}
