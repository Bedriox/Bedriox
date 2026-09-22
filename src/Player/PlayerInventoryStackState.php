<?php

declare(strict_types=1);

namespace Bedriox\Server\Player;

use Bedriox\Api\Inventory\ItemNbt;
use InvalidArgumentException;

/** Canonical inventory content which is safe to carry across play sessions. */
final readonly class PlayerInventoryStackState
{
    public const int MAX_DAMAGE = 65_535;

    public function __construct(
        public string $identifier,
        public int $count,
        public int $damage = 0,
        public ?ItemNbt $nbt = null,
    ) {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $this->identifier) !== 1) {
            throw new InvalidArgumentException('Inventory identifier must be canonical and namespaced.');
        }
        if ($this->count < 1 || $this->count > 64) {
            throw new InvalidArgumentException('Inventory stack count must be between 1 and 64.');
        }
        if ($this->damage < 0 || $this->damage > self::MAX_DAMAGE) {
            throw new InvalidArgumentException('Inventory stack damage is outside its supported range.');
        }
    }
}
