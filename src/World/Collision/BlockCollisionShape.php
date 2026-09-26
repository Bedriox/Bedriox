<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Collision;

use InvalidArgumentException;

/** Immutable block-local collision geometry. */
final readonly class BlockCollisionShape
{
    /** @var list<AxisAlignedBox> */
    private array $boxes;

    /** @param list<AxisAlignedBox> $boxes */
    private function __construct(array $boxes)
    {
        if (count($boxes) > 16) {
            throw new InvalidArgumentException('A block collision shape cannot contain more than 16 boxes.');
        }
        foreach ($boxes as $box) {
            if ($box->minX < 0.0 || $box->minY < 0.0 || $box->minZ < 0.0
                || $box->maxX > 1.0 || $box->maxY > 1.0 || $box->maxZ > 1.0) {
                throw new InvalidArgumentException('Block collision boxes must remain inside their local cell.');
            }
        }
        $this->boxes = $boxes;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public static function fullCube(): self
    {
        return new self([new AxisAlignedBox(0.0, 0.0, 0.0, 1.0, 1.0, 1.0)]);
    }

    /** @param list<AxisAlignedBox> $boxes */
    public static function fromBoxes(array $boxes): self
    {
        return new self($boxes);
    }

    /** @return list<AxisAlignedBox> */
    public function boxesAt(int $x, int $y, int $z): array
    {
        return array_map(
            static fn(AxisAlignedBox $box): AxisAlignedBox => $box->offset($x, $y, $z),
            $this->boxes,
        );
    }

    public function isEmpty(): bool
    {
        return $this->boxes === [];
    }

    /** Returns the highest local collision surface, or null for a non-colliding block. */
    public function highestY(): ?float
    {
        $highest = null;
        foreach ($this->boxes as $box) {
            $highest = $highest === null ? $box->maxY : max($highest, $box->maxY);
        }

        return $highest;
    }
}
