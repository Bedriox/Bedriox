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

namespace Bedriox\Api\Entity;

/**
 * Built-in entity identities. The complete current-version catalog is admitted
 * by Data; cases are added here only when Bedriox implements their gameplay.
 */
enum VanillaEntityType: string implements VanillaEntityIdentity
{
    case COW = 'minecraft:cow';
    case CHICKEN = 'minecraft:chicken';
    case PIG = 'minecraft:pig';
    case RABBIT = 'minecraft:rabbit';
    case SHEEP = 'minecraft:sheep';
    case SKELETON = 'minecraft:skeleton';
    case ZOMBIE = 'minecraft:zombie';
    case HUSK = 'minecraft:husk';
    case ZOMBIE_VILLAGER = 'minecraft:zombie_villager_v2';
    case STRAY = 'minecraft:stray';
    case BOGGED = 'minecraft:bogged';
    case PARCHED = 'minecraft:parched';
    case WITHER_SKELETON = 'minecraft:wither_skeleton';
    case SPIDER = 'minecraft:spider';
    case CAVE_SPIDER = 'minecraft:cave_spider';
    case CREEPER = 'minecraft:creeper';
    case SLIME = 'minecraft:slime';
    case MAGMA_CUBE = 'minecraft:magma_cube';
    case ENDERMAN = 'minecraft:enderman';
    case ENDERMITE = 'minecraft:endermite';
    case SILVERFISH = 'minecraft:silverfish';
    case WITCH = 'minecraft:witch';

    public function identifier(): string
    {
        return $this->value;
    }
}
