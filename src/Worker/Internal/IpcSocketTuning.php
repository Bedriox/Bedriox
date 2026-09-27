<?php

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
