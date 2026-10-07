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

namespace Bedriox\Server\Transport\Process;

enum TransportProcessFrameKind: int
{
    case READY = 1;
    case SEND_PAYLOAD = 2;
    case REMOVE_SESSION = 3;
    case SHUTDOWN = 4;
    case SESSION_OPENED = 5;
    case SESSION_CLOSED = 6;
    case RECEIVED_PAYLOAD = 7;
    case HANDSHAKE_DIAGNOSTICS = 8;
    case FAILURE = 9;
    case SECURITY_SNAPSHOT = 10;
}
