<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Collision;

use Bedriox\Server\Simulation\Position;

/** Resolves one bounded client-predicted player displacement against authoritative terrain. */
final readonly class PlayerCollisionResolver
{
    private const float EPSILON = 0.0000001;

    public function __construct(private CollisionBoxQuery $query) {}

    public function resolve(
        Position $from,
        Position $requested,
        bool $wasGrounded,
        bool $verticalCollision = false,
    ): PlayerCollisionResult {
        $wantedX = $requested->x - $from->x;
        $wantedY = $requested->y - $from->y;
        $wantedZ = $requested->z - $from->z;
        $initial = PlayerCollisionShape::at($from);
        if (($wasGrounded || $verticalCollision) && abs($wantedY) <= self::EPSILON
            && abs($wantedX) <= 0.5 && abs($wantedZ) <= 0.5) {
            $destination = $initial->offset($wantedX, 0.0, $wantedZ);
            $groundProbe = $destination->offset(0.0, -0.05, 0.0);
            $probeObstacles = $this->loadedBoxes(new AxisAlignedBox(
                $destination->minX,
                $groundProbe->minY,
                $destination->minZ,
                $destination->maxX,
                $destination->maxY,
                $destination->maxZ,
            ));
            if ($probeObstacles === null) {
                return self::terrainUnavailable($from, $wasGrounded);
            }
            $destinationBlocked = false;
            $supported = false;
            foreach ($probeObstacles as $obstacle) {
                $destinationBlocked = $destinationBlocked || $obstacle->intersects($destination);
                $supported = $supported || $obstacle->intersects($groundProbe);
                if ($destinationBlocked && $supported) {
                    break;
                }
            }
            if (!$destinationBlocked && $supported) {
                return new PlayerCollisionResult($requested, false, false, false, false, true, true);
            }
        }
        $obstacles = $this->loadedBoxes(
            $initial->swept($wantedX, $wantedY, $wantedZ)->expanded(0.0, 0.05, 0.0),
        );
        if ($obstacles === null) {
            return self::terrainUnavailable($from, $wasGrounded);
        }
        [$box, $resolvedX, $resolvedY, $resolvedZ] = $this->resolveAxes(
            $initial,
            $wantedX,
            $wantedY,
            $wantedZ,
            $obstacles,
        );

        $stepped = false;
        $blockedHorizontally = self::differs($wantedX, $resolvedX) || self::differs($wantedZ, $resolvedZ);
        $landing = $wantedY < 0.0 && self::differs($wantedY, $resolvedY);
        if (($wasGrounded || $landing) && $blockedHorizontally) {
            $step = $this->resolveStep($initial, $wantedX, $wantedZ);
            if ($step === null) {
                return self::terrainUnavailable($from, $wasGrounded);
            }
            [$stepBox, $stepX, $stepY, $stepZ, $stepObstacles] = $step;
            if (($stepX * $stepX) + ($stepZ * $stepZ) > ($resolvedX * $resolvedX) + ($resolvedZ * $resolvedZ)) {
                $box = $stepBox;
                $resolvedX = $stepX;
                $resolvedY = $stepY;
                $resolvedZ = $stepZ;
                $obstacles = $stepObstacles;
                $stepped = true;
            }
        }

        $grounded = false;
        $groundProbe = $box->offset(0.0, -0.05, 0.0);
        foreach ($obstacles as $obstacle) {
            if ($obstacle->intersects($groundProbe)) {
                $grounded = true;
                break;
            }
        }

        return new PlayerCollisionResult(
            new Position(
                ($box->minX + $box->maxX) / 2.0,
                $box->minY,
                ($box->minZ + $box->maxZ) / 2.0,
            ),
            self::differs($wantedX, $resolvedX),
            self::differs($wantedY, $resolvedY),
            self::differs($wantedZ, $resolvedZ),
            $stepped,
            $grounded,
            false,
            count($obstacles),
        );
    }

    public function isGrounded(Position $position): bool
    {
        $obstacles = $this->loadedBoxes(PlayerCollisionShape::at($position)->offset(0.0, -0.05, 0.0));

        return $obstacles !== null && $obstacles !== [];
    }

    /**
     * @param list<AxisAlignedBox> $obstacles
     * @return array{AxisAlignedBox, float, float, float}
     */
    private function resolveAxes(
        AxisAlignedBox $initial,
        float $wantedX,
        float $wantedY,
        float $wantedZ,
        array $obstacles,
    ): array {
        $box = $initial;
        $resolvedY = $wantedY;
        foreach ($obstacles as $obstacle) {
            $resolvedY = $obstacle->resolveY($box, $resolvedY);
        }
        $box = $box->offset(0.0, $resolvedY, 0.0);

        $resolvedX = $wantedX;
        foreach ($obstacles as $obstacle) {
            $resolvedX = $obstacle->resolveX($box, $resolvedX);
        }
        $box = $box->offset($resolvedX, 0.0, 0.0);

        $resolvedZ = $wantedZ;
        foreach ($obstacles as $obstacle) {
            $resolvedZ = $obstacle->resolveZ($box, $resolvedZ);
        }

        return [$box->offset(0.0, 0.0, $resolvedZ), $resolvedX, $resolvedY, $resolvedZ];
    }

    /** @return array{AxisAlignedBox, float, float, float, list<AxisAlignedBox>}|null */
    private function resolveStep(AxisAlignedBox $initial, float $wantedX, float $wantedZ): ?array
    {
        $obstacles = $this->loadedBoxes(
            $initial->swept($wantedX, PlayerCollisionShape::STEP_HEIGHT, $wantedZ)->expanded(0.0, 0.05, 0.0),
        );
        if ($obstacles === null) {
            return null;
        }
        $box = $initial;
        $up = PlayerCollisionShape::STEP_HEIGHT;
        foreach ($obstacles as $obstacle) {
            $up = $obstacle->resolveY($box, $up);
        }
        $box = $box->offset(0.0, $up, 0.0);

        $resolvedX = $wantedX;
        foreach ($obstacles as $obstacle) {
            $resolvedX = $obstacle->resolveX($box, $resolvedX);
        }
        $box = $box->offset($resolvedX, 0.0, 0.0);

        $resolvedZ = $wantedZ;
        foreach ($obstacles as $obstacle) {
            $resolvedZ = $obstacle->resolveZ($box, $resolvedZ);
        }
        $box = $box->offset(0.0, 0.0, $resolvedZ);

        $down = -$up;
        foreach ($obstacles as $obstacle) {
            $down = $obstacle->resolveY($box, $down);
        }

        return [$box->offset(0.0, $down, 0.0), $resolvedX, $up + $down, $resolvedZ, $obstacles];
    }

    /** @return list<AxisAlignedBox>|null */
    private function loadedBoxes(AxisAlignedBox $area): ?array
    {
        return $this->query instanceof LoadedCollisionBoxQuery
            ? $this->query->boxesIntersectingLoaded($area)
            : $this->query->boxesIntersecting($area);
    }

    private static function terrainUnavailable(Position $position, bool $grounded): PlayerCollisionResult
    {
        return new PlayerCollisionResult(
            $position,
            false,
            false,
            false,
            false,
            $grounded,
            false,
            0,
            false,
        );
    }

    private static function differs(float $wanted, float $resolved): bool
    {
        return abs($wanted - $resolved) > self::EPSILON;
    }
}
