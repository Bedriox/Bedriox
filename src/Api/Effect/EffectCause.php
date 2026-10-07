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

namespace Bedriox\Api\Effect;

enum EffectCause: string
{
    case PLUGIN = 'plugin';
    case COMMAND = 'command';
    case POTION = 'potion';
    case SPLASH_POTION = 'splash_potion';
    case LINGERING_POTION = 'lingering_potion';
    case TIPPED_ARROW = 'tipped_arrow';
    case FOOD = 'food';
    case TOTEM = 'totem';
    case BEACON = 'beacon';
    case ENTITY_ATTACK = 'entity_attack';
    case ENVIRONMENT = 'environment';
    case MILK = 'milk';
    case DEATH = 'death';
    case EXPIRATION = 'expiration';
}
