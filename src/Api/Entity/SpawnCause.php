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

enum SpawnCause: string
{
    case SPAWN_EGG = 'spawn_egg';
    case COMMAND = 'command';
    case PLUGIN = 'plugin';
    case NATURAL = 'natural';
    case SPAWNER = 'spawner';
    case BREEDING = 'breeding';
    case STRUCTURE = 'structure';
    case CHUNK_LOAD = 'chunk_load';
    case EFFECT = 'effect';
}
