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

    /** @var array<int, array{identity: string, world: string, target: string, revision: string, terrain: string}> */
    private array $pending = [];

    /** @var array<int, array{target: string, points: list<NavigationPoint>, index: int}> */
    private array $paths = [];

    /** @var array<int, int> */
    private array $retryAfter = [];

    private int $budgetTick = -1;
    private int $submissionsThisTick = 0;
    private int $submitted = 0;
    private int $completed = 0;
    private int $rejected = 0;

    public function __construct(
        private readonly EntityRegistry $entities,
        private readonly LoadedCollisionBoxQuery $collisions,
        private readonly WorkerDispatcher $workers,
        /** @var null|Closure(Position, Position): string */
        private readonly ?Closure $terrainRevision = null,
    ) {}

    public function waypoint(AbstractMobEntity $entity, Position $target, int $tick): Position
    {
        if ($this->directPathIsClear($entity, $target)) {
            unset($this->paths[$entity->getRuntimeId()], $this->pending[$entity->getRuntimeId()]);
            return $target;
        }
        $runtimeId = $entity->getRuntimeId();
        $targetKey = self::pointKey($target);
        $path = $this->paths[$runtimeId] ?? null;
        if ($path !== null && $path['target'] === $targetKey) {
            while (isset($path['points'][$path['index']])) {
                $point = $path['points'][$path['index']];
                $waypoint = new Position($point->x + 0.5, $point->y, $point->z + 0.5);
                if ($entity->internalPosition()->distanceTo($waypoint) > 0.75) {
                    $this->paths[$runtimeId] = $path;
                    return $waypoint;
                }
                ++$path['index'];
            }
            unset($this->paths[$runtimeId]);
        }
        if (isset($this->pending[$runtimeId]) || ($this->retryAfter[$runtimeId] ?? 0) > $tick) {
            return $entity->internalPosition();
        }
        if ($this->budgetTick !== $tick) {
            $this->budgetTick = $tick;
            $this->submissionsThisTick = 0;
        }
        if ($this->submissionsThisTick >= self::MAXIMUM_SUBMISSIONS_PER_TICK) {
            return $entity->internalPosition();
        }
        $snapshot = $this->capture($entity, $target);
        if ($snapshot === null) {
            $this->retryAfter[$runtimeId] = $tick + self::RETRY_BACKOFF_TICKS;
            return $entity->internalPosition();
        }
        [$volume, $start, $destination] = $snapshot;
        $request = new NavigationSearchRequest(
            $volume,
            $start,
            $destination,
            self::MAXIMUM_SNAPSHOT_PATH_DISTANCE,
            8,
            2_048,
            25,
        );
        $identity = $entity->getUniqueId();
        $world = $entity->getWorldName();
        $revision = $volume->revision;
        $terrain = $this->terrainRevision === null
            ? ''
            : ($this->terrainRevision)($entity->internalPosition(), $target);
        $submission = $this->workers->submit(
            CoreWorkerTaskCatalog::FIND_NAVIGATION_PATH,
            (new NavigationSearchRequestCodec())->encode($request),
            function (WorkerResult $result) use ($runtimeId, $identity, $world, $target, $targetKey, $revision, $terrain): void {
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
                $this->paths[$runtimeId] = ['target' => $targetKey, 'points' => $path->points, 'index' => 1];
                ++$this->completed;
            },
            hrtime(true) + 100_000_000,
        );
        ++$this->submissionsThisTick;
        if (!$submission->isAccepted()) {
            ++$this->rejected;
            $this->retryAfter[$runtimeId] = $tick + self::RETRY_BACKOFF_TICKS;
            return $entity->internalPosition();
        }
        $this->pending[$runtimeId] = [
            'identity' => $identity,
            'world' => $world,
            'target' => $targetKey,
            'revision' => $revision,
            'terrain' => $terrain,
        ];
        ++$this->submitted;

        return $entity->internalPosition();
    }

    public function metrics(): EntityNavigationMetrics
    {
        return new EntityNavigationMetrics(
            $this->submitted,
            $this->completed,
            $this->rejected,
            count($this->pending),
            count($this->paths),
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

    /** @return null|array{NavigationSnapshot, NavigationPoint, NavigationPoint} */
    private function capture(AbstractMobEntity $entity, Position $target): ?array
    {
        $origin = $entity->internalPosition();
        $centerX = (int) floor(($origin->x + $target->x) / 2.0);
        $centerZ = (int) floor(($origin->z + $target->z) / 2.0);
        $minimumX = $centerX - self::HORIZONTAL_RADIUS;
        $minimumZ = $centerZ - self::HORIZONTAL_RADIUS;
        $minimumY = (int) floor(min($origin->y, $target->y)) - self::VERTICAL_RADIUS;
        $sizeX = $sizeZ = (self::HORIZONTAL_RADIUS * 2) + 1;
        $sizeY = (self::VERTICAL_RADIUS * 2) + 3;
        $walkable = [];
        $halfWidth = $entity->collisionWidth() / 2.0;
        for ($y = $minimumY; $y < $minimumY + $sizeY; ++$y) {
            for ($z = $minimumZ; $z < $minimumZ + $sizeZ; ++$z) {
                for ($x = $minimumX; $x < $minimumX + $sizeX; ++$x) {
                    $body = new AxisAlignedBox(
                        $x + 0.5 - $halfWidth,
                        $y,
                        $z + 0.5 - $halfWidth,
                        $x + 0.5 + $halfWidth,
                        $y + $entity->collisionHeight(),
                        $z + 0.5 + $halfWidth,
                    );
                    $bodyBlocks = $this->collisions->boxesIntersectingLoaded($body);
                    $support = $this->collisions->boxesIntersectingLoaded($body->offset(0.0, -0.1, 0.0));
                    if ($bodyBlocks === null || $support === null) {
                        return null;
                    }
                    if ($bodyBlocks === [] && $support !== []) {
                        $walkable[] = new NavigationPoint($x, $y, $z);
                    }
                }
            }
        }
        $start = self::nearestWalkable($walkable, $origin);
        $destination = self::nearestWalkable($walkable, $target);
        if ($start === null || $destination === null) {
            return null;
        }
        $revisionInput = implode(';', array_map(static fn(NavigationPoint $point): string => $point->key(), $walkable));
        $volume = NavigationSnapshot::fromWalkablePoints(
            $minimumX,
            $minimumY,
            $minimumZ,
            $sizeX,
            $sizeY,
            $sizeZ,
            $walkable,
            hash('sha256', $revisionInput),
        );

        return [$volume, $start, $destination];
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

    private static function pointKey(Position $position): string
    {
        return (int) floor($position->x) . ':' . (int) floor($position->y) . ':' . (int) floor($position->z);
    }
}
