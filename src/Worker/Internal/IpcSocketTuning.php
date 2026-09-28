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

namespace Bedriox\Server\Worker\Internal;

/** Applies bounded buffering to authenticated loopback worker channels. */
final class IpcSocketTuning
{
    private const int SOCKET_BUFFER_BYTES = 4_194_304;
    private const int STREAM_CHUNK_BYTES = 262_144;

    /** @param resource $stream */
    public static function apply($stream): void
    {
        @stream_set_chunk_size($stream, self::STREAM_CHUNK_BYTES);
        if (!function_exists('socket_import_stream')) {
            return;
        }
        $socket = @socket_import_stream($stream);
        if ($socket === false) {
            return;
        }
        @socket_set_option($socket, SOL_SOCKET, SO_SNDBUF, self::SOCKET_BUFFER_BYTES);
        @socket_set_option($socket, SOL_SOCKET, SO_RCVBUF, self::SOCKET_BUFFER_BYTES);
    }
}
