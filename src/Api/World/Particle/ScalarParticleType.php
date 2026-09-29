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

namespace Bedriox\Api\World\Particle;

/** Particles carrying a bounded semantic scale, lifetime, or force-field value. */
enum ScalarParticleType: string
{
    case BLOCK_FORCE_FIELD = 'block_force_field';
    case CRITICAL = 'critical';
    case HEART = 'heart';
    case INK = 'ink';
    case REDSTONE = 'redstone';
    case SMOKE = 'smoke';
}
