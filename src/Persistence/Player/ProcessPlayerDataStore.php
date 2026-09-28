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

namespace Bedriox\Server\Persistence\Player;

use Bedriox\Server\Persistence\OrderedPersistenceQueue;
use Bedriox\Server\Persistence\PersistenceEnqueueResult;
use Bedriox\Server\Persistence\PersistenceQueueSnapshot;
use Bedriox\Server\Persistence\PersistenceQueueStatusProvider;
use Bedriox\Server\Persistence\PersistenceWriteCompletion;
use Bedriox\Server\Persistence\PersistenceWriteRequest;
use Bedriox\Server\Persistence\Player\Internal\PlayerStorageProcessProgram;
use Bedriox\Server\Player\Persistence\AsynchronousPlayerDataStore;
use Bedriox\Server\Player\Persistence\Exception\CorruptPlayerDataException;
use Bedriox\Server\Player\Persistence\Exception\PlayerDataException;
use Bedriox\Server\Player\Persistence\Exception\PlayerDataWriteException;
use Bedriox\Server\Player\Persistence\Exception\UnsupportedPlayerDataException;
use Bedriox\Server\Player\Persistence\PlayerDataCodec;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Worker\Internal\ProcessEnvironment;
use Bedriox\Server\Worker\Protocol\WorkerFrame;
use Bedriox\Server\Worker\Protocol\WorkerFrameCodec;
use Bedriox\Server\Worker\Protocol\WorkerFrameDecoder;
use Bedriox\Server\Worker\Protocol\WorkerFrameKind;
use Throwable;

final class ProcessPlayerDataStore implements AsynchronousPlayerDataStore, PersistenceQueueStatusProvider
{
    /** @var resource|null */
    private $process = null;
    /** @var resource|null */
    private $connection = null;
    private readonly string $epoch;
    private readonly WorkerFrameCodec $frames;
    private readonly PlayerDataCodec $profiles;
    private readonly OrderedPersistenceQueue $writeQueue;
    private readonly WorkerFrameDecoder $asyncDecoder;
    private int $nextTaskId = 1;
    private ?PersistenceWriteRequest $asyncWrite = null;
    private int $asyncTaskId = 0;
    private string $asyncOutgoing = '';
    /** @var list<PersistenceWriteCompletion> */
    private array $deferredCompletions = [];
    private bool $closed = false;
    private bool $ownerConfirmedClosed = false;

    private function __construct(
        private readonly string $applicationVersion,
        private readonly string $directory,
        private readonly int $requestTimeoutMilliseconds,
        private readonly string $entryPoint,
    ) {
        if ($applicationVersion === '' || strlen($applicationVersion) > 128 || $directory === ''
            || strlen($directory) > 4_096 || str_contains($directory, "\0")
            || $requestTimeoutMilliseconds < 100 || $requestTimeoutMilliseconds > 300_000) {
            throw new \InvalidArgumentException('Process player store configuration is invalid.');
        }
        $this->epoch = random_bytes(16);
        $this->frames = new WorkerFrameCodec();
        $this->profiles = new PlayerDataCodec();
        $this->writeQueue = new OrderedPersistenceQueue(1_024, 33_554_432, PlayerDataCodec::MAX_BYTES, 1_024);
        $this->asyncDecoder = new WorkerFrameDecoder($this->frames, 1_048_576);
    }

    public static function start(
        string $applicationVersion,
        string $directory,
        int $requestTimeoutMilliseconds = 30_000,
        ?string $entryPoint = null,
    ): self {
        $store = new self(
            $applicationVersion,
            $directory,
            $requestTimeoutMilliseconds,
            $entryPoint ?? dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'bedriox-io.php',
        );
        $store->launch();

        return $store;
    }

    public function exists(string $uuid): bool
    {
        $result = $this->request(PlayerStorageOperation::EXISTS, $uuid);
        $exists = $result->metadata['exists'] ?? null;
        if (!is_bool($exists) || $result->payload !== '') {
            throw $this->failOwner('Player storage existence result is invalid.');
        }

        return $exists;
    }

