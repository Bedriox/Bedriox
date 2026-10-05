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
use Bedriox\Api\Entity\EntityType;
use InvalidArgumentException;

final readonly class NaturalSpawnEntry
{
    public function __construct(
        public EntityType $type,
        public EntityCategory $category,
        public NaturalSpawnRule $rule,
        public int $weight = 1,
        public NaturalSpawnMedium $candidateMedium = NaturalSpawnMedium::GROUND,
    ) {
        if ($weight < 1 || $weight > 1_000) {
            throw new InvalidArgumentException('Natural-spawn entry weight is outside its supported bounds.');
        }
    }
}
