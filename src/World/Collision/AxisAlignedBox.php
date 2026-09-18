<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Collision;

use InvalidArgumentException;

/** Immutable axis-aligned collision volume. */
final readonly class AxisAlignedBox
{
    public function __construct(
        public float $minX,
        public float $minY,
        public float $minZ,
        public float $maxX,
        public float $maxY,
        public float $maxZ,
    ) {
        foreach ([$minX, $minY, $minZ, $maxX, $maxY, $maxZ] as $coordinate) {
            if (!is_finite($coordinate)) {
                throw new InvalidArgumentException('Collision-box coordinates must be finite.');
            }
        }
        if ($minX > $maxX || $minY > $maxY || $minZ > $maxZ) {
            throw new InvalidArgumentException('Collision-box minimums cannot exceed maximums.');
        }
    }

    public static function unitAt(int $x, int $y, int $z): self
    {
        return new self($x, $y, $z, $x + 1, $y + 1, $z + 1);
    }

    public function offset(float $x, float $y, float $z): self
    {
        return new self(
            $this->minX + $x,
            $this->minY + $y,
            $this->minZ + $z,
            $this->maxX + $x,
            $this->maxY + $y,
            $this->maxZ + $z,
        );
    }

    /** Returns the complete volume swept by the requested displacement. */
    public function swept(float $x, float $y, float $z): self
    {
        return new self(
            $this->minX + min(0.0, $x),
            $this->minY + min(0.0, $y),
            $this->minZ + min(0.0, $z),
            $this->maxX + max(0.0, $x),
            $this->maxY + max(0.0, $y),
            $this->maxZ + max(0.0, $z),
        );
    }

    public function expanded(float $x, float $y, float $z): self
    {
        return new self(
            $this->minX - $x,
            $this->minY - $y,
            $this->minZ - $z,
            $this->maxX + $x,
            $this->maxY + $y,
            $this->maxZ + $z,
        );
    }

    public function intersects(self $other): bool
    {
        return $other->maxX > $this->minX && $other->minX < $this->maxX
            && $other->maxY > $this->minY && $other->minY < $this->maxY
            && $other->maxZ > $this->minZ && $other->minZ < $this->maxZ;
    }

    public function resolveX(self $moving, float $wanted): float
    {
        if ($moving->maxY <= $this->minY || $moving->minY >= $this->maxY
            || $moving->maxZ <= $this->minZ || $moving->minZ >= $this->maxZ) {
            return $wanted;
        }
        if ($wanted > 0.0 && $moving->maxX <= $this->minX) {
            return min($wanted, $this->minX - $moving->maxX);
        }
        if ($wanted < 0.0 && $moving->minX >= $this->maxX) {
            return max($wanted, $this->maxX - $moving->minX);
        }

        return $wanted;
    }

    public function resolveY(self $moving, float $wanted): float
    {
        if ($moving->maxX <= $this->minX || $moving->minX >= $this->maxX
            || $moving->maxZ <= $this->minZ || $moving->minZ >= $this->maxZ) {
            return $wanted;
        }
        if ($wanted > 0.0 && $moving->maxY <= $this->minY) {
            return min($wanted, $this->minY - $moving->maxY);
        }
        if ($wanted < 0.0 && $moving->minY >= $this->maxY) {
            return max($wanted, $this->maxY - $moving->minY);
        }

        return $wanted;
    }

    public function resolveZ(self $moving, float $wanted): float
    {
        if ($moving->maxX <= $this->minX || $moving->minX >= $this->maxX
            || $moving->maxY <= $this->minY || $moving->minY >= $this->maxY) {
            return $wanted;
        }
        if ($wanted > 0.0 && $moving->maxZ <= $this->minZ) {
            return min($wanted, $this->minZ - $moving->maxZ);
        }
        if ($wanted < 0.0 && $moving->minZ >= $this->maxZ) {
            return max($wanted, $this->maxZ - $moving->minZ);
        }

        return $wanted;
    }
}