    public function persistenceQueueSnapshot(): PersistenceQueueSnapshot
    {
        return $this->writeQueue->snapshot();
    }

    public function load(string $uuid): ?PlayerBootstrap
    {
        $result = $this->request(PlayerStorageOperation::LOAD, $uuid);
        $missing = $result->metadata['missing'] ?? null;
        if (!is_bool($missing) || ($missing && $result->payload !== '')) {
            throw $this->failOwner('Player storage load result is invalid.');
        }
        if ($missing) {
            return null;
        }
        try {
            $profile = $this->profiles->decode($result->payload);
        } catch (Throwable $error) {
            throw $this->failOwner('Player storage returned an invalid profile.', $error);
        }
        if ($profile->identity->uuid !== $uuid) {
            throw $this->failOwner('Player storage returned the wrong profile identity.');
        }

        return $profile;
    }

    public function save(PlayerBootstrap $player): void
    {
        $result = $this->request(PlayerStorageOperation::SAVE, $this->profiles->encode($player));
        if (($result->metadata['uuid'] ?? null) !== $player->identity->uuid || $result->payload !== '') {
            throw $this->failOwner('Player storage save acknowledgement has the wrong identity.');
        }
    }

    public function enqueueSave(PlayerBootstrap $player, int $revision): PersistenceEnqueueResult
    {
        if ($this->closed || !is_resource($this->process) || !is_resource($this->connection)) {
            throw new PlayerDataException('Player storage owner is closed.');
        }
        if ($revision < 0) {
            throw new \InvalidArgumentException('Player persistence revision must be non-negative.');
        }

        return $this->writeQueue->enqueue(
            'player:' . $player->identity->uuid,
            $revision,
            $this->profiles->encode($player),
        );
    }

    /** @return list<PersistenceWriteCompletion> */
    public function pollSaves(int $maximumCompletions = 256): array
    {
        if ($maximumCompletions < 1 || $maximumCompletions > 256) {
            throw new \InvalidArgumentException('Player persistence completion limit is invalid.');
        }
        $this->advanceSaves();
        $completions = array_splice($this->deferredCompletions, 0, $maximumCompletions);
        while (count($completions) < $maximumCompletions) {
            $completion = $this->writeQueue->takeCompletion();
            if (!$completion instanceof PersistenceWriteCompletion) {
                break;
            }
            $completions[] = $completion;
        }

        return $completions;
    }

    /**
     * @phpstan-impure
     * @return list<PersistenceWriteCompletion>
     */
    public function drainSaves(int $timeoutMilliseconds): array
    {
        if ($timeoutMilliseconds < 1 || $timeoutMilliseconds > 300_000) {
            throw new \InvalidArgumentException('Player persistence drain timeout is invalid.');
        }
        $all = [];
        $deadline = hrtime(true) + $timeoutMilliseconds * 1_000_000;
        do {
            $all = [...$all, ...$this->pollSaves()];
            $snapshot = $this->writeQueue->snapshot();
            if ($snapshot->queued === 0 && $snapshot->inFlight === 0
                && $this->asyncWrite === null && $this->asyncOutgoing === '') {
                return $all;
            }
            usleep(1_000);
        } while (hrtime(true) < $deadline);

        throw $this->failOwner('Timed out draining queued player persistence.');
    }

    public static function uuidFor(PersistenceWriteCompletion $completion): string
    {
        $uuid = substr($completion->key, strlen('player:'));
        if ($completion->key !== 'player:' . $uuid
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $uuid) !== 1) {
            throw new \InvalidArgumentException('Persistence completion does not contain a player UUID key.');
        }

