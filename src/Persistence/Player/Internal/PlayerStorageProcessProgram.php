<?php

declare(strict_types=1);

namespace Bedriox\Server\Persistence\Player\Internal;

use Bedriox\Server\Persistence\Player\PlayerStorageOperation;
use Bedriox\Server\Player\Persistence\Exception\CorruptPlayerDataException;
use Bedriox\Server\Player\Persistence\Exception\UnsupportedPlayerDataException;
use Bedriox\Server\Player\Persistence\FilePlayerDataStore;
use Bedriox\Server\Player\Persistence\PlayerDataCodec;
use Bedriox\Server\Worker\Protocol\WorkerFrame;
use Bedriox\Server\Worker\Protocol\WorkerFrameCodec;
use Bedriox\Server\Worker\Protocol\WorkerFrameKind;
use Throwable;

final class PlayerStorageProcessProgram
{
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
        $frames = new WorkerFrameCodec();
        $hello = self::readFrame($connection, $frames);
        $nonce = $hello?->metadata['nonce'] ?? null;
        if ($hello === null || $hello->kind !== WorkerFrameKind::HELLO || !hash_equals($epoch, $hello->epoch)
            || !is_string($nonce) || strlen($nonce) !== 32
            || $hello->metadata !== self::identity($applicationVersion, $nonce)
            || $hello->payload === '' || strlen($hello->payload) > 4_096 || str_contains($hello->payload, "\0")) {
            return 65;
        }
        try {
            $store = new FilePlayerDataStore($hello->payload);
            $profiles = new PlayerDataCodec();
            self::write($connection, $frames->encode(new WorkerFrame(
                WorkerFrameKind::READY,
                $epoch,
                metadata: self::identity($applicationVersion, $nonce),
            )));
            while (($frame = self::readFrame($connection, $frames)) !== null) {
                if (!hash_equals($epoch, $frame->epoch)) {
                    return 67;
                }
                if ($frame->kind === WorkerFrameKind::SHUTDOWN) {
                    self::write($connection, $frames->encode(new WorkerFrame(WorkerFrameKind::RESULT, $epoch)));

                    return 0;
                }
                $operation = PlayerStorageOperation::tryFrom($frame->taskTypeId);
                if ($frame->kind !== WorkerFrameKind::SUBMIT || $operation === null
                    || $frame->schemaVersion !== self::SCHEMA_VERSION) {
                    return 68;
                }
                try {
                    [$metadata, $payload] = match ($operation) {
                        PlayerStorageOperation::EXISTS => [['exists' => $store->exists($frame->payload)], ''],
                        PlayerStorageOperation::LOAD => self::load($store, $profiles, $frame->payload),
                        PlayerStorageOperation::SAVE => self::save($store, $profiles, $frame->payload),
                    };
                    self::write($connection, $frames->encode(new WorkerFrame(
                        WorkerFrameKind::RESULT,
                        $epoch,
                        $frame->taskId,
                        $operation->value,
                        self::SCHEMA_VERSION,
                        metadata: $metadata,
                        payload: $payload,
                    )));
                } catch (Throwable $error) {
                    self::write($connection, $frames->encode(new WorkerFrame(
                        WorkerFrameKind::FAILURE,
                        $epoch,
                        $frame->taskId,
                        $operation->value,
                        self::SCHEMA_VERSION,
                        metadata: ['code' => self::failureCode($error)],
                    )));
                }
            }
        } catch (Throwable $error) {
            self::write($connection, $frames->encode(new WorkerFrame(
                WorkerFrameKind::FAILURE,
                $epoch,
                metadata: ['code' => self::failureCode($error)],
            )));

            return 74;
        }

        return 0;
    }

    /** @return array<string, string> */
    public static function identity(string $applicationVersion, string $nonce): array
    {
        return ['application_version' => $applicationVersion, 'nonce' => $nonce, 'service' => 'player-storage'];
    }

    /** @return array{array<string, bool|int|string|null>, string} */
    private static function load(FilePlayerDataStore $store, PlayerDataCodec $codec, string $uuid): array
    {
        $profile = $store->load($uuid);

        return $profile === null
            ? [['missing' => true], '']
            : [['missing' => false], $codec->encode($profile)];
    }

    /** @return array{array<string, bool|int|string|null>, string} */
    private static function save(FilePlayerDataStore $store, PlayerDataCodec $codec, string $payload): array
    {
        $profile = $codec->decode($payload);
        $store->save($profile);

        return [['uuid' => $profile->identity->uuid], ''];
    }

    private static function failureCode(Throwable $error): string
    {
        return match (true) {
            $error instanceof UnsupportedPlayerDataException => 'unsupported_player_data',
            $error instanceof CorruptPlayerDataException => 'corrupt_player_data',
            default => 'player_write_failure',
        };
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
                throw new \RuntimeException('Player storage IPC write failed.');
            }
            $bytes = substr($bytes, $written);
        }
        fflush($stream);
    }
}
