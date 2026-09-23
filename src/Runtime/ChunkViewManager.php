<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use InvalidArgumentException;

/** Tracks one player's visible square plus a bounded hidden prefetch ring. */
final class ChunkViewManager
{
    /** @var array<string, array{x: int, z: int}> */
    private array $sent = [];

    /** @var array<string, array{x: int, z: int}> chunks retained and ready for delivery */
    private array $prepared = [];

    /** @var list<array{x: int, z: int}> */
    private array $pending = [];

    /** @var list<array{x: int, z: int}> */
    private array $prefetchPending = [];

    private ?int $centerX = null;
    private ?int $centerZ = null;

    public function __construct(
        private readonly int $radius,
        private readonly int $prefetchRadius = 0,
    ) {
        if ($radius < 1 || $radius > 32 || $prefetchRadius < 0 || $prefetchRadius > 8
            || $radius + $prefetchRadius > 32) {
            throw new InvalidArgumentException('Chunk view and prefetch radii are invalid.');
        }
    }

    /** @return list<array{x: int, z: int}> chunks which left the view */
    public function centerOnBlock(float $x, float $z): array
    {
        if (!is_finite($x) || !is_finite($z)) {
            throw new InvalidArgumentException('Chunk view position must be finite.');
        }

        return $this->centerOnChunk((int) floor($x / 16.0), (int) floor($z / 16.0));
    }

    /** @return list<array{x: int, z: int}> chunks which left the view */
    public function centerOnChunk(int $x, int $z): array
    {
        if ($this->centerX === $x && $this->centerZ === $z) {
            return [];
        }
        $this->centerX = $x;
        $this->centerZ = $z;

        /** @var array<string, array{x: int, z: int}> $visible */
        $visible = [];
        for ($chunkX = $x - $this->radius; $chunkX <= $x + $this->radius; ++$chunkX) {
            for ($chunkZ = $z - $this->radius; $chunkZ <= $z + $this->radius; ++$chunkZ) {
                $visible[self::key($chunkX, $chunkZ)] = ['x' => $chunkX, 'z' => $chunkZ];
            }
        }

        /** @var array<string, array{x: int, z: int}> $retained */
        $retained = $visible;
        $retainedRadius = $this->radius + $this->prefetchRadius;
        for ($chunkX = $x - $retainedRadius; $chunkX <= $x + $retainedRadius; ++$chunkX) {
            for ($chunkZ = $z - $retainedRadius; $chunkZ <= $z + $retainedRadius; ++$chunkZ) {
                $retained[self::key($chunkX, $chunkZ)] = ['x' => $chunkX, 'z' => $chunkZ];
            }
        }

        $released = [];
        foreach ($this->prepared as $key => $coordinate) {
            if (!isset($retained[$key])) {
                $released[] = $coordinate;
                unset($this->prepared[$key]);
            }
        }
        foreach ($this->sent as $key => $coordinate) {
            if (!isset($visible[$key])) {
                unset($this->sent[$key]);
            }
        }

        $pending = [];
        foreach ($visible as $key => $coordinate) {
            if (!isset($this->sent[$key])) {
                $pending[] = $coordinate;
            }
        }
        usort($pending, static function (array $left, array $right) use ($x, $z): int {
            $leftDistance = ($left['x'] - $x) ** 2 + ($left['z'] - $z) ** 2;
            $rightDistance = ($right['x'] - $x) ** 2 + ($right['z'] - $z) ** 2;

            return $leftDistance <=> $rightDistance
                ?: $left['x'] <=> $right['x']
                ?: $left['z'] <=> $right['z'];
        });
        $this->pending = $pending;

        $prefetchPending = [];
        foreach ($retained as $key => $coordinate) {
            if (!isset($visible[$key]) && !isset($this->prepared[$key])) {
                $prefetchPending[] = $coordinate;
            }
        }
        usort($prefetchPending, static function (array $left, array $right) use ($x, $z): int {
            $leftDistance = ($left['x'] - $x) ** 2 + ($left['z'] - $z) ** 2;
            $rightDistance = ($right['x'] - $x) ** 2 + ($right['z'] - $z) ** 2;

            return $leftDistance <=> $rightDistance
                ?: $left['x'] <=> $right['x']
                ?: $left['z'] <=> $right['z'];
        });
        $this->prefetchPending = $prefetchPending;

        return $released;
    }

