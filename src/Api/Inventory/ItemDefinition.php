<?php

declare(strict_types=1);

namespace Bedriox\Api\Inventory;

use InvalidArgumentException;

/** Plugin-facing behavior for an item already known to the active Bedrock client data. */
final readonly class ItemDefinition
{
    public function __construct(
        public string $identifier,
        public int $maximumStackSize = 64,
        public bool $creative = true,
    ) {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Item definition identifier must be canonical and namespaced.');
        }
        if ($maximumStackSize < 1 || $maximumStackSize > 64) {
            throw new InvalidArgumentException('Item definition stack size must be between 1 and 64.');
        }
    }
}
