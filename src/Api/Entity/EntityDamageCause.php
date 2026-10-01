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

/** Stable causes shared by authoritative living-entity damage events. */
enum EntityDamageCause: string
{
    case ATTACK = 'attack';
    case FIRE = 'fire';
    case FIRE_TICK = 'fire_tick';
    case KILL = 'kill';
    case MAGIC = 'magic';
    case PROJECTILE = 'projectile';
    case EXPLOSION = 'explosion';
    case DROWNING = 'drowning';
    case THORNS = 'thorns';
    case PLUGIN = 'plugin';
}
