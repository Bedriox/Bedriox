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

namespace Bedriox\Api\Inventory;

use LogicException;

/** @internal */
final class UnavailableItemRegistrar implements ItemRegistrar
{
    public function register(ItemDefinition $definition, bool $replace = false): void
    {
        throw new LogicException('Item registration is unavailable in this plugin context.');
    }

    public function registerBehavior(
        string $identifier,
        ItemBehaviorDefinition $definition,
        bool $replace = false,
    ): void {
        throw new LogicException('Item behavior registration is unavailable in this plugin context.');
    }
}
