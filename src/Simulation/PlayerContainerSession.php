<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

use Bedriox\Api\Inventory\ContainerLayout;
use Bedriox\Api\Inventory\ContainerType;
use Bedriox\Api\Processing\EnchantingOption;
use Bedriox\Server\Inventory\ContainerInventory;
use Bedriox\Server\Inventory\ResolvedWorldContainer;
use Bedriox\Server\Player\OpenedContainerInventory;
use Bedriox\Server\Simulation\Command\WorkstationRequest;
use Bedriox\Server\World\BlockPosition;

/** Authoritative open-window state; protocol identity is isolated to this per-player session. */
final class PlayerContainerSession
{
    /** @var array<int, EnchantingOption> Current authoritative options keyed by client-visible network ID. */
    public array $enchantingOptions = [];

    public int $nextEnchantingOptionNetworkId = 1;

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
        public ?WorkstationRequest $workstationRequest = null,
    ) {}
}
