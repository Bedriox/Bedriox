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

namespace Bedriox\Server\Entity\Spawn\Natural;

use Bedriox\Api\Entity\EntityCategory;
use InvalidArgumentException;

final readonly class NaturalSpawnCategoryLimit
{
    public function __construct(
        public EntityCategory $category,
        public int $worldCap,
        public int $localDensityCap,
    ) {
        if ($worldCap < 0 || $worldCap > 100_000 || $localDensityCap < 0 || $localDensityCap > 10_000) {
            throw new InvalidArgumentException('Natural-spawn category limits are outside their supported bounds.');
        }
    }
}
