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

    public function identifier(): string
    {
        return $this->value;
    }
}
