<?php

declare(strict_types=1);

namespace Bedriox\Api\Crafting;

use Bedriox\Api\Inventory\ItemStack;
use InvalidArgumentException;

/** Immutable personal or crafting-table grid snapshot. */
final readonly class CraftingGrid
{
    /** @var list<ItemStack|null> */
    public array $slots;

    /** @param array<array-key, mixed> $slots ItemStack|null values in row-major order. */
    public function __construct(public int $width, public int $height, array $slots)
    {
        if (!array_is_list($slots) || !in_array($width, [2, 3], true)
            || $height !== $width || count($slots) !== $width * $height) {
            throw new InvalidArgumentException('Crafting grid must be a complete two-by-two or three-by-three snapshot.');
        }
        $validated = [];
        foreach ($slots as $slot) {
            if ($slot !== null && !$slot instanceof ItemStack) {
                throw new InvalidArgumentException('Crafting grid contains an invalid slot value.');
            }
            $validated[] = $slot;
        }
        $this->slots = $validated;
    }

    public function stackAt(int $x, int $y): ?ItemStack
    {
        if ($x < 0 || $x >= $this->width || $y < 0 || $y >= $this->height) {
            throw new InvalidArgumentException('Crafting grid coordinate is outside the grid.');
        }

        return $this->slots[$y * $this->width + $x];
    }
}
