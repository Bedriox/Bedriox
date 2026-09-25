<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

use Bedriox\Api\Inventory\ContainerLayout;
use Bedriox\Api\Inventory\ContainerType;
use Bedriox\Server\Inventory\ContainerInventory;
use Bedriox\Server\Inventory\ResolvedWorldContainer;
use Bedriox\Server\Player\OpenedContainerInventory;
use Bedriox\Server\World\BlockPosition;

/** Authoritative open-window state; protocol identity is isolated to this per-player session. */
final class PlayerContainerSession
{
    public function __construct(
        public readonly int $windowId,
        public readonly ContainerType $type,
        public readonly ContainerInventory $inventory,
        public OpenedContainerInventory $projection,
        public string $canonicalRevision,
        public readonly ?BlockPosition $position = null,
        public readonly ?BlockPosition $pairedPosition = null,
        public readonly ?string $title = null,
        public readonly ?ContainerLayout $layout = null,
        public readonly ?ResolvedWorldContainer $worldContainer = null,
        public readonly bool $playerOwnedEnderChest = false,
        public readonly ?string $owningPlugin = null,
    ) {}
}
