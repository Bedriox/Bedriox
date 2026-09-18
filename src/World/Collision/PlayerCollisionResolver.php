<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Collision;

use Bedriox\Server\Simulation\Position;

/** Resolves one bounded client-predicted player displacement against authoritative terrain. */
final readonly class PlayerCollisionResolver
{
    private const float EPSILON = 0.0000001;

    public function __construct(private CollisionBoxQuery $query) {}

    public function resolve(Position $from, Position $requested, bool $wasGrounded): PlayerCollisionResult
    {
        $wantedX = $requested->x - $from->x;
        $wantedY = $requested->y - $from->y;
        $wantedZ = $requested->z - $from->z;
        $initial = PlayerCollisionShape::at($from);
        [$box, $resolvedX, $resolvedY, $resolvedZ] = $this->resolveAxes(
            $initial,
            $wantedX,
            $wantedY,
            $wantedZ,
            $this->query->boxesIntersecting($initial->swept($wantedX, $wantedY, $wantedZ)),
        );

        $stepped = false;
        $blockedHorizontally = self::differs($wantedX, $resolvedX) || self::differs($wantedZ, $resolvedZ);
        $landing = $wantedY < 0.0 && self::differs($wantedY, $resolvedY);
        if (($wasGrounded || $landing) && $blockedHorizontally) {
            [$stepBox, $stepX, $stepY, $stepZ] = $this->resolveStep($initial, $wantedX, $wantedZ);
            if (($stepX * $stepX) + ($stepZ * $stepZ) > ($resolvedX * $resolvedX) + ($resolvedZ * $resolvedZ)) {
                $box = $stepBox;
                $resolvedX = $stepX;
                $resolvedY = $stepY;
                $resolvedZ = $stepZ;
                $stepped = true;
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
        );
    }

    public function isGrounded(Position $position): bool
    {
        return $this->query->hasCollision(PlayerCollisionShape::at($position)->offset(0.0, -0.05, 0.0));
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

    /** @return array{AxisAlignedBox, float, float, float} */
    private function resolveStep(AxisAlignedBox $initial, float $wantedX, float $wantedZ): array
    {
        $obstacles = $this->query->boxesIntersecting(
            $initial->swept($wantedX, PlayerCollisionShape::STEP_HEIGHT, $wantedZ),
        );
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

        return [$box->offset(0.0, $down, 0.0), $resolvedX, $up + $down, $resolvedZ];
    }

    private static function differs(float $wanted, float $resolved): bool
    {
        return abs($wanted - $resolved) > self::EPSILON;
    }
}
