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

namespace Bedriox\Server\Gameplay\Processing;

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Nbt\Tag;
use Bedriox\Api\Processing\CartographyOperation;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;

/** Produces bounded map mutations; map storage allocation remains owned by the world service. */
final readonly class CartographyProcessor
{
    public function process(
        ContainerItemStack $map,
        ?ContainerItemStack $addition,
        CartographyOperation $operation,
        ?string $name = null,
    ): ?WorkstationResult {
        if (!in_array($map->identifier, ['minecraft:filled_map', 'minecraft:map'], true)) {
            return null;
        }
        $nbt = $map->nbt ?? ItemNbt::empty();
        $outputs = [];
        $consumed = [0 => 1];
        switch ($operation) {
            case CartographyOperation::CLONE:
                if ($addition?->identifier !== 'minecraft:empty_map') {
                    return null;
                }
                $consumed[1] = 1;
                $outputs[] = new ContainerItemStack($map->identifier, 2, $map->damage, $nbt, $map->auxValue);
                break;
            case CartographyOperation::SCALE:
                if ($addition?->identifier !== 'minecraft:paper' || ($nbt->int('map_scale') ?? 0) >= 4
                    || ($nbt->int('map_locked') ?? 0) !== 0) {
                    return null;
                }
                $consumed[1] = 1;
                $outputs[] = new ContainerItemStack(
                    $map->identifier,
                    1,
                    $map->damage,
                    $nbt->withTag('map_scale', Tag::int(($nbt->int('map_scale') ?? 0) + 1)),
                    $map->auxValue,
                );
                break;
            case CartographyOperation::LOCK:
                if ($addition?->identifier !== 'minecraft:glass_pane' || ($nbt->int('map_locked') ?? 0) !== 0) {
                    return null;
                }
                $consumed[1] = 1;
                $outputs[] = new ContainerItemStack(
                    $map->identifier,
                    1,
                    $map->damage,
                    $nbt->withTag('map_locked', Tag::int(1)),
                    $map->auxValue,
                );
                break;
            case CartographyOperation::RENAME:
                $renamed = WorkstationItemData::withDisplayName($nbt, $name);
                if ($renamed === null || $renamed->equals($nbt)) {
                    return null;
                }
                $outputs[] = new ContainerItemStack($map->identifier, 1, $map->damage, $renamed, $map->auxValue);
                break;
        }
        return new WorkstationResult($consumed, $outputs);
    }
}
