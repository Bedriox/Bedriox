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

namespace Bedriox\Server\Entity;

use Bedriox\Api\Entity\EntityType;
use Bedriox\Api\Entity\VanillaEntityType;

final class AquaticBucketRegistry
{
    /** @var array<string, VanillaEntityType> */
    private const array TYPES_BY_BUCKET = [
        'minecraft:cod_bucket' => VanillaEntityType::COD,
        'minecraft:salmon_bucket' => VanillaEntityType::SALMON,
        'minecraft:tropical_fish_bucket' => VanillaEntityType::TROPICAL_FISH,
        'minecraft:pufferfish_bucket' => VanillaEntityType::PUFFERFISH,
        'minecraft:axolotl_bucket' => VanillaEntityType::AXOLOTL,
    ];

    public static function typeForBucket(string $itemIdentifier): ?VanillaEntityType
    {
        return self::TYPES_BY_BUCKET[$itemIdentifier] ?? null;
    }

    public static function bucketForType(EntityType $type): ?string
    {
        foreach (self::TYPES_BY_BUCKET as $bucket => $candidate) {
            if ($candidate === $type) {
                return $bucket;
            }
        }

        return null;
    }
}