        return $uuid;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $failure = null;
        try {
            $this->drainSaves($this->requestTimeoutMilliseconds);
        } catch (Throwable $error) {
            $failure = $error;
        }
        $this->closed = true;
        try {
            if (is_resource($this->connection)) {
                stream_set_blocking($this->connection, true);
                $this->writeFrame(new WorkerFrame(WorkerFrameKind::SHUTDOWN, $this->epoch));
                $result = $this->readFrame();
                if ($result === null || $result->kind !== WorkerFrameKind::RESULT
                    || !hash_equals($this->epoch, $result->epoch) || $result->taskId !== 0) {
                    throw new PlayerDataWriteException('Player storage owner did not confirm closure.');
                }
                $this->ownerConfirmedClosed = true;
            }
        } catch (Throwable $error) {
            $failure ??= $error;
        }
        $this->closeProcess();
        if ($failure !== null) {
            throw $failure;
        }
    }

    public function ownerConfirmedClosed(): bool
    {
        return $this->ownerConfirmedClosed;
    }

    public function __destruct()
    {
        if (!$this->closed) {
            try {
                $this->close();
            } catch (Throwable) {
            }
        }
    }

    private function launch(): void
    {
        if (!is_file($this->entryPoint)) {
            throw new PlayerDataWriteException('Player storage process entry point is unavailable.');
        }
        $listener = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        if (!is_resource($listener)) {
            throw new PlayerDataWriteException('Player storage IPC listener could not be created.');
        }
        $endpoint = stream_socket_get_name($listener, false);
        if (!is_string($endpoint) || $endpoint === '') {
            @fclose($listener);
            throw new PlayerDataWriteException('Player storage IPC endpoint is unavailable.');
        }
        $token = random_bytes(32);
        $pipes = [];
        $process = @proc_open(
            ProcessEnvironment::phpCommand(
                $this->entryPoint,
                'player',
                bin2hex($this->epoch),
                $this->applicationVersion,
                'tcp://' . $endpoint,
                bin2hex($token),
            ),
            [
                0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
                1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'a'],
                2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'a'],
            ],
            $pipes,
            dirname($this->entryPoint, 2),
            ProcessEnvironment::allowlisted(),
            ['bypass_shell' => true, 'blocking_pipes' => false],
        );
        if (!is_resource($process)) {
            @fclose($listener);
            throw new PlayerDataWriteException('Player storage process could not be started.');
        }
        $connection = @stream_socket_accept($listener, 5);
        @fclose($listener);
        if (!is_resource($connection)) {
            @proc_terminate($process);
            throw new PlayerDataWriteException('Player storage process did not connect to its IPC endpoint.');
        }
        stream_set_blocking($connection, true);
        stream_set_timeout($connection, 5);
        $receivedToken = $this->readExact($connection, strlen($token));
        if (!is_string($receivedToken) || !hash_equals($token, $receivedToken)) {
            @fclose($connection);
            @proc_terminate($process);
            throw new PlayerDataWriteException('Player storage IPC authentication failed.');
        }
        $this->process = $process;
        $this->connection = $connection;
        $nonce = bin2hex(random_bytes(16));
        $this->writeFrame(new WorkerFrame(
            WorkerFrameKind::HELLO,
            $this->epoch,
            metadata: PlayerStorageProcessProgram::identity($this->applicationVersion, $nonce),
            payload: $this->directory,
        ));
        $ready = $this->readFrame();
        if ($ready === null || !hash_equals($this->epoch, $ready->epoch)) {
            $this->closeProcess();
            throw new PlayerDataWriteException('Player storage process handshake failed.');
        }
        if ($ready->kind === WorkerFrameKind::FAILURE) {
            $this->closeProcess();
            throw self::mappedFailure(is_string($ready->metadata['code'] ?? null) ? $ready->metadata['code'] : 'player_write_failure');
        }
        if ($ready->kind !== WorkerFrameKind::READY
            || $ready->metadata !== PlayerStorageProcessProgram::identity($this->applicationVersion, $nonce)) {
            $this->closeProcess();
            throw new PlayerDataWriteException('Player storage process identity did not match.');
        }
        stream_set_timeout(
            $connection,
            intdiv($this->requestTimeoutMilliseconds, 1_000),
            ($this->requestTimeoutMilliseconds % 1_000) * 1_000,
        );
        stream_set_blocking($connection, false);
    }

    private function request(PlayerStorageOperation $operation, string $payload): WorkerFrame
    {
        if ($this->closed || !is_resource($this->process) || !is_resource($this->connection)) {
            throw new PlayerDataException('Player storage owner is closed.');
        }
        $pending = $this->writeQueue->snapshot();
        if ($pending->queued > 0 || $pending->inFlight > 0 || $this->asyncWrite !== null || $this->asyncOutgoing !== '') {
            $drained = $this->drainSaves($this->requestTimeoutMilliseconds);
            $this->deferredCompletions = [...$this->deferredCompletions, ...$drained];
        }
        if (!is_resource($this->connection)) {
            throw new PlayerDataException('Player storage owner is closed.');
        }
        stream_set_blocking($this->connection, true);
        $taskId = $this->nextTaskId++;
        if ($this->nextTaskId > 0xffffffff) {
            $this->nextTaskId = 1;
        }
        try {
            $this->writeFrame(new WorkerFrame(
                WorkerFrameKind::SUBMIT,
                $this->epoch,
                $taskId,
                $operation->value,
                PlayerStorageProcessProgram::SCHEMA_VERSION,
                payload: $payload,
            ));
            $result = $this->readFrame();
        } finally {
            if (is_resource($this->connection)) {
                stream_set_blocking($this->connection, false);
            }
        }
        if ($result === null || !hash_equals($this->epoch, $result->epoch)
            || $result->taskId !== $taskId || $result->taskTypeId !== $operation->value
            || $result->schemaVersion !== PlayerStorageProcessProgram::SCHEMA_VERSION) {
            throw $this->failOwner('Player storage response was missing or did not match its request.');
        }
        if ($result->kind === WorkerFrameKind::FAILURE) {
            throw self::mappedFailure(is_string($result->metadata['code'] ?? null) ? $result->metadata['code'] : 'player_write_failure');
        }
        if ($result->kind !== WorkerFrameKind::RESULT) {
            throw $this->failOwner('Player storage response kind is invalid.');
        }

        return $result;
    }

    private function advanceSaves(): void
    {
        if ($this->closed || !is_resource($this->connection)) {
            throw new PlayerDataException('Player storage owner is closed.');
        }
        if ($this->asyncWrite === null) {
            $request = $this->writeQueue->dispatch();
            if ($request instanceof PersistenceWriteRequest) {
                $this->asyncWrite = $request;
                $this->asyncTaskId = $this->nextTaskId++;
                if ($this->nextTaskId > 0xffffffff) {
                    $this->nextTaskId = 1;
                }
                $this->asyncOutgoing = $this->frames->encode(new WorkerFrame(
                    WorkerFrameKind::SUBMIT,
                    $this->epoch,
                    $this->asyncTaskId,
                    PlayerStorageOperation::SAVE->value,
                    PlayerStorageProcessProgram::SCHEMA_VERSION,
                    payload: $request->payload,
                ));
            }
        }
        if ($this->asyncOutgoing !== '') {
            $written = @fwrite($this->connection, $this->asyncOutgoing);
            if ($written === false) {
                throw $this->failOwner('Player storage asynchronous IPC write failed.');
            }
            if ($written > 0) {
                $this->asyncOutgoing = substr($this->asyncOutgoing, $written);
            }
        }
        $bytes = @fread($this->connection, 262_144);
        if (!is_string($bytes) || $bytes === '') {
            return;
        }
        try {
            $frames = $this->asyncDecoder->push($bytes, 16);
        } catch (Throwable $error) {
            throw $this->failOwner('Player storage asynchronous response framing failed.', $error);
        }
        foreach ($frames as $frame) {
            $request = $this->asyncWrite;
            if (!$request instanceof PersistenceWriteRequest || !hash_equals($this->epoch, $frame->epoch)
                || $frame->taskId !== $this->asyncTaskId || $frame->taskTypeId !== PlayerStorageOperation::SAVE->value
                || $frame->schemaVersion !== PlayerStorageProcessProgram::SCHEMA_VERSION) {
                throw $this->failOwner('Player storage asynchronous response did not match its request.');
            }
            $uuid = substr($request->key, strlen('player:'));
            $successful = $frame->kind === WorkerFrameKind::RESULT
                && ($frame->metadata['uuid'] ?? null) === $uuid && $frame->payload === '';
            $failureCode = $successful
                ? null
                : (is_string($frame->metadata['code'] ?? null) ? $frame->metadata['code'] : 'player_write_failure');
            $this->writeQueue->complete(
                $request->id,
                $request->key,
                $request->revision,
                $successful,
                $failureCode,
            );
            $this->asyncWrite = null;
            $this->asyncTaskId = 0;
            if (count($frames) > 1) {
                throw $this->failOwner('Player storage returned more than one asynchronous completion.');
            }
        }
    }

    private function writeFrame(WorkerFrame $frame): void
    {
        if (!is_resource($this->connection)) {
            throw new PlayerDataException('Player storage owner is closed.');
        }
        $bytes = $this->frames->encode($frame);
        while ($bytes !== '') {
            $written = @fwrite($this->connection, $bytes);
            if (!is_int($written) || $written < 1) {
                throw $this->failOwner('Player storage IPC write failed.');
            }
            $bytes = substr($bytes, $written);
        }
        fflush($this->connection);
    }

    private function readFrame(): ?WorkerFrame
    {
        if (!is_resource($this->connection)) {
            return null;
        }
        $header = $this->readExact($this->connection, WorkerFrameCodec::FIXED_HEADER_BYTES);
        if ($header === null) {
            return null;
        }
        $lengths = $this->frames->lengths($header);
        if ($lengths === null) {
            return null;
        }
        $body = $this->readExact($this->connection, $lengths['headerLength'] + $lengths['payloadLength']);

        return $body === null ? null : $this->frames->decode($header . $body);
    }

    /** @param resource $stream */
    private function readExact($stream, int $length): ?string
    {
        if ($length < 0) {
            return null;
        }
        if ($length === 0) {
            return '';
        }
        $bytes = '';
        while (strlen($bytes) < $length) {
            $chunk = @fread($stream, max(1, $length - strlen($bytes)));
            if (!is_string($chunk) || $chunk === '') {
                return null;
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }

    private function failOwner(string $message, ?Throwable $previous = null): PlayerDataException
    {
        $this->closed = true;
        $this->closeProcess();

        return new PlayerDataException($message, previous: $previous);
    }

    private static function mappedFailure(string $code): PlayerDataException
    {
        return match ($code) {
            'corrupt_player_data' => new CorruptPlayerDataException('Player storage owner reported corrupt data.'),
            'unsupported_player_data' => new UnsupportedPlayerDataException('Player storage owner reported an unsupported schema.'),
            default => new PlayerDataWriteException('Player storage owner reported an I/O failure.'),
        };
    }

    private function closeProcess(): void
    {
        if (is_resource($this->connection)) {
            @fclose($this->connection);
        }
        $this->connection = null;
        if (is_resource($this->process)) {
            $status = @proc_get_status($this->process);
            if ($status['running']) {
                @proc_terminate($this->process);
            }
            $deadline = hrtime(true) + 250_000_000;
            do {
                $status = @proc_get_status($this->process);
                if (!$status['running']) {
                    @proc_close($this->process);
                    break;
                }
                usleep(1_000);
            } while (hrtime(true) < $deadline);
        }
        $this->process = null;
    }
}
