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

namespace Bedriox\Api\ResourcePack;

interface ResourcePackManager
{
    /** Adds a pack to the end of the stack offered to future connections. */
    public function register(ResourcePackDefinition $definition): ResourcePackInfo;

    /** Removes a pack owned by this plugin from future connection offers. */
    public function remove(string $uuid): void;

    /** @return list<ResourcePackInfo> Current stack in application order. */
    public function all(): array;
}
