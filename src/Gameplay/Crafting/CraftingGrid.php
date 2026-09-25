<?php

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Crafting;

use Bedriox\Server\Player\InventoryStack;
use InvalidArgumentException;

/** Immutable authoritative two-by-two or three-by-three crafting grid. */
final readonly class CraftingGrid
{
    /** @var list<InventoryStack|null> */
    public array $slots;

    /** @param array<array-key, mixed> $slots */
    public function __construct(public int $width, public int $height, array $slots)
    {
        if ($width < 1 || $width > 3 || $height < 1 || $height > 3
            || !array_is_list($slots) || count($slots) !== $width * $height) {
            throw new InvalidArgumentException('Crafting grid dimensions or slot count are invalid.');
        }
        $validated = [];
        foreach ($slots as $slot) {
            if ($slot !== null && !$slot instanceof InventoryStack) {
                throw new InvalidArgumentException('Crafting grid contains an invalid slot value.');
            }
            $validated[] = $slot;
        }
        $this->slots = $validated;
    }

    public static function empty(int $width, int $height): self
    {
        return new self($width, $height, array_fill(0, $width * $height, null));
    }

    public function slot(int $x, int $y): ?InventoryStack
    {
        if ($x < 0 || $x >= $this->width || $y < 0 || $y >= $this->height) {
            throw new InvalidArgumentException('Crafting grid coordinate is outside its bounds.');
        }

        return $this->slots[$y * $this->width + $x];
    }

    /** @return array<int, InventoryStack> */
    public function occupiedSlots(): array
    {
        $occupied = [];
        foreach ($this->slots as $slot => $stack) {
            if ($stack !== null) {
                $occupied[$slot] = $stack;
            }
        }

        return $occupied;
    }
}
