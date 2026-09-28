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

namespace Bedriox\Api\World;

enum WorldOperationFailureCode: string
{
    case INVALID_ID = 'invalid_id';
    case ALREADY_EXISTS = 'already_exists';
    case NOT_FOUND = 'not_found';
    case STALE_HANDLE = 'stale_handle';
    case DEFAULT_WORLD = 'default_world';
    case OCCUPIED = 'occupied';
    case BUSY = 'busy';
    case CANCELLED = 'cancelled';
    case STORAGE = 'storage';
    case GENERATOR = 'generator';
    case CAPACITY = 'capacity';
    case OWNER_DISABLED = 'owner_disabled';
    case UNKNOWN = 'unknown';
}
