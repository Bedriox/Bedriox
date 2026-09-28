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

namespace Bedriox\Server\Transport;

/** Application-owned Bedrock compression policy shared by login, play, and prepared chunks. */
final class NetworkCompressionPolicy
{
    /** Keep latency-sensitive control and movement batches off the main-thread deflater. */
    public const int THRESHOLD_BYTES = 4_096;

    /** Process IPC costs more than local compression for medium real-time batches. */
    public const int MINIMUM_WORKER_OFFLOAD_BYTES = 131_072;

    private function __construct() {}
}
