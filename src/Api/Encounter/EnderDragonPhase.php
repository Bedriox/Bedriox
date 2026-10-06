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

namespace Bedriox\Api\Encounter;

enum EnderDragonPhase: string
{
    case HOLDING = 'holding';
    case CIRCLING = 'circling';
    case STRAFING = 'strafing';
    case LANDING = 'landing';
    case PERCHED = 'perched';
    case BREATHING = 'breathing';
    case CHARGING = 'charging';
    case TAKEOFF = 'takeoff';
    case DYING = 'dying';
}
