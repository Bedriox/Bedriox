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

namespace Bedriox\Server\Worker;

enum WorkerRejectionReason: string
{
    case DISABLED = 'disabled';
    case SHUTTING_DOWN = 'shutting-down';
    case UNKNOWN_TASK_TYPE = 'unknown-task-type';
    case INVALID_INPUT = 'invalid-input';
    case TASK_LIMIT = 'task-limit';
    case BYTE_LIMIT = 'byte-limit';
    case UNAVAILABLE_BROKER = 'unavailable-broker';
}
