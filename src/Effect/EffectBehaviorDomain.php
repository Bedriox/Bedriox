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

namespace Bedriox\Server\Effect;

/** Authoritative systems affected by an active vanilla effect. @internal */
enum EffectBehaviorDomain
{
    case MOVEMENT;
    case MINING;
    case COMBAT;
    case PERIODIC_HEALTH;
    case NUTRITION;
    case FIRE;
    case BREATHING;
    case HEALTH_CAPACITY;
    case ABSORPTION;
    case VISIBILITY;
    case CLIENT_PRESENTATION;
    case WORLD_TRIGGER;
}
