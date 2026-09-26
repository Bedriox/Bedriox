<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Worker\Navigation;

use Bedriox\Server\Entity\Navigation\GroundPathfinder;
use Bedriox\Server\Entity\Navigation\NavigationPathStatus;
use Bedriox\Server\Entity\Navigation\NavigationPoint;
use Bedriox\Server\Entity\Navigation\NavigationSnapshot;
use Bedriox\Server\Worker\CoreWorkerTaskCatalog;
use Bedriox\Server\Worker\ManagedWorkerPool;
use Bedriox\Server\Worker\Navigation\NavigationPathCodec;
use Bedriox\Server\Worker\Navigation\NavigationSearchRequest;
use Bedriox\Server\Worker\Navigation\NavigationSearchRequestCodec;
use Bedriox\Server\Worker\Navigation\NavigationTransferException;
use Bedriox\Server\Worker\Task\FindNavigationPathTask;
use Bedriox\Server\Worker\WorkerResultStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NavigationWorkerTaskTest extends TestCase
{
    public function testRequestCodecRoundTripsSnapshotLimitsAndRevision(): void
    {
        $request = self::request(-10, 7, -20);
        $codec = new NavigationSearchRequestCodec();

        $decoded = $codec->decode($codec->encode($request));

        self::assertSame($request->snapshot->revision, $decoded->snapshot->revision);
        self::assertSame($request->snapshot->bits(), $decoded->snapshot->bits());
        self::assertSame('-10:7:-20', $decoded->start->key());
        self::assertSame('-6:7:-20', $decoded->target->key());
        self::assertSame(64, $decoded->maximumDistanceBlocks);
        self::assertSame(8, $decoded->maximumVerticalRange);
        self::assertSame(512, $decoded->maximumVisitedNodes);
        self::assertSame(50, $decoded->maximumRuntimeMilliseconds);
    }

    public function testMaximumSnapshotFitsTheDeclaredPayloadBound(): void
    {
        $snapshot = new NavigationSnapshot(
            0,
            0,
            0,
            128,
            16,
            128,
            str_repeat("\xff", intdiv(NavigationSnapshot::MAXIMUM_CELLS, 8)),
            hash('sha256', 'maximum-snapshot'),
        );
        $request = new NavigationSearchRequest(
            $snapshot,
            new NavigationPoint(0, 0, 0),
            new NavigationPoint(127, 15, 127),
            NavigationSearchRequest::MAXIMUM_DISTANCE_BLOCKS,
            NavigationSearchRequest::MAXIMUM_VERTICAL_RANGE,
            GroundPathfinder::MAXIMUM_VISITED_NODES,
            NavigationSearchRequest::MAXIMUM_RUNTIME_MILLISECONDS,
        );

        self::assertSame(
            NavigationSearchRequestCodec::MAXIMUM_ENCODED_BYTES,
            strlen((new NavigationSearchRequestCodec())->encode($request)),
        );
    }

    public function testRequestRejectsAnUnboundedDeadline(): void
    {
        $request = self::request();

        $this->expectException(InvalidArgumentException::class);
        new NavigationSearchRequest(
            $request->snapshot,
            $request->start,
            $request->target,
            64,
            8,
            512,
            NavigationSearchRequest::MAXIMUM_RUNTIME_MILLISECONDS + 1,
        );
    }

    public function testRequestCodecRejectsChecksumDamage(): void
    {
        $codec = new NavigationSearchRequestCodec();
        $encoded = $codec->encode(self::request());
        $encoded[20] = chr(ord($encoded[20]) ^ 0x01);

        $this->expectException(NavigationTransferException::class);
        $codec->decode($encoded);
    }

    public function testTaskReturnsARevisionBoundedPath(): void
    {
        $request = self::request();
        $result = (new FindNavigationPathTask())->execute((new NavigationSearchRequestCodec())->encode($request));
        $path = (new NavigationPathCodec())->decode($result);

        self::assertSame(NavigationPathStatus::REACHED_TARGET, $path->status);
        self::assertSame($request->snapshot->revision, $path->snapshotRevision);
        self::assertSame('0:0:0', $path->points[0]->key());
        $lastPoint = $path->points[count($path->points) - 1] ?? null;
        self::assertInstanceOf(NavigationPoint::class, $lastPoint);
        self::assertSame('4:0:0', $lastPoint->key());
    }

    public function testCatalogRegistersBoundedNavigationTask(): void
    {
        $definition = CoreWorkerTaskCatalog::create()->get(CoreWorkerTaskCatalog::FIND_NAVIGATION_PATH);

        self::assertNotNull($definition);
        self::assertSame(FindNavigationPathTask::class, $definition->handlerClass);
        self::assertSame(NavigationSearchRequestCodec::MAXIMUM_ENCODED_BYTES, $definition->maximumInputBytes);
        self::assertSame(NavigationPathCodec::MAXIMUM_ENCODED_BYTES, $definition->maximumResultBytes);
        self::assertSame(2_000, $definition->timeoutMilliseconds);
    }

    public function testProductionWorkerExecutesNavigationTask(): void
    {
        $pool = ManagedWorkerPool::start('navigation-test', 1);
        try {
            $request = self::request();
            $submission = $pool->submit(
                CoreWorkerTaskCatalog::FIND_NAVIGATION_PATH,
                (new NavigationSearchRequestCodec())->encode($request),
            );
            self::assertNotNull($submission->receipt);
            $deadline = hrtime(true) + 10_000_000_000;
            $result = null;
            do {
                $pool->poll();
                foreach ($pool->takeResults() as $candidate) {
                    if ($candidate->receipt->taskId === $submission->receipt->taskId) {
                        $result = $candidate;
                    }
                }
                if ($result !== null) {
                    break;
                }
                usleep(1_000);
            } while (hrtime(true) < $deadline);

            self::assertNotNull($result, $pool->diagnostic());
            self::assertSame(WorkerResultStatus::SUCCESS, $result->status, $result->failureCode ?? '');
            $path = (new NavigationPathCodec())->decode($result->payload);
            self::assertSame(NavigationPathStatus::REACHED_TARGET, $path->status);
            self::assertSame($request->snapshot->revision, $path->snapshotRevision);
        } finally {
            $pool->shutdown();
        }
    }

    private static function request(int $minimumX = 0, int $minimumY = 0, int $minimumZ = 0): NavigationSearchRequest
    {
        $points = [];
        for ($offset = 0; $offset < 5; ++$offset) {
            $points[] = new NavigationPoint($minimumX + $offset, $minimumY, $minimumZ);
        }
        $snapshot = NavigationSnapshot::fromWalkablePoints(
            $minimumX,
            $minimumY,
            $minimumZ,
            5,
            1,
            1,
            $points,
            hash('sha256', $minimumX . ':' . $minimumY . ':' . $minimumZ),
        );

        return new NavigationSearchRequest(
            $snapshot,
            $points[0],
            $points[4],
            64,
            8,
            512,
            50,
        );
    }
}
