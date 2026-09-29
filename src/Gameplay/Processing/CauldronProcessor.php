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

use Bedriox\Api\Processing\CauldronContentType;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;

/** Resolves bucket, bottle and leather-washing interactions against one authoritative cauldron state. */
final readonly class CauldronProcessor
{
    private const int BOTTLE_LEVELS = 2;

    public function interact(CauldronState $state, ContainerItemStack $held): ?CauldronResult
    {
        if ($held->identifier === 'minecraft:water_bucket') {
            if ($state->content === CauldronContentType::WATER && $state->level === 6) {
                return null;
            }
            return new CauldronResult(
                new CauldronState(CauldronContentType::WATER, 6),
                new ContainerItemStack('minecraft:bucket', 1),
            );
        }
        if ($held->identifier === 'minecraft:lava_bucket') {
            if ($state->content === CauldronContentType::LAVA) {
                return null;
            }
            return new CauldronResult(
                new CauldronState(CauldronContentType::LAVA, 6),
                new ContainerItemStack('minecraft:bucket', 1),
            );
        }
        if ($held->identifier === 'minecraft:powder_snow_bucket') {
            if ($state->content === CauldronContentType::POWDER_SNOW) {
                return null;
            }
            return new CauldronResult(
                new CauldronState(CauldronContentType::POWDER_SNOW, 6),
                new ContainerItemStack('minecraft:bucket', 1),
            );
        }
        if ($held->identifier === 'minecraft:bucket' && $state->level === 6) {
            $filled = match ($state->content) {
                CauldronContentType::WATER => 'minecraft:water_bucket',
                CauldronContentType::LAVA => 'minecraft:lava_bucket',
                CauldronContentType::POWDER_SNOW => 'minecraft:powder_snow_bucket',
                default => null,
            };
            return $filled === null ? null : new CauldronResult(CauldronState::empty(), new ContainerItemStack($filled, 1));
        }
        if ($held->identifier === 'minecraft:glass_bottle'
            && ($state->content === CauldronContentType::WATER || $state->content === CauldronContentType::POTION)
            && $state->level >= self::BOTTLE_LEVELS) {
            $level = $state->level - self::BOTTLE_LEVELS;
            $next = $level === 0 ? CauldronState::empty() : new CauldronState($state->content, $level, $state->potionAuxValue);
            return new CauldronResult(
                $next,
                new ContainerItemStack('minecraft:potion', 1, auxValue: $state->potionAuxValue ?? 0),
            );
        }
        if ($held->identifier === 'minecraft:potion' && $state->level <= 4
            && ($state->content === CauldronContentType::EMPTY
                || ($state->content === CauldronContentType::POTION && $state->potionAuxValue === $held->auxValue))) {
            return new CauldronResult(
                new CauldronState(CauldronContentType::POTION, $state->level + self::BOTTLE_LEVELS, $held->auxValue),
                new ContainerItemStack('minecraft:glass_bottle', 1),
            );
        }
        if ($state->content === CauldronContentType::WATER && $state->level >= self::BOTTLE_LEVELS
            && str_starts_with($held->identifier, 'minecraft:leather_') && $held->nbt?->tag('customColor') !== null) {
            $level = $state->level - self::BOTTLE_LEVELS;
            return new CauldronResult(
                $level === 0 ? CauldronState::empty() : new CauldronState(CauldronContentType::WATER, $level),
                new ContainerItemStack(
                    $held->identifier,
                    1,
                    $held->damage,
                    $held->nbt->withoutTag('customColor'),
                    $held->auxValue,
                ),
            );
        }
        return null;
    }
}
