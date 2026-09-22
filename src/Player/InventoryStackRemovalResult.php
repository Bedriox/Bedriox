<?php

declare(strict_types=1);

namespace Bedriox\Server\Player;

final readonly class InventoryStackRemovalResult
{
    public function __construct(
        public bool $success,
        public ?InventoryStack $removed = null,
        public bool $selectedStackChanged = false,
        public string $reason = '',
    ) {}
}
