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

namespace Bedriox\Server\Entity\Navigation;

use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Worker\CoreWorkerTaskCatalog;
use Bedriox\Server\Worker\Navigation\NavigationPathCodec;
use Bedriox\Server\Worker\Navigation\NavigationSearchRequest;
use Bedriox\Server\Worker\Navigation\NavigationSearchRequestCodec;
use Bedriox\Server\Worker\WorkerDispatcher;
use Bedriox\Server\Worker\WorkerResult;
use Bedriox\Server\Worker\WorkerResultStatus;
use Bedriox\Server\World\Collision\AxisAlignedBox;
use Bedriox\Server\World\Collision\LoadedCollisionBoxQuery;
use Closure;

/** Captures bounded loaded-terrain snapshots and commits worker paths only after identity validation. */
final class AsyncNavigationCoordinator
{
    private const int HORIZONTAL_RADIUS = 10;
    private const int MAXIMUM_SNAPSHOT_PATH_DISTANCE = 30;
    private const int VERTICAL_RADIUS = 3;
    private const int MAXIMUM_SUBMISSIONS_PER_TICK = 2;
    private const int RETRY_BACKOFF_TICKS = 20;
    private const int MAXIMUM_PREPARATION_CELLS_PER_TICK = 128;
    private const int MAXIMUM_PREPARATION_NANOSECONDS_PER_TICK = 1_500_000;
    private const int DIRECT_PATH_REUSE_TICKS = 5;
    private const float TARGET_REUSE_DISTANCE_SQUARED = 9.0;

    /** @var array<int, array{identity: string, world: string, target: Position, revision: string, terrain: string, generation: int}> */
    private array $pending = [];

    /** @var array<int, array{target: Position, points: list<NavigationPoint>, index: int}> */
    private array $paths = [];

    /** @var array<int, NavigationSnapshotBuild> */
    private array $builds = [];

    /** @var list<NavigationSnapshotBuild> */
    private array $buildQueue = [];

    /** @var array<int, array{target: Position, until: int}> */
    private array $directPaths = [];

    /** @var array<int, int> */
    private array $retryAfter = [];

    private int $submissionsThisTick = 0;
    private int $submitted = 0;
    private int $completed = 0;
    private int $rejected = 0;
    private int $generation = 0;
    private int $queuedBuilds = 0;
    private int $completedBuilds = 0;
    private int $cellsScanned = 0;
    private int $preparationNanoseconds = 0;
    private int $maximumPreparationNanoseconds = 0;
    private int $pathsReused = 0;
    private int $repathsSuppressed = 0;

    public function __construct(
        private readonly EntityRegistry $entities,
        private readonly LoadedCollisionBoxQuery $collisions,
        private readonly WorkerDispatcher $workers,
        /** @var null|Closure(Position, Position): string */
        private readonly ?Closure $terrainRevision = null,
    ) {}

    public function waypoint(AbstractMobEntity $entity, Position $target, int $tick): Position
    {
        $runtimeId = $entity->getRuntimeId();
        $path = $this->paths[$runtimeId] ?? null;
        if ($path !== null && self::targetsAreReusable($path['target'], $target)) {
            while (isset($path['points'][$path['index']])) {
                $point = $path['points'][$path['index']];
                $waypoint = new Position($point->x + 0.5, $point->y, $point->z + 0.5);
                if ($entity->internalPosition()->distanceTo($waypoint) > 0.75) {
                    $this->paths[$runtimeId] = $path;
                    ++$this->pathsReused;
                    return $waypoint;
                }
                ++$path['index'];
            }
            unset($this->paths[$runtimeId]);
        }
        if ($path !== null) {
            unset($this->paths[$runtimeId]);
        }
        $direct = $this->directPaths[$runtimeId] ?? null;
        if ($direct !== null && $direct['until'] >= $tick && self::targetsAreReusable($direct['target'], $target)) {
            ++$this->pathsReused;
            return $target;
        }
        unset($this->directPaths[$runtimeId]);
        $pending = $this->pending[$runtimeId] ?? null;
        if ($pending !== null && self::targetsAreReusable($pending['target'], $target)) {
            ++$this->repathsSuppressed;
            return $entity->internalPosition();
        }
        if ($pending !== null) {
            unset($this->pending[$runtimeId]);
        }
        $build = $this->builds[$runtimeId] ?? null;
        if ($build !== null && self::targetsAreReusable($build->target, $target)) {
            ++$this->repathsSuppressed;
            return $entity->internalPosition();
        }
        if ($build !== null) {
            unset($this->builds[$runtimeId]);
        }
        if (($this->retryAfter[$runtimeId] ?? 0) > $tick) {
            return $entity->internalPosition();
        }
        if ($this->directPathIsClear($entity, $target)) {
            $this->directPaths[$runtimeId] = ['target' => $target, 'until' => $tick + self::DIRECT_PATH_REUSE_TICKS];
            return $target;
        }
        $this->queueBuild($entity, $target);

        return $entity->internalPosition();
    }

