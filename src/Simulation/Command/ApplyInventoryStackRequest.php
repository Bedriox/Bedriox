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

namespace Bedriox\Server\Simulation\Command;

use Bedriox\Server\Player\InventoryResponseMode;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Player\InventoryStackRequestAction;

final readonly class ApplyInventoryStackRequest implements WorldCommand
{
    /** @param list<InventoryStackRequestAction> $actions */
    public function __construct(
        public string $session,
        public int $requestId,
        public array $actions,
        public ?string $rejectionReason = null,
        public InventoryResponseMode $responseMode = InventoryResponseMode::ItemStackResponse,
        public ?InventoryStack $authoritativeCreativeStack = null,
        public ?CraftingRequest $crafting = null,
    ) {}

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        return 32 + strlen($this->session) + strlen($this->rejectionReason ?? '') + count($this->actions) * 48
            + ($this->authoritativeCreativeStack === null ? 0 : 64 + strlen($this->authoritativeCreativeStack->identifier))
            + ($this->crafting === null ? 0 : 16);
    }
}