    /** @return list<array{x: int, z: int}> */
    public function pending(int $maximum): array
    {
        if ($maximum < 1) {
            throw new InvalidArgumentException('Chunk load batch size must be positive.');
        }

        return array_slice($this->pending, 0, $maximum);
    }

    /** @return list<array{x: int, z: int}> */
    public function pendingPrefetch(int $maximum): array
    {
        if ($maximum < 1) {
            throw new InvalidArgumentException('Chunk prefetch batch size must be positive.');
        }

        return array_slice($this->prefetchPending, 0, $maximum);
    }

    public function markPrepared(int $x, int $z): void
    {
        if (!$this->containsRetained($x, $z)) {
            throw new InvalidArgumentException('Cannot prepare a chunk outside the retained view.');
        }
        $key = self::key($x, $z);
        $coordinate = ['x' => $x, 'z' => $z];
        $this->prepared[$key] = $coordinate;
        foreach ($this->prefetchPending as $index => $pending) {
            if ($pending['x'] === $x && $pending['z'] === $z) {
                array_splice($this->prefetchPending, $index, 1);
                break;
            }
        }
    }

    public function markSent(int $x, int $z): void
    {
        $key = self::key($x, $z);
        if (!isset($this->prepared[$key])) {
            throw new InvalidArgumentException('Cannot send a chunk which has not been prepared.');
        }
        foreach ($this->pending as $index => $coordinate) {
            if ($coordinate['x'] === $x && $coordinate['z'] === $z) {
                array_splice($this->pending, $index, 1);
                $this->sent[$key] = $coordinate;

                return;
            }
        }
        if (!isset($this->sent[$key])) {
            throw new InvalidArgumentException('Cannot mark a chunk outside the pending view as sent.');
        }
    }

    public function radius(): int
    {
        return $this->radius;
    }

    public function sentCount(): int
    {
        return count($this->sent);
    }

    public function pendingCount(): int
    {
        return count($this->pending);
    }

    public function prefetchPendingCount(): int
    {
        return count($this->prefetchPending);
    }

    public function preparedCount(): int
    {
        return count($this->prepared);
    }

    public function unpreparedVisibleCount(): int
    {
        $count = 0;
        foreach ($this->pending as $coordinate) {
            if (!isset($this->prepared[self::key($coordinate['x'], $coordinate['z'])])) {
                ++$count;
            }
        }

        return $count;
    }

    public function contains(int $x, int $z): bool
    {
        return $this->centerX !== null && $this->centerZ !== null
            && abs($x - $this->centerX) <= $this->radius
            && abs($z - $this->centerZ) <= $this->radius;
    }

    public function hasSent(int $x, int $z): bool
    {
        return isset($this->sent[self::key($x, $z)]);
    }

    public function isPrepared(int $x, int $z): bool
    {
        return isset($this->prepared[self::key($x, $z)]);
    }

    public function containsRetained(int $x, int $z): bool
    {
        $radius = $this->radius + $this->prefetchRadius;

        return $this->centerX !== null && $this->centerZ !== null
            && abs($x - $this->centerX) <= $radius
            && abs($z - $this->centerZ) <= $radius;
    }

    public function prefetchRadius(): int
    {
        return $this->prefetchRadius;
    }

    /** @return array{x: int, z: int}|null */
    public function center(): ?array
    {
        return $this->centerX === null || $this->centerZ === null
            ? null
            : ['x' => $this->centerX, 'z' => $this->centerZ];
    }

    private static function key(int $x, int $z): string
    {
        return $x . ':' . $z;
    }
}
