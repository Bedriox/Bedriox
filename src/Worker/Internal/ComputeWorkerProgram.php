<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Internal;

use Bedriox\Server\Worker\CoreWorkerTaskCatalog;
use Bedriox\Server\Worker\Protocol\WorkerFrame;
use Bedriox\Server\Worker\Protocol\WorkerFrameCodec;
use Bedriox\Server\Worker\Protocol\WorkerFrameDecoder;
use Bedriox\Server\Worker\Protocol\WorkerFrameKind;
use Bedriox\Server\Worker\WorkerIdentity;
use Throwable;

final class ComputeWorkerProgram
{
    public static function run(string $epochHex, string $applicationVersion, ?string $endpoint = null, ?string $tokenHex = null): int
    {
        $epoch = hex2bin($epochHex);
        if (!is_string($epoch) || strlen($epoch) !== 16) {
            return 64;
        }
        $registry = CoreWorkerTaskCatalog::create();
        $stream = STDIN;
        if ($endpoint !== null && $tokenHex !== null) {
            $token = hex2bin($tokenHex);
            $connection = is_string($token) ? @stream_socket_client($endpoint, $errorCode, $errorMessage, 5) : false;
            if (!is_resource($connection) || !self::writeTo($connection, $token)) {
                return 68;
            }
            $stream = $connection;
        }
        IpcSocketTuning::apply($stream);
        stream_set_blocking($stream, true);
        $identity = WorkerIdentity::current($applicationVersion, $registry);
        $codec = new WorkerFrameCodec();
        $decoder = new WorkerFrameDecoder($codec, 33_619_968);
        $hello = self::readFrame($decoder, $stream);
        if ($hello === null || $hello->kind !== WorkerFrameKind::HELLO || !hash_equals($epoch, $hello->epoch)
            || $hello->metadata !== $identity->metadata((string) ($hello->metadata['nonce'] ?? ''))) {
            return 65;
        }
        self::writeTo($stream, $codec->encode(new WorkerFrame(
            WorkerFrameKind::READY,
            $epoch,
            metadata: $identity->metadata((string) $hello->metadata['nonce']),
        )));

        /** @var array<class-string<\Bedriox\Server\Worker\WorkerTaskHandler>, \Bedriox\Server\Worker\WorkerTaskHandler> $handlers */
        $handlers = [];
        while (($frame = self::readFrame($decoder, $stream)) !== null) {
            if (!hash_equals($epoch, $frame->epoch)) {
                return 66;
            }
            if ($frame->kind === WorkerFrameKind::SHUTDOWN) {
                return 0;
            }
            if ($frame->kind !== WorkerFrameKind::SUBMIT) {
                return 67;
            }
            $definition = $registry->get($frame->taskTypeId);
            if ($definition === null || $definition->schemaVersion !== $frame->schemaVersion
                || strlen($frame->payload) > $definition->maximumInputBytes) {
                self::failure($codec, $frame, 'invalid-task', $stream);
                continue;
            }
            try {
                $handler = $handlers[$definition->handlerClass] ??= new ($definition->handlerClass)();
                $result = $handler->execute($frame->payload);
                if (strlen($result) > $definition->maximumResultBytes) {
                    self::failure($codec, $frame, 'oversized-result', $stream);
                    continue;
                }
                self::writeTo($stream, $codec->encode(new WorkerFrame(
                    WorkerFrameKind::RESULT,
                    $epoch,
                    $frame->taskId,
                    $frame->taskTypeId,
                    $frame->schemaVersion,
                    metadata: ['memory_bytes' => memory_get_usage(true)],
                    payload: $result,
                )));
            } catch (Throwable) {
                self::failure($codec, $frame, 'task-failed', $stream);
            }
        }

        return 0;
    }

    /** @param resource $stream */
    private static function failure(WorkerFrameCodec $codec, WorkerFrame $request, string $code, $stream): void
    {
        self::writeTo($stream, $codec->encode(new WorkerFrame(
            WorkerFrameKind::FAILURE,
            $request->epoch,
            $request->taskId,
            $request->taskTypeId,
            $request->schemaVersion,
            metadata: ['code' => $code, 'memory_bytes' => memory_get_usage(true)],
        )));
    }

    /** @param resource $stream */
    private static function readFrame(WorkerFrameDecoder $decoder, $stream): ?WorkerFrame
    {
        while (!feof($stream)) {
            $bytes = @fread($stream, 65_536);
            if ($bytes === false) {
                $metadata = stream_get_meta_data($stream);
                if ($metadata['timed_out']) {
                    continue;
                }

                return null;
            }
            if ($bytes === '') {
                usleep(1_000);
                continue;
            }
            $frames = $decoder->push($bytes, 1);
            if ($frames !== []) {
                return $frames[0];
            }
        }

        return null;
    }

    /** @param resource $stream */
    private static function writeTo($stream, string $bytes): bool
    {
        while ($bytes !== '') {
            $written = fwrite($stream, $bytes);
            if (!is_int($written) || $written < 1) {
                return false;
            }
            $bytes = substr($bytes, $written);
        }
        fflush($stream);

        return true;
    }
}
