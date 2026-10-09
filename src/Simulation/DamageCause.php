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

namespace Bedriox\Server\Simulation;

enum DamageCause: string
{
    case Attack = 'attack';
    case Fall = 'fall';
    case FallingBlock = 'falling_block';
    case Kill = 'kill';
    case Plugin = 'plugin';
    case Magic = 'magic';
    case Projectile = 'projectile';
    case Drowning = 'drowning';
    case Fire = 'fire';
    case Explosion = 'explosion';
    case Thorns = 'thorns';
}
