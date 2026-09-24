<?php

declare(strict_types=1);

namespace Bedriox\Api\Inventory;

use InvalidArgumentException;

final readonly class ItemStack
{
    public function __construct(
        public string $identifier,
        public int $count,
        public int $damage = 0,
        public ?ItemNbt $nbt = null,
        public int $auxValue = 0,
    ) {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Item identifier must be canonical and namespaced.');
        }
        if ($count < 1 || $count > 64) {
            throw new InvalidArgumentException('Item count must be between 1 and 64.');
        }
        if ($damage < 0 || $damage > 65_535) {
            throw new InvalidArgumentException('Item damage must be between 0 and 65535.');
        }
        if ($auxValue < 0 || $auxValue > 32_767) {
            throw new InvalidArgumentException('Item auxiliary value must be between 0 and 32767.');
        }
    }
}
