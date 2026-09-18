<?php

declare(strict_types=1);

namespace Bedriox\Server\Player;

final readonly class InventoryStackRequestResult
{
    /** @param list<InventorySlotReference> $affectedSlots */
    public function __construct(
        public bool $success,
        public array $affectedSlots = [],
        public string $reason = '',
        public bool $selectedStackChanged = false,
    ) {}
}
