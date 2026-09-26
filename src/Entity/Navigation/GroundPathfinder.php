<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Navigation;

use InvalidArgumentException;
use SplPriorityQueue;

/** Bounded ground A* over a prevalidated immutable walkability snapshot. */
final class GroundPathfinder
{
    public const int MAXIMUM_VISITED_NODES = 8_192;

    public function find(
        NavigationSnapshot $snapshot,
        NavigationPoint $start,
        NavigationPoint $target,
        int $maximumVisitedNodes = 2_048,
        ?int $deadlineNanoseconds = null,
    ): NavigationPath {
        if ($maximumVisitedNodes < 1 || $maximumVisitedNodes > self::MAXIMUM_VISITED_NODES) {
            throw new InvalidArgumentException('Pathfinding node budget is outside its supported bounds.');
        }
        if (!$snapshot->isWalkable($start) || !$snapshot->isWalkable($target)) {
            throw new InvalidArgumentException('Pathfinding endpoints must be walkable snapshot cells.');
        }
        if ($deadlineNanoseconds !== null && $deadlineNanoseconds < 1) {
            throw new InvalidArgumentException('Pathfinding deadline is invalid.');
        }
        if ($deadlineNanoseconds !== null && hrtime(true) >= $deadlineNanoseconds) {
            return new NavigationPath(
                [$start],
                NavigationPathStatus::DEADLINE_EXCEEDED,
                $snapshot->revision,
                0,
            );
        }

        $startKey = $start->key();
        $targetKey = $target->key();
        $open = new SplPriorityQueue();
        $open->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $open->insert($start, [0, 0]);
        $gScore = [$startKey => 0];
        $parents = [];
        $points = [$startKey => $start];
        $closed = [];
        $visited = 0;
        $sequence = 0;
        $closest = $start;
        $closestHeuristic = self::heuristic($start, $target);

        while (!$open->isEmpty() && $visited < $maximumVisitedNodes) {
            /** @var array{data: NavigationPoint, priority: mixed} $entry */
            $entry = $open->extract();
            $current = $entry['data'];
            $currentKey = $current->key();
            if (isset($closed[$currentKey])) {
                continue;
            }
            $closed[$currentKey] = true;
            ++$visited;
            $heuristic = self::heuristic($current, $target);
            if ($heuristic < $closestHeuristic) {
                $closestHeuristic = $heuristic;
                $closest = $current;
            }
            if ($currentKey === $targetKey) {
                $path = self::reconstruct($current, $parents, $points);
                if (count($path) > NavigationPath::MAXIMUM_POINTS) {
                    return new NavigationPath(
                        array_slice($path, 0, NavigationPath::MAXIMUM_POINTS),
                        NavigationPathStatus::PATH_LENGTH_EXCEEDED,
                        $snapshot->revision,
                        $visited,
                    );
                }

                return new NavigationPath(
                    $path,
                    NavigationPathStatus::REACHED_TARGET,
                    $snapshot->revision,
                    $visited,
                );
            }

            foreach ($this->neighbors($snapshot, $current) as $neighbor) {
                $neighborKey = $neighbor->key();
                if (isset($closed[$neighborKey])) {
                    continue;
                }
                $verticalCost = abs($neighbor->y - $current->y) * 5;
                $tentative = $gScore[$currentKey] + 10 + $verticalCost;
                if ($tentative >= ($gScore[$neighborKey] ?? PHP_INT_MAX)) {
                    continue;
                }
                $parents[$neighborKey] = $currentKey;
                $points[$neighborKey] = $neighbor;
                $gScore[$neighborKey] = $tentative;
                $priority = -($tentative + self::heuristic($neighbor, $target));
                $open->insert($neighbor, [$priority, -$sequence++]);
            }
            if ($deadlineNanoseconds !== null && ($visited & 31) === 0 && hrtime(true) >= $deadlineNanoseconds) {
                return new NavigationPath(
                    self::boundedReconstruction($closest, $parents, $points),
                    NavigationPathStatus::DEADLINE_EXCEEDED,
                    $snapshot->revision,
                    $visited,
                );
            }
        }

        return new NavigationPath(
            self::boundedReconstruction($closest, $parents, $points),
            $open->isEmpty()
                ? NavigationPathStatus::UNREACHABLE
                : NavigationPathStatus::NODE_BUDGET_EXHAUSTED,
            $snapshot->revision,
            $visited,
        );
    }

    /** @return list<NavigationPoint> */
    private function neighbors(NavigationSnapshot $snapshot, NavigationPoint $current): array
    {
        $result = [];
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$offsetX, $offsetZ]) {
            foreach ([0, 1, -1] as $offsetY) {
                $candidate = new NavigationPoint(
                    $current->x + $offsetX,
                    $current->y + $offsetY,
                    $current->z + $offsetZ,
                );
                if ($snapshot->isWalkable($candidate)) {
                    $result[] = $candidate;
                    break;
                }
            }
        }

        return $result;
    }

    private static function heuristic(NavigationPoint $left, NavigationPoint $right): int
    {
        return (abs($left->x - $right->x) + abs($left->z - $right->z)) * 10
            + abs($left->y - $right->y) * 15;
    }

    /**
     * @param array<string, string> $parents
     * @param array<string, NavigationPoint> $points
     * @return list<NavigationPoint>
     */
    private static function reconstruct(NavigationPoint $end, array $parents, array $points): array
    {
        $path = [$end];
        $key = $end->key();
        while (isset($parents[$key])) {
            $key = $parents[$key];
            $path[] = $points[$key];
        }

        return array_reverse($path);
    }

    /**
     * @param array<string, string> $parents
     * @param array<string, NavigationPoint> $points
     * @return list<NavigationPoint>
     */
    private static function boundedReconstruction(NavigationPoint $end, array $parents, array $points): array
    {
        return array_slice(self::reconstruct($end, $parents, $points), 0, NavigationPath::MAXIMUM_POINTS);
    }
}
