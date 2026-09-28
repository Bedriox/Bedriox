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

use Bedriox\Server\Worker\Internal\IpcSocketTuning;
use Bedriox\Server\Worker\Internal\ProcessEnvironment;
use Bedriox\Server\Worker\Protocol\WorkerFrame;
use Bedriox\Server\Worker\Protocol\WorkerFrameCodec;
use Bedriox\Server\Worker\Protocol\WorkerFrameDecoder;
use Bedriox\Server\Worker\Protocol\WorkerFrameKind;
use RuntimeException;

final class ManagedWorkerPool
{
    /** @var resource|null */
    private $process = null;
    /** @var resource|null */
    private $input = null;
    /** @var resource|null */
    private $output = null;
    private readonly string $epoch;
    private readonly WorkerFrameCodec $codec;
    private readonly WorkerFrameDecoder $decoder;
    /** @var array<int, array{WorkerReceipt, int}> */
    private array $pending = [];
    /** @var list<array{WorkerResult, int}> */
    private array $ready = [];
    private string $outgoing = '';
    private int $outgoingOffset = 0;
    private string $diagnostic = '';
    private int $nextTaskId = 2;
    private int $pendingBytes = 0;
    private int $readyBytes = 0;
    private int $submitted = 0;
    private int $completed = 0;
    private int $rejected = 0;
    private int $cancelled = 0;
    private int $timedOut = 0;
    private int $failed = 0;
    private int $restarts = 0;
    private int $busyWorkers = 0;
    private int $brokerMemoryBytes = 0;
    private int $workerMemoryBytes = 0;
    private bool $available;
    private bool $shuttingDown = false;

    private function __construct(
        private readonly int $workerCount,
        private readonly string $applicationVersion,
        private readonly WorkerTaskRegistry $registry,
        private readonly WorkerLimits $limits,
        private readonly string $entryPoint,
    ) {
        $this->epoch = random_bytes(16);
        $this->codec = new WorkerFrameCodec();
        $this->decoder = new WorkerFrameDecoder($this->codec, $limits->maximumBufferedIpcBytes);
        $this->available = $workerCount > 0;
    }

    public static function start(
        string $applicationVersion,
        int $workerCount,
        ?WorkerTaskRegistry $registry = null,
        ?WorkerLimits $limits = null,
        ?string $entryPoint = null,
    ): self {
        if ($workerCount < 0 || $workerCount > 32 || $applicationVersion === '' || strlen($applicationVersion) > 128) {
            throw new \InvalidArgumentException('Invalid managed worker pool configuration.');
        }
        $registry ??= CoreWorkerTaskCatalog::create();
        $limits ??= new WorkerLimits();
        $entryPoint ??= dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'bedriox-worker.php';
        $pool = new self($workerCount, $applicationVersion, $registry, $limits, $entryPoint);
        if ($workerCount > 0) {
            $pool->launch();
        }

        return $pool;
    }

    public function submit(int $taskTypeId, string $payload, ?int $deadlineNanoseconds = null): WorkerSubmission
    {
        if ($this->shuttingDown) {
            return $this->reject(WorkerRejectionReason::SHUTTING_DOWN);
        }
        if ($this->workerCount === 0) {
            return $this->reject(WorkerRejectionReason::DISABLED);
        }
        if (!$this->available) {
            return $this->reject(WorkerRejectionReason::UNAVAILABLE_BROKER);
        }
        $definition = $this->registry->get($taskTypeId);
        if ($definition === null) {
            return $this->reject(WorkerRejectionReason::UNKNOWN_TASK_TYPE);
        }
        if (strlen($payload) > $definition->maximumInputBytes || strlen($payload) > $this->limits->maximumTaskBytes) {
            return $this->reject(WorkerRejectionReason::INVALID_INPUT);
        }
        if (count($this->pending) + count($this->ready) >= $this->limits->maximumQueuedTasks) {
            return $this->reject(WorkerRejectionReason::TASK_LIMIT);
        }
        $deadlineNanoseconds ??= hrtime(true) + $definition->timeoutMilliseconds * 1_000_000;
        if ($deadlineNanoseconds <= hrtime(true)) {
            return $this->reject(WorkerRejectionReason::INVALID_INPUT);
        }
        $taskId = $this->allocateTaskId();
        $frame = new WorkerFrame(
            WorkerFrameKind::SUBMIT,
            $this->epoch,
            $taskId,
            $definition->id,
            $definition->schemaVersion,
            deadlineNanoseconds: $deadlineNanoseconds,
            metadata: ['lane' => $definition->lane->value, 'owner' => $definition->owner],
            payload: $payload,
        );
        $bytes = $this->codec->encode($frame);
        if ($this->pendingBytes + strlen($bytes) > $this->limits->maximumQueuedBytes
            || $this->outgoingBytes() + strlen($bytes) > $this->limits->maximumBufferedIpcBytes) {
            return $this->reject(WorkerRejectionReason::BYTE_LIMIT);
        }
        $receipt = new WorkerReceipt($this->epoch, $taskId, $taskTypeId, $definition->owner, $deadlineNanoseconds);
        $this->pending[$taskId] = [$receipt, strlen($bytes)];
        $this->pendingBytes += strlen($bytes);
        $this->outgoing .= $bytes;
        ++$this->submitted;

        return WorkerSubmission::accepted($receipt);
    }

