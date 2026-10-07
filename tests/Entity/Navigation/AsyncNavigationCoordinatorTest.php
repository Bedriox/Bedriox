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

namespace Bedriox\Server\Tests\Entity\Navigation;

use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Entity\Navigation\AsyncNavigationCoordinator;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Spawn\EntitySpawnService;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Worker\Task\FindNavigationPathTask;
use Bedriox\Server\Worker\WorkerDispatcher;
use Bedriox\Server\Worker\WorkerPoolSnapshot;
use Bedriox\Server\Worker\WorkerReceipt;
use Bedriox\Server\Worker\WorkerResult;
use Bedriox\Server\Worker\WorkerResultStatus;
use Bedriox\Server\Worker\WorkerSubmission;
use Bedriox\Server\World\Collision\AxisAlignedBox;
use Bedriox\Server\World\Collision\LoadedCollisionBoxQuery;
use Closure;
use PHPUnit\Framework\TestCase;

final class AsyncNavigationCoordinatorTest extends TestCase
{
    public function testObstructionSubmitsWorkerPathAndReusesValidatedCompletion(): void
    {
        $entities = new EntityRegistry();
        $mob = (new EntitySpawnService($entities, EntityDefinitionRegistry::baseline()))->spawn(
            new EntitySpawnRequest(
                VanillaEntityType::COW,
                SpawnCause::COMMAND,
                'world',
                new Position(0.5, 64.0, 0.5),
            ),
        )->entity;
        self::assertInstanceOf(AbstractMobEntity::class, $mob);
        $workers = new NavigationTestDispatcher();
        $navigation = new AsyncNavigationCoordinator($entities, new NavigationTestCollisionQuery(), $workers);
        $target = new Position(5.5, 64.0, 0.5);

        self::assertEquals($mob->internalPosition(), $navigation->waypoint($mob, $target, 1));
        self::assertSame(1, $workers->submissions);
        $workers->complete();

        $waypoint = $navigation->waypoint($mob, $target, 2);
        self::assertNotEquals($mob->internalPosition(), $waypoint);
        self::assertSame(1, $workers->submissions);
        self::assertSame(1, $navigation->metrics()->completed);
        self::assertSame(1, $navigation->metrics()->cached);
    }

    public function testDistantDiagonalTargetCannotExceedSnapshotRequestBounds(): void
    {
        $entities = new EntityRegistry();
        $mob = (new EntitySpawnService($entities, EntityDefinitionRegistry::baseline()))->spawn(
            new EntitySpawnRequest(
                VanillaEntityType::COW,
                SpawnCause::COMMAND,
                'world',
                new Position(0.5, 64.0, 0.5),
            ),
        )->entity;
        self::assertInstanceOf(AbstractMobEntity::class, $mob);
        $workers = new NavigationTestDispatcher();
        $navigation = new AsyncNavigationCoordinator($entities, new NavigationTestCollisionQuery(), $workers);

        self::assertEquals(
            $mob->internalPosition(),
            $navigation->waypoint($mob, new Position(100.5, 64.0, 100.5), 1),
        );
        self::assertSame(1, $workers->submissions);
    }
}

final class NavigationTestCollisionQuery implements LoadedCollisionBoxQuery
{
    public function boxesIntersecting(AxisAlignedBox $area): array
    {
        return $this->boxesIntersectingLoaded($area);
    }

    public function hasCollision(AxisAlignedBox $area): bool
    {
        return $this->boxesIntersecting($area) !== [];
    }

    public function boxesIntersectingLoaded(AxisAlignedBox $area): array
    {
        $matches = [];
        foreach ([
            new AxisAlignedBox(-64.0, 63.0, -64.0, 64.0, 64.0, 64.0),
            new AxisAlignedBox(2.0, 64.0, -0.5, 3.0, 67.0, 1.5),
        ] as $box) {
            if ($box->intersects($area)) {
                $matches[] = $box;
            }
        }

        return $matches;
    }
}

final class NavigationTestDispatcher implements WorkerDispatcher
{
    public int $submissions = 0;
    private string $payload = '';
    private Closure $completion;
    private WorkerReceipt $receipt;

    public function submit(
        int $taskTypeId,
        string $payload,
        Closure $completion,
        ?int $deadlineNanoseconds = null,
    ): WorkerSubmission {
        ++$this->submissions;
        $this->payload = $payload;
        $this->completion = $completion;
        $this->receipt = new WorkerReceipt('navigation-test', 1, $taskTypeId, 'navigation', hrtime(true) + 1_000_000_000);

        return WorkerSubmission::accepted($this->receipt);
    }

    public function complete(): void
    {
        $payload = (new FindNavigationPathTask())->execute($this->payload);
        ($this->completion)(new WorkerResult($this->receipt, WorkerResultStatus::SUCCESS, $payload));
    }

    public function cancel(WorkerReceipt $receipt): bool
    {
        return true;
    }

    public function poll(int $maximumCompletions = 256): void {}

    public function snapshot(): WorkerPoolSnapshot
    {
        return new WorkerPoolSnapshot('', 1, 0, 0, 0, 0, 1, 1, 0, 0, 0, 0, 0, true);
    }

    public function shutdown(): void {}
}
