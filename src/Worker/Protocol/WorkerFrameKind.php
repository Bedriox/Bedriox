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

namespace Bedriox\Server\Worker\Protocol;

enum WorkerFrameKind: int
{
    case HELLO = 1;
    case READY = 2;
    case SUBMIT = 3;
    case RESULT = 4;
    case FAILURE = 5;
    case CANCEL = 6;
    case CANCELLED = 7;
    case SHUTDOWN = 8;
    case HEARTBEAT = 9;
}
