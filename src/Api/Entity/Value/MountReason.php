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

namespace Bedriox\Api\Entity\Value;

enum MountReason: string
{
    case INTERACTION = 'interaction';
    case PLUGIN = 'plugin';
    case DISMOUNT_INPUT = 'dismount_input';
    case DEATH = 'death';
    case TELEPORT = 'teleport';
    case WORLD_CHANGE = 'world_change';
    case DISCONNECT = 'disconnect';
    case VEHICLE_REMOVED = 'vehicle_removed';
}
