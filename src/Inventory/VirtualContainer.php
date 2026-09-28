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