    public function cancel(WorkerReceipt $receipt): bool
    {
        if (!hash_equals($this->epoch, $receipt->epoch) || !isset($this->pending[$receipt->taskId])) {
            return false;
        }
        $definition = $this->registry->get($receipt->taskTypeId);
        if ($definition === null || !$definition->cancellable || !$this->available) {
            return false;
        }
        $this->outgoing .= $this->codec->encode(new WorkerFrame(
            WorkerFrameKind::CANCEL,
            $this->epoch,
            $receipt->taskId,
            $receipt->taskTypeId,
            $definition->schemaVersion,
        ));

        return true;
    }

    public function poll(): void
    {
        if (!$this->available || !is_resource($this->process)) {
            return;
        }
        $this->flushOutgoing();
        try {
            $this->collectFrames('');
            $readBudget = $this->decoder->remainingCapacity();
            if ($this->remainingReadyResults() > 0 && $this->remainingReadyBytes() > 0 && $readBudget > 0) {
                $bytes = is_resource($this->output) ? @fread($this->output, min(1_048_576, $readBudget)) : false;
                if (is_string($bytes) && $bytes !== '') {
                    $this->collectFrames($bytes);
                }
            }
        } catch (\Throwable) {
            $this->loseBroker('protocol');

            return;
        }
        if (!is_resource($this->process)
            || !is_resource($this->output)
            || feof($this->output)) {
            $this->loseBroker('exited');
        }
    }

    /** @return list<WorkerResult> */
    public function takeResults(int $maximum = 256): array
    {
        if ($maximum < 1 || $maximum > $this->limits->maximumFramesPerPoll) {
            throw new \InvalidArgumentException('Invalid worker result collection limit.');
        }
        $entries = array_splice($this->ready, 0, $maximum);
        $results = [];
        foreach ($entries as [$result, $bytes]) {
            $this->readyBytes -= $bytes;
            $results[] = $result;
        }

        return $results;
    }

    public function snapshot(): WorkerPoolSnapshot
    {
        return new WorkerPoolSnapshot(
            bin2hex($this->epoch),
            $this->workerCount,
            $this->busyWorkers,
            count($this->pending),
            $this->pendingBytes,
            count($this->ready),
            $this->submitted,
            $this->completed,
            $this->rejected,
            $this->cancelled,
            $this->timedOut,
            $this->failed,
            $this->restarts,
            $this->available,
            $this->readyBytes,
            $this->brokerMemoryBytes,
            $this->workerMemoryBytes,
        );
    }

    public function diagnostic(): string
    {
        return $this->diagnostic;
    }

    public function shutdown(): void
    {
        if ($this->shuttingDown) {
            return;
        }
        $this->shuttingDown = true;
        if ($this->available) {
            $this->outgoing .= $this->codec->encode(new WorkerFrame(WorkerFrameKind::SHUTDOWN, $this->epoch));
            $deadline = hrtime(true) + $this->limits->shutdownGraceMilliseconds * 1_000_000;
            do {
                $this->poll();
                if (!$this->available || $this->pending === []) {
                    break;
                }
                usleep(1_000);
            } while (hrtime(true) < $deadline);
        }
        $this->closeProcess();
    }