    /** Advances all queued terrain captures under one global main-thread budget. */
    public function advance(
        int $tick,
        int $maximumCells = self::MAXIMUM_PREPARATION_CELLS_PER_TICK,
        int $maximumNanoseconds = self::MAXIMUM_PREPARATION_NANOSECONDS_PER_TICK,
    ): void {
        $this->submissionsThisTick = 0;
        $started = hrtime(true);
        $scanned = 0;
        $visits = count($this->buildQueue);
        while ($this->buildQueue !== [] && $scanned < $maximumCells && $visits > 0) {
            if ($scanned > 0 && hrtime(true) - $started >= $maximumNanoseconds) {
                break;
            }
            $build = array_shift($this->buildQueue);
            $runtimeId = $build->runtimeId;
            $entity = $this->entities->getByRuntimeId($runtimeId);
            if (($this->builds[$runtimeId] ?? null) !== $build
                || $entity === null || $entity->getUniqueId() !== $build->identity
                || $entity->getWorldName() !== $build->world) {
                if (($this->builds[$runtimeId] ?? null) === $build) {
                    unset($this->builds[$runtimeId]);
                }
                --$visits;
                continue;
            }
            if (!$build->isComplete()) {
                $build->advance($this->collisions);
                ++$scanned;
                ++$this->cellsScanned;
            }
            if ($build->isComplete() && $this->submissionsThisTick < self::MAXIMUM_SUBMISSIONS_PER_TICK) {
                unset($this->builds[$runtimeId]);
                ++$this->completedBuilds;
                $this->submitBuild($build, $tick);
                --$visits;
                continue;
            }
            $this->buildQueue[] = $build;
            --$visits;
            if ($visits === 0 && $scanned < $maximumCells) {
                $visits = count($this->buildQueue);
            }
        }
        $elapsed = hrtime(true) - $started;
        $this->preparationNanoseconds += $elapsed;
        $this->maximumPreparationNanoseconds = max($this->maximumPreparationNanoseconds, $elapsed);
    }

    private function submitBuild(NavigationSnapshotBuild $build, int $tick): void
    {
        $runtimeId = $build->runtimeId;
        if ($build->isUnavailable() || $build->walkablePoints() === []) {
            $this->retryAfter[$runtimeId] = $tick + self::RETRY_BACKOFF_TICKS;
            return;
        }
        if ($this->terrainRevision !== null
            && ($this->terrainRevision)($build->origin, $build->target) !== $build->terrainRevision) {
            $this->retryAfter[$runtimeId] = $tick + 1;
            ++$this->rejected;
            return;
        }
        $walkable = $build->walkablePoints();
        $start = self::nearestWalkable($walkable, $build->origin);
        $destination = self::nearestWalkable($walkable, $build->target);
        if ($start === null || $destination === null) {
            $this->retryAfter[$runtimeId] = $tick + self::RETRY_BACKOFF_TICKS;
            return;
        }
        $revisionInput = implode(';', array_map(static fn(NavigationPoint $point): string => $point->key(), $walkable));
        $volume = NavigationSnapshot::fromWalkablePoints(
            $build->minimumX,
            $build->minimumY,
            $build->minimumZ,
            $build->sizeX,
            $build->sizeY,
            $build->sizeZ,
            $walkable,
            hash('sha256', $revisionInput),
        );
        $request = new NavigationSearchRequest(
            $volume,
            $start,
            $destination,
            self::MAXIMUM_SNAPSHOT_PATH_DISTANCE,
            8,
            2_048,
            25,
        );
        $identity = $build->identity;
        $world = $build->world;
        $revision = $volume->revision;
        $terrain = $build->terrainRevision;
        $target = $build->target;
        $generation = ++$this->generation;
        $submission = $this->workers->submit(
            CoreWorkerTaskCatalog::FIND_NAVIGATION_PATH,
            (new NavigationSearchRequestCodec())->encode($request),
            function (WorkerResult $result) use ($runtimeId, $identity, $world, $target, $revision, $terrain, $generation): void {
                $pending = $this->pending[$runtimeId] ?? null;
                if ($pending === null || $pending['generation'] !== $generation) {
                    ++$this->rejected;
                    return;
                }
                unset($this->pending[$runtimeId]);
                $entity = $this->entities->getByRuntimeId($runtimeId);
                if ($result->status !== WorkerResultStatus::SUCCESS || $entity === null
                    || $entity->getUniqueId() !== $identity || $entity->getWorldName() !== $world) {
                    ++$this->rejected;
                    return;
                }
                $path = (new NavigationPathCodec())->decode($result->payload);
                if ($path->snapshotRevision !== $revision || !$path->reachedTarget()
                    || ($this->terrainRevision !== null
                        && ($this->terrainRevision)($entity->internalPosition(), $target) !== $terrain)) {
                    ++$this->rejected;
                    return;
                }
                $this->paths[$runtimeId] = ['target' => $target, 'points' => $path->points, 'index' => 1];
                ++$this->completed;
            },
            hrtime(true) + 100_000_000,
        );
        ++$this->submissionsThisTick;
        if (!$submission->isAccepted()) {
            ++$this->rejected;
            $this->retryAfter[$runtimeId] = $tick + self::RETRY_BACKOFF_TICKS;
            return;
        }
        $this->pending[$runtimeId] = [
            'identity' => $identity,
            'world' => $world,
            'target' => $target,
            'revision' => $revision,
            'terrain' => $terrain,
            'generation' => $generation,
        ];
        ++$this->submitted;
    }

