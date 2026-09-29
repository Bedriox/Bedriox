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

namespace Bedriox\Server\Gameplay\Potion;

use Bedriox\Api\Potion\PotionContainer;
use Bedriox\Api\Potion\PotionType;

final readonly class PotionCatalog
{
    public function resolve(string $itemIdentifier, int $auxValue): ?PotionStack
    {
        $container = PotionContainer::tryFrom($itemIdentifier);
        $type = PotionType::tryFrom($auxValue);

        return $container !== null && $type !== null ? new PotionStack($container, $type) : null;
    }
}
