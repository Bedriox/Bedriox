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

namespace Bedriox\Server\Observability\Internal;

use Bedriox\Server\Observability\RotatingFileLog;
use Bedriox\Server\Worker\Protocol\WorkerFrame;
use Bedriox\Server\Worker\Protocol\WorkerFrameCodec;
use Bedriox\Server\Worker\Protocol\WorkerFrameKind;
use Throwable;

final class LogWriterProcessProgram
{
    public const int TASK_TYPE = 32_001;
    public const int SCHEMA_VERSION = 1;

    public static function run(string $epochHex, string $applicationVersion, string $endpoint, string $tokenHex): int
    {
        $epoch = hex2bin($epochHex);
        $token = hex2bin($tokenHex);
        if (!is_string($epoch) || strlen($epoch) !== 16 || !is_string($token) || strlen($token) !== 32
            || $applicationVersion === '' || strlen($applicationVersion) > 128) {
            return 64;
        }
        $connection = @stream_socket_client($endpoint, $errorCode, $errorMessage, 5, STREAM_CLIENT_CONNECT);
        if (!is_resource($connection)) {
            return 69;
        }
        stream_set_blocking($connection, true);
        self::write($connection, $token);
        $codec = new WorkerFrameCodec();
        $hello = self::readFrame($connection, $codec);
        $nonce = $hello?->metadata['nonce'] ?? null;
        if ($hello === null || $hello->kind !== WorkerFrameKind::HELLO || !hash_equals($epoch, $hello->epoch)
            || !is_string($nonce) || strlen($nonce) !== 32
            || ($hello->metadata['service'] ?? null) !== 'ordered-log'
            || ($hello->metadata['application_version'] ?? null) !== $applicationVersion) {
            return 65;
        }
        try {
            $config = (new LogWriterConfigCodec())->decode($hello->payload);
            $file = new RotatingFileLog($config['path'], $config['maximum_bytes'], $config['history']);
        } catch (Throwable) {
            return 66;
        }
        self::write($connection, $codec->encode(new WorkerFrame(
            WorkerFrameKind::READY,
            $epoch,
            metadata: self::identity($applicationVersion, $nonce),
        )));

        while (($frame = self::readFrame($connection, $codec)) !== null) {
            if (!hash_equals($epoch, $frame->epoch)) {
                return 67;
            }
            if ($frame->kind === WorkerFrameKind::SHUTDOWN) {
                self::write($connection, $codec->encode(new WorkerFrame(WorkerFrameKind::RESULT, $epoch)));

                return 0;
            }
            if ($frame->kind !== WorkerFrameKind::SUBMIT || $frame->taskTypeId !== self::TASK_TYPE
                || $frame->schemaVersion !== self::SCHEMA_VERSION || strlen($frame->payload) > 262_144) {
                return 68;
            }
            try {
                $file->write($frame->payload);
                self::write($connection, $codec->encode(new WorkerFrame(
                    WorkerFrameKind::RESULT,
                    $epoch,
                    $frame->taskId,
                    self::TASK_TYPE,
                    self::SCHEMA_VERSION,
                )));
            } catch (Throwable) {
                self::write($connection, $codec->encode(new WorkerFrame(
                    WorkerFrameKind::FAILURE,
                    $epoch,
                    $frame->taskId,
                    self::TASK_TYPE,
                    self::SCHEMA_VERSION,
                    metadata: ['code' => 'log-write-failed'],
                )));

                return 74;
            }
        }

        return 0;
    }

    /** @return array<string, string> */
    public static function identity(string $applicationVersion, string $nonce): array
    {
        return [
            'application_version' => $applicationVersion,
            'nonce' => $nonce,
            'service' => 'ordered-log',
        ];
    }

    /** @param resource $stream */
    private static function readFrame($stream, WorkerFrameCodec $codec): ?WorkerFrame
    {
        $header = self::readExact($stream, WorkerFrameCodec::FIXED_HEADER_BYTES);
        if ($header === null) {
            return null;
        }
        $lengths = $codec->lengths($header);
        if ($lengths === null) {
            return null;
        }
        $body = self::readExact($stream, $lengths['headerLength'] + $lengths['payloadLength']);

        return $body === null ? null : $codec->decode($header . $body);
    }

    /** @param resource $stream */
    private static function readExact($stream, int $length): ?string
    {
        if ($length < 0) {
            return null;
        }
        if ($length === 0) {
            return '';
        }
        $bytes = '';
        while (strlen($bytes) < $length && !feof($stream)) {
            $chunk = @fread($stream, max(1, $length - strlen($bytes)));
            if ($chunk === false) {
                $metadata = stream_get_meta_data($stream);
                if ($metadata['timed_out']) {
                    continue;
                }

                return null;
            }
            if ($chunk === '') {
                usleep(1_000);
                continue;
            }
            $bytes .= $chunk;
        }

        return strlen($bytes) === $length ? $bytes : null;
    }

    /** @param resource $stream */
    private static function write($stream, string $bytes): void
    {
        while ($bytes !== '') {
            $written = fwrite($stream, $bytes);
            if (!is_int($written) || $written < 1) {
                exit(74);
            }
            $bytes = substr($bytes, $written);
        }
        fflush($stream);
    }
}
