<?php

declare(strict_types=1);

namespace Bedriox\Server\Player;

use InvalidArgumentException;

/** Canonical inventory content which is safe to carry across play sessions. */
final readonly class PlayerInventoryStackState
{
    public function __construct(
        public string $identifier,
        public int $count,
    ) {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $this->identifier) !== 1) {
            throw new InvalidArgumentException('Inventory identifier must be canonical and namespaced.');
        }
        if ($this->count < 1 || $this->count > 64) {
            throw new InvalidArgumentException('Inventory stack count must be between 1 and 64.');
        }
    }
}
