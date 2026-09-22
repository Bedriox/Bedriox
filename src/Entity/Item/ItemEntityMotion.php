<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Item;

use InvalidArgumentException;

/** Immutable motion in blocks per simulation tick. */
final readonly class ItemEntityMotion
{
    public function __construct(
        public float $x,
        public float $y,
        public float $z,
    ) {
        foreach ([$x, $y, $z] as $component) {
            if (!is_finite($component) || abs($component) > 100.0) {
                throw new InvalidArgumentException('Item-entity motion must be finite and bounded.');
            }
        }
    }
}
