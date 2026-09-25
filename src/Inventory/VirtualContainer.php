<?php

declare(strict_types=1);

namespace Bedriox\Server\Inventory;

use Bedriox\Api\Inventory\ContainerLayout;

/** Server-owned definition of one plugin-created inventory without a world block. */
final readonly class VirtualContainer
{
    public function __construct(
        public string $identifier,
        public string $ownerPlugin,
        public ContainerLayout $layout,
        public ?string $title,
        public SimpleContainerInventory $inventory,
    ) {}
}
