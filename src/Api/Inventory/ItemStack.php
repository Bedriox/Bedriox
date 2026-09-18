<?php

declare(strict_types=1);

namespace Bedriox\Api\Inventory;

use InvalidArgumentException;

final readonly class ItemStack
{
    public function __construct(
        public string $identifier,
        public int $count,
    ) {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Item identifier must be canonical and namespaced.');
        }
        if ($count < 1 || $count > 64) {
            throw new InvalidArgumentException('Item count must be between 1 and 64.');
        }
    }
}
