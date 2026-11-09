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

final readonly class UnavailableResourcePackManager implements ResourcePackManager
{
    public function register(ResourcePackDefinition $definition): ResourcePackInfo
    {
        throw new \LogicException('The resource-pack capability is unavailable.');
    }

    public function remove(string $uuid): void
    {
        throw new \LogicException('The resource-pack capability is unavailable.');
    }

    public function all(): array
    {
        return [];
    }
}
