<?php

declare(strict_types=1);

namespace Bedriox\Server\Inventory;

use Bedriox\Api\Inventory\ContainerType;
use Bedriox\Server\World\BlockPosition;

/** One resolved storage block (or paired chest) backed by canonical world state. */
final readonly class ResolvedWorldContainer
{
    public function __construct(
        public ContainerType $type,
        public BlockPosition $position,
        public ContainerInventory $inventory,
        public ?BlockPosition $pairedPosition = null,
        public ?string $customName = null,
    ) {}
}