    public function metrics(): EntityNavigationMetrics
    {
        return new EntityNavigationMetrics(
            $this->submitted,
            $this->completed,
            $this->rejected,
            count($this->pending),
            count($this->paths),
            count($this->builds),
            $this->queuedBuilds,
            $this->completedBuilds,
            $this->cellsScanned,
            $this->preparationNanoseconds,
            $this->maximumPreparationNanoseconds,
            $this->pathsReused,
            $this->repathsSuppressed,
        );
    }

    private function directPathIsClear(AbstractMobEntity $entity, Position $target): bool
    {
        $position = $entity->internalPosition();
        $halfWidth = $entity->collisionWidth() / 2.0;
        $box = new AxisAlignedBox(
            $position->x - $halfWidth,
            $position->y,
            $position->z - $halfWidth,
            $position->x + $halfWidth,
            $position->y + $entity->collisionHeight(),
            $position->z + $halfWidth,
        );

        $deltaX = $target->x - $position->x;
        $deltaZ = $target->z - $position->z;
        $distance = hypot($deltaX, $deltaZ);
        $scale = $distance > 12.0 ? 12.0 / $distance : 1.0;
        $obstacles = $this->collisions->boxesIntersectingLoaded($box->swept(
            $deltaX * $scale,
            max(-1.0, min(1.0, $target->y - $position->y)),
            $deltaZ * $scale,
        ));

        return $obstacles !== null && $obstacles === [];
    }

    private function queueBuild(AbstractMobEntity $entity, Position $target): void
    {
        $origin = $entity->internalPosition();
        $deltaX = abs($target->x - $origin->x);
        $deltaZ = abs($target->z - $origin->z);
        if ($deltaX <= 14.0 && $deltaZ <= 14.0) {
            $minimumX = (int) floor(min($origin->x, $target->x)) - 2;
            $minimumZ = (int) floor(min($origin->z, $target->z)) - 2;
            $sizeX = min(
                (self::HORIZONTAL_RADIUS * 2) + 1,
                (int) floor(max($origin->x, $target->x)) + 3 - $minimumX,
            );
            $sizeZ = min(
                (self::HORIZONTAL_RADIUS * 2) + 1,
                (int) floor(max($origin->z, $target->z)) + 3 - $minimumZ,
            );
        } else {
            $centerX = (int) floor(($origin->x + $target->x) / 2.0);
            $centerZ = (int) floor(($origin->z + $target->z) / 2.0);
            $minimumX = $centerX - self::HORIZONTAL_RADIUS;
            $minimumZ = $centerZ - self::HORIZONTAL_RADIUS;
            $sizeX = $sizeZ = (self::HORIZONTAL_RADIUS * 2) + 1;
        }
        $minimumY = (int) floor(min($origin->y, $target->y)) - 2;
        $sizeY = min(
            (self::VERTICAL_RADIUS * 2) + 3,
            (int) floor(max($origin->y, $target->y)) + 4 - $minimumY,
        );
        $runtimeId = $entity->getRuntimeId();
        $terrain = $this->terrainRevision === null ? '' : ($this->terrainRevision)($origin, $target);
        $build = new NavigationSnapshotBuild(
            $runtimeId,
            $entity->getUniqueId(),
            $entity->getWorldName(),
            $origin,
            $target,
            $terrain,
            $minimumX,
            $minimumY,
            $minimumZ,
            $sizeX,
            $sizeY,
            $sizeZ,
            $entity->collisionWidth(),
            $entity->collisionHeight(),
        );
        $this->builds[$runtimeId] = $build;
        $this->buildQueue[] = $build;
        ++$this->queuedBuilds;
    }

    /** @param list<NavigationPoint> $points */
    private static function nearestWalkable(array $points, Position $position): ?NavigationPoint
    {
        $nearest = null;
        $distance = INF;
        foreach ($points as $point) {
            $candidate = (($point->x + 0.5 - $position->x) ** 2)
                + (($point->y - $position->y) ** 2)
                + (($point->z + 0.5 - $position->z) ** 2);
            if ($candidate < $distance) {
                $nearest = $point;
                $distance = $candidate;
            }
        }

        return $nearest;
    }

    private static function targetsAreReusable(Position $left, Position $right): bool
    {
        return (($left->x - $right->x) ** 2) + (($left->z - $right->z) ** 2)
            <= self::TARGET_REUSE_DISTANCE_SQUARED
            && abs($left->y - $right->y) <= 1.5;
    }
}
