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

namespace Bedriox\Api\Event\Block;

enum BlockStateChangeReason: string
{
    case INTERACTION = 'interaction';
    case NEIGHBOR_UPDATE = 'neighbor_update';
    case SPREAD = 'spread';
    case DECAY = 'decay';
    case HYDRATION = 'hydration';
    case TRAMPLE = 'trample';
    case GROWTH = 'growth';
    case PHYSICS = 'physics';
    case FLUID_FLOW = 'fluid_flow';
    case FLUID_REACTION = 'fluid_reaction';
    case TOOL_TRANSFORM = 'tool_transform';
    case IGNITION = 'ignition';
    case EXPLOSION = 'explosion';
}