    public function __destruct()
    {
        $this->shutdown();
    }

    private function launch(): void
    {
        if (!is_file($this->entryPoint)) {
            throw new RuntimeException('Worker entry point is unavailable.');
        }
        $listener = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        $address = is_resource($listener) ? @stream_socket_get_name($listener, false) : false;
        if (!is_resource($listener) || !is_string($address) || $address === '') {
            throw new RuntimeException('Managed worker broker channel could not be created.');
        }
        $token = random_bytes(32);
        $pipes = [];
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = @proc_open(
            ProcessEnvironment::phpCommand(
                $this->entryPoint,
                'broker',
                (string) $this->workerCount,
                bin2hex($this->epoch),
                $this->applicationVersion,
                'tcp://' . $address,
                bin2hex($token),
            ),
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'a'], 2 => ['file', $null, 'a']],
            $pipes,
            dirname($this->entryPoint, 2),
            ProcessEnvironment::allowlisted(),
            ['bypass_shell' => true, 'blocking_pipes' => false],
        );
        if (!is_resource($process)) {
            fclose($listener);
            throw new RuntimeException('Managed worker broker could not be started.');
        }
        $connection = @stream_socket_accept($listener, 5);
        fclose($listener);
        if (!is_resource($connection)) {
            @proc_terminate($process);
            @proc_close($process);
            throw new RuntimeException('Managed worker broker did not connect.');
        }
        IpcSocketTuning::apply($connection);
        stream_set_timeout($connection, 5);
        $receivedToken = $this->readExactly($connection, strlen($token));
        if (!is_string($receivedToken) || !hash_equals($token, $receivedToken)) {
            fclose($connection);
            @proc_terminate($process);
            @proc_close($process);
            throw new RuntimeException('Managed worker broker authentication failed.');
        }
        $this->process = $process;
        $this->input = $connection;
        $this->output = $connection;
        stream_set_blocking($this->output, true);
        $nonce = bin2hex(random_bytes(16));
        $identity = WorkerIdentity::current($this->applicationVersion, $this->registry);
        $this->writeBlocking($this->codec->encode(new WorkerFrame(
            WorkerFrameKind::HELLO,
            $this->epoch,
            metadata: $identity->metadata($nonce),
        )));
        $ready = $this->readBlockingFrame();
        $expected = $identity->metadata($nonce);
        if ($ready === null || $ready->kind !== WorkerFrameKind::READY || !hash_equals($this->epoch, $ready->epoch)
            || array_diff_assoc($expected, $ready->metadata) !== []
            || (int) ($ready->metadata['workers'] ?? -1) !== $this->workerCount) {
            $diagnostic = $this->diagnostic;
            $this->closeProcess();
            throw new RuntimeException('Managed worker broker handshake failed: ' . $diagnostic);
        }
        $probe = random_bytes(32);
        $definition = $this->registry->get(CoreWorkerTaskCatalog::SELF_TEST);
        if ($definition === null) {
            $this->closeProcess();
            throw new RuntimeException('Managed worker self-test task is not registered.');
        }
        $this->writeBlocking($this->codec->encode(new WorkerFrame(
            WorkerFrameKind::SUBMIT,
            $this->epoch,
            1,
            $definition->id,
            $definition->schemaVersion,
            deadlineNanoseconds: hrtime(true) + 5_000_000_000,
            metadata: ['lane' => $definition->lane->value, 'owner' => $definition->owner],
            payload: $probe,
        )));
        $result = $this->readBlockingFrame();
        if ($result === null || $result->kind !== WorkerFrameKind::RESULT || $result->taskId !== 1
            || !hash_equals(hash('sha256', $probe, true), $result->payload)) {
            $this->closeProcess();
            throw new RuntimeException('Managed worker broker self-test failed.');
        }
        stream_set_blocking($connection, false);
    }

    private function acceptFrame(WorkerFrame $frame): void
    {
        if (!hash_equals($this->epoch, $frame->epoch)) {
            throw new RuntimeException('Worker result epoch does not match the active pool.');
        }
        if ($frame->kind === WorkerFrameKind::HEARTBEAT) {
            $this->busyWorkers = max(0, min($this->workerCount, (int) ($frame->metadata['busy'] ?? 0)));
            $this->restarts = max($this->restarts, (int) ($frame->metadata['restarts'] ?? 0));
            $this->brokerMemoryBytes = max(0, (int) ($frame->metadata['broker_memory_bytes'] ?? 0));
            $this->workerMemoryBytes = max(0, (int) ($frame->metadata['worker_memory_bytes'] ?? 0));

            return;
        }
        if (!isset($this->pending[$frame->taskId])) {
            return;
        }
        [$receipt] = $this->pending[$frame->taskId];
        $definition = $this->registry->get($receipt->taskTypeId);
        $invalidResult = $definition === null
            || $definition->schemaVersion !== $frame->schemaVersion
            || strlen($frame->payload) > min($definition->maximumResultBytes, $this->limits->maximumResultBytes);
        $expired = hrtime(true) >= $receipt->deadlineNanoseconds;
        $failureCode = is_string($frame->metadata['code'] ?? null) ? $frame->metadata['code'] : null;
        $status = match (true) {
            $expired => WorkerResultStatus::TIMED_OUT,
            $receipt->taskTypeId !== $frame->taskTypeId,
            $invalidResult => WorkerResultStatus::FAILED,
            $frame->kind === WorkerFrameKind::RESULT => WorkerResultStatus::SUCCESS,
            $frame->kind === WorkerFrameKind::CANCELLED => WorkerResultStatus::CANCELLED,
            $frame->kind === WorkerFrameKind::FAILURE => ($frame->metadata['code'] ?? '') === 'timeout'
                ? WorkerResultStatus::TIMED_OUT : WorkerResultStatus::FAILED,
            default => WorkerResultStatus::FAILED,
        };
        if ($expired) {
            $failureCode = 'deadline';
        } elseif ($status === WorkerResultStatus::FAILED && $failureCode === null) {
            $failureCode = 'invalid-result';
        }
        $this->removePending($receipt->taskId);
        if ($status === WorkerResultStatus::CANCELLED) {
            ++$this->cancelled;
        } elseif ($status === WorkerResultStatus::TIMED_OUT) {
            ++$this->timedOut;
        } elseif ($status === WorkerResultStatus::FAILED) {
            ++$this->failed;
        } else {
            ++$this->completed;
        }
        $resultPayload = $status === WorkerResultStatus::SUCCESS ? $frame->payload : '';
        $this->queueReady(new WorkerResult(
            $receipt,
            $status,
            $resultPayload,
            $failureCode,
            hrtime(true),
        ), strlen($resultPayload));
    }

    private function collectFrames(string $bytes): void
    {
        $this->decoder->append($bytes);
        $maximum = min($this->limits->maximumFramesPerPoll, $this->remainingReadyResults());
        if ($maximum < 1) {
            return;
        }
        foreach ($this->decoder->drain($maximum, $this->remainingReadyBytes()) as $frame) {
            $this->acceptFrame($frame);
        }
    }

    private function remainingReadyResults(): int
    {
        return $this->limits->maximumReadyResults - count($this->ready);
    }

    private function remainingReadyBytes(): int
    {
        return $this->limits->maximumReadyBytes - $this->readyBytes;
    }

    private function removePending(int $taskId): void
    {
        $entry = $this->pending[$taskId] ?? null;
        if ($entry === null) {
            return;
        }
        $this->pendingBytes -= $entry[1];
        unset($this->pending[$taskId]);
    }

    private function queueReady(WorkerResult $result, int $bytes): void
    {
        if ($this->remainingReadyResults() < 1 || $bytes > $this->remainingReadyBytes()) {
            throw new RuntimeException('Worker ready-result queue capacity was exceeded.');
        }
        $this->ready[] = [$result, $bytes];
        $this->readyBytes += $bytes;
    }

    private function flushOutgoing(): void
    {
        if ($this->outgoingBytes() === 0 || !is_resource($this->input)) {
            return;
        }
        $remainingBudget = 1_048_576;
        while ($this->outgoingBytes() > 0 && $remainingBudget > 0) {
            $attemptBytes = min($remainingBudget, $this->outgoingBytes(), 262_144);
            $written = @fwrite(
                $this->input,
                substr($this->outgoing, $this->outgoingOffset, $attemptBytes),
            );
            if ($written === false) {
                $this->loseBroker('write-failed');

                return;
            }
            if ($written < 1) {
                break;
            }
            $this->outgoingOffset += $written;
            $remainingBudget -= $written;
        }
        if ($this->outgoingBytes() === 0) {
            $this->outgoing = '';
            $this->outgoingOffset = 0;
        } elseif ($this->outgoingOffset >= 8_388_608
            && $this->outgoingOffset >= intdiv(strlen($this->outgoing), 2)) {
            $this->outgoing = (string) substr($this->outgoing, $this->outgoingOffset);
            $this->outgoingOffset = 0;
        }
    }

    private function outgoingBytes(): int
    {
        return strlen($this->outgoing) - $this->outgoingOffset;
    }

    private function loseBroker(string $code): void
    {
        if (!$this->available) {
            return;
        }
        $this->available = false;
        foreach ($this->pending as [$receipt]) {
            $this->queueReady(
                new WorkerResult($receipt, WorkerResultStatus::BROKER_LOST, failureCode: $code, completedAtNanoseconds: hrtime(true)),
                0,
            );
            ++$this->failed;
        }
        $this->pending = [];
        $this->pendingBytes = 0;
        $this->outgoing = '';
        $this->outgoingOffset = 0;
        $this->closeProcess();
    }

    private function reject(WorkerRejectionReason $reason): WorkerSubmission
    {
        ++$this->rejected;

        return WorkerSubmission::rejected($reason);
    }

    private function allocateTaskId(): int
    {
        for ($attempts = 0; $attempts < 0xffffffff; ++$attempts) {
            $id = $this->nextTaskId++;
            if ($this->nextTaskId > 0xffffffff) {
                $this->nextTaskId = 2;
            }
            if (!isset($this->pending[$id])) {
                return $id;
            }
        }
        throw new RuntimeException('Worker task identifier space is exhausted.');
    }

    private function writeBlocking(string $bytes): void
    {
        while ($bytes !== '' && is_resource($this->input)) {
            $written = fwrite($this->input, $bytes);
            if (!is_int($written) || $written < 1) {
                throw new RuntimeException('Managed worker broker write failed.');
            }
            $bytes = substr($bytes, $written);
        }
        if (is_resource($this->input)) {
            fflush($this->input);
        }
    }

    private function readBlockingFrame(): ?WorkerFrame
    {
        if (!is_resource($this->output)) {
            return null;
        }
        $fixed = $this->readExactly($this->output, WorkerFrameCodec::FIXED_HEADER_BYTES);
        if ($fixed === null) {
            return null;
        }
        $lengths = $this->codec->lengths($fixed);
        if ($lengths === null) {
            return null;
        }
        $body = $this->readExactly($this->output, $lengths['headerLength'] + $lengths['payloadLength']);

        return $body === null ? null : $this->codec->decode($fixed . $body);
    }

    /** @param resource $stream */
    private function readExactly($stream, int $length): ?string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $remaining = $length - strlen($bytes);
            if ($remaining < 1) {
                break;
            }
            $part = fread($stream, $remaining);
            if (!is_string($part) || $part === '') {
                return null;
            }
            $bytes .= $part;
        }

        return $bytes;
    }

    private function closeProcess(): void
    {
        if (is_resource($this->process)) {
            $status = @proc_get_status($this->process);
            if ($status['running']) {
                @proc_terminate($this->process);
            }
        }
        foreach (['input', 'output'] as $property) {
            if (is_resource($this->{$property})) {
                @fclose($this->{$property});
            }
            if ($property === 'input' && $this->output === $this->input) {
                $this->output = null;
            }
            $this->{$property} = null;
        }
        if (is_resource($this->process)) {
            @proc_close($this->process);
        }
        $this->process = null;
        $this->available = false;
    }
}
