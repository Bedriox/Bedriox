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

use Bedriox\Server\Player\InventoryStack;

final readonly class SetPluginArmorContents implements WorldCommand
{
    /** @param list<InventoryStack|null> $contents */
    public function __construct(
        public string $session,
        public array $contents,
    ) {}

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        $bytes = 48 + strlen($this->session);
        foreach ($this->contents as $stack) {
            $bytes += $stack === null ? 1 : 32 + strlen($stack->identifier) + strlen($stack->nbt?->toBinary() ?? '');
        }

        return $bytes;
    }
}
