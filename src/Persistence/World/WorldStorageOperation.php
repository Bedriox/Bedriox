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

namespace Bedriox\Server\Persistence\World;

enum WorldStorageOperation: int
{
    case LOAD_CHUNK = 32_101;
    case SAVE_CHUNK = 32_102;
    case SAVE_WORLD_DATA = 32_103;
    case LOAD_ENTITY_CHUNK = 32_104;
    case SAVE_ENTITY_CHUNK = 32_105;
    case TRANSFER_ENTITY_OWNERSHIP = 32_106;
    case LOAD_TRANSIENT_ENTITIES = 32_107;
    case SAVE_TRANSIENT_ENTITIES = 32_108;
}
