<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Internal;

use Bedriox\Server\Worker\CoreWorkerTaskCatalog;
use Bedriox\Server\Worker\Protocol\WorkerFrame;
use Bedriox\Server\Worker\Protocol\WorkerFrameCodec;
use Bedriox\Server\Worker\Protocol\WorkerFrameDecoder;
use Bedriox\Server\Worker\Protocol\WorkerFrameKind;
use Bedriox\Server\Worker\WorkerIdentity;
use Bedriox\Server\Worker\WorkerLane;
use Bedriox\Server\Worker\WorkerLaneCapacity;

final class BrokerProgram
{
    private const MAXIMUM_RESTARTS_PER_MINUTE = 5;
    private const LANE_CYCLE = [0, 1, 1, 1, 1, 2, 2, 2, 3, 3];

    private WorkerFrameCodec $codec;
    private WorkerFrameDecoder $parentDecoder;
    /** @var array<int, BrokerWorker> */
    private array $workers = [];
    /** @var array<int, list<array{WorkerFrame, int}>> */
    private array $queues = [];
    /** @var array<int, int> */
    private array $cancelledRunning = [];
    /** @var list<int> */
    private array $restartTimes = [];
    private string $parentOutput = '';
    private int $laneCursor = 0;
    private int $restarts = 0;
    private bool $stopping = false;
    private readonly WorkerLaneCapacity $laneCapacity;
    /** @var resource|null */
    private $parentStream = null;

    private function __construct(
        private readonly string $epoch,
        private readonly string $applicationVersion,
        private readonly string $entryPoint,
        private readonly int $configuredWorkers,
        private readonly ?string $parentEndpoint,
        private readonly ?string $parentTokenHex,
    ) {
        $this->codec = new WorkerFrameCodec();
        $this->parentDecoder = new WorkerFrameDecoder($this->codec);
        foreach (WorkerLane::cases() as $lane) {
            $this->queues[$lane->value] = [];
        }
        $this->laneCapacity = new WorkerLaneCapacity($configuredWorkers);
    }

    public static function run(
        int $workerCount,
        string $epochHex,
        string $applicationVersion,
        string $entryPoint,
        ?string $parentEndpoint = null,
        ?string $parentTokenHex = null,
    ): int {
        $epoch = hex2bin($epochHex);
        if ($workerCount < 1 || $workerCount > 32 || !is_string($epoch) || strlen($epoch) !== 16) {
            return 64;
        }

        return (new self($epoch, $applicationVersion, $entryPoint, $workerCount, $parentEndpoint, $parentTokenHex))->execute();
    }

    private function execute(): int
    {
        $this->parentStream = $this->openParentStream();
        if (!is_resource($this->parentStream)) {
            return 63;
        }
        IpcSocketTuning::apply($this->parentStream);
        stream_set_blocking($this->parentStream, true);
        $hello = $this->awaitParentHello();
        if ($hello === null) {
            return 65;
        }
        stream_set_blocking($this->parentStream, false);
        for ($id = 0; $id < $this->configuredWorkers; ++$id) {
            $worker = $this->startWorker();
            if ($worker === null) {
                $this->closeAll();

                return 66;
            }
            $this->workers[$id] = $worker;
        }
        $this->queueParent(new WorkerFrame(
            WorkerFrameKind::READY,
            $this->epoch,
            metadata: WorkerIdentity::current($this->applicationVersion, CoreWorkerTaskCatalog::create())
                ->metadata((string) $hello->metadata['nonce']) + ['workers' => $this->configuredWorkers],
        ));

        $lastHeartbeat = hrtime(true);
        while (!$this->stopping || $this->hasOutstanding()) {
            $this->pollParent();
            $this->pollWorkers();
            $this->expireAndRecoverWorkers();
            $this->dispatch();
            $this->flushWorkerInputs();
            $now = hrtime(true);
            if ($now - $lastHeartbeat >= 1_000_000_000) {
                $this->queueParent(new WorkerFrame(
                    WorkerFrameKind::HEARTBEAT,
                    $this->epoch,
                    metadata: [
                        'busy' => $this->busyCount(),
                        'queued' => $this->queuedCount(),
                        'restarts' => $this->restarts,
                        'workers' => count($this->workers),
                        'broker_memory_bytes' => memory_get_usage(true),
                        'worker_memory_bytes' => array_sum(array_map(
                            static fn(BrokerWorker $worker): int => $worker->memoryBytes,
                            $this->workers,
                        )),
                    ],
                ));
                $lastHeartbeat = $now;
            }
            $this->flushParent();
            if (feof($this->parent())) {
                $this->stopping = true;
                $this->cancelAllQueued('parent-lost');
                break;
            }
            usleep(1_000);
        }
        $this->closeAll();

        return 0;
    }

    private function awaitParentHello(): ?WorkerFrame
    {
        $deadline = hrtime(true) + 5_000_000_000;
        do {
            $bytes = fread($this->parent(), 65_536);
            if (is_string($bytes) && $bytes !== '') {
                foreach ($this->parentDecoder->push($bytes) as $frame) {
                    $identity = WorkerIdentity::current($this->applicationVersion, CoreWorkerTaskCatalog::create());
                    $nonce = (string) ($frame->metadata['nonce'] ?? '');
                    if ($frame->kind === WorkerFrameKind::HELLO && hash_equals($this->epoch, $frame->epoch)
                        && $frame->metadata === $identity->metadata($nonce) && strlen($nonce) === 32) {
                        return $frame;
                    }

                    return null;
                }
            }
            usleep(1_000);
        } while (hrtime(true) < $deadline && !feof($this->parent()));

        return null;
    }

    private function startWorker(): ?BrokerWorker
    {
        $listener = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        $address = is_resource($listener) ? @stream_socket_get_name($listener, false) : false;
        if (!is_resource($listener) || !is_string($address) || $address === '') {
            return null;
        }
        $token = random_bytes(32);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $pipes = [];
        $process = @proc_open(
            ProcessEnvironment::phpCommand(
                $this->entryPoint,
                'compute',
                bin2hex($this->epoch),
                $this->applicationVersion,
                'tcp://' . $address,
                bin2hex($token),
            ),
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'a'], 2 => ['file', $null, 'a']],
            $pipes,
            dirname($this->entryPoint, 2),
            ProcessEnvironment::allowlisted(),
            ['bypass_shell' => true, 'create_process_group' => true],
        );
        if (!is_resource($process)) {
            fclose($listener);
            return null;
        }
        $connection = @stream_socket_accept($listener, 5);
        fclose($listener);
        if (!is_resource($connection)) {
            @proc_terminate($process);
            @proc_close($process);

            return null;
        }
        IpcSocketTuning::apply($connection);
        stream_set_timeout($connection, 5);
        $receivedToken = $this->readExactly($connection, strlen($token));
        if (!is_string($receivedToken) || !hash_equals($token, $receivedToken)) {
            fclose($connection);
            @proc_terminate($process);
            @proc_close($process);

            return null;
        }
        $pipes[0] = $connection;
        $pipes[1] = $connection;
        $nonce = bin2hex(random_bytes(16));
        $identity = WorkerIdentity::current($this->applicationVersion, CoreWorkerTaskCatalog::create());
        $hello = $this->codec->encode(new WorkerFrame(
            WorkerFrameKind::HELLO,
            $this->epoch,
            metadata: $identity->metadata($nonce),
        ));
        if (!$this->writeBlocking($pipes[0], $hello)) {
            $this->closeProcess($process, $pipes[0], $pipes[1]);

            return null;
        }
        stream_set_blocking($pipes[1], true);
        $decoder = new WorkerFrameDecoder($this->codec, 33_619_968);
        $frame = $this->readWorkerFrameBlocking($pipes[1]);
        if ($frame !== null && $frame->kind === WorkerFrameKind::READY && hash_equals($this->epoch, $frame->epoch)
            && $frame->metadata === $identity->metadata($nonce)) {
            stream_set_blocking($pipes[0], false);
            stream_set_blocking($pipes[1], false);

            return new BrokerWorker($process, $pipes[0], $pipes[1], $decoder);
        }
        $this->closeProcess($process, $pipes[0], $pipes[1]);

        return null;
    }

    private function pollParent(): void
    {
        $bytes = is_resource($this->parentStream) ? fread($this->parentStream, 262_144) : false;
        if (!is_string($bytes) || $bytes === '') {
            return;
        }
        foreach ($this->parentDecoder->push($bytes) as $frame) {
            if (!hash_equals($this->epoch, $frame->epoch)) {
                $this->stopping = true;
                continue;
            }
            if ($frame->kind === WorkerFrameKind::SUBMIT) {
                $this->accept($frame);
            } elseif ($frame->kind === WorkerFrameKind::CANCEL) {
                $this->cancel($frame->taskId);
            } elseif ($frame->kind === WorkerFrameKind::SHUTDOWN) {
                $this->stopping = true;
                $this->cancelAllQueued('shutdown');
            }
        }
    }

    private function accept(WorkerFrame $frame): void
    {
        $definition = CoreWorkerTaskCatalog::create()->get($frame->taskTypeId);
        if ($this->stopping || $definition === null || $definition->schemaVersion !== $frame->schemaVersion
            || $definition->lane->value !== (int) ($frame->metadata['lane'] ?? -1)
            || strlen($frame->payload) > $definition->maximumInputBytes) {
            $this->queueFailure($frame, 'invalid-task');

            return;
        }
        $this->queues[$definition->lane->value][] = [$frame, (int) hrtime(true)];
    }

    private function cancel(int $taskId): void
    {
        foreach ($this->queues as $lane => $queue) {
            foreach ($queue as $index => [$frame]) {
                if ($frame->taskId === $taskId) {
                    array_splice($this->queues[$lane], $index, 1);
                    $this->queueParent(new WorkerFrame(
                        WorkerFrameKind::CANCELLED,
                        $this->epoch,
                        $frame->taskId,
                        $frame->taskTypeId,
                        $frame->schemaVersion,
                    ));

                    return;
                }
            }
        }
        foreach ($this->workers as $worker) {
            if ($worker->task?->taskId === $taskId) {
                $worker->discardResult = true;
                $this->cancelledRunning[$taskId] = $taskId;
                $this->queueParent(new WorkerFrame(
                    WorkerFrameKind::CANCELLED,
                    $this->epoch,
                    $taskId,
                    $worker->task->taskTypeId,
                    $worker->task->schemaVersion,
                ));

                return;
            }
        }
    }

    private function dispatch(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->task !== null || ($frame = $this->nextTask()) === null) {
                continue;
            }
            $worker->task = $frame;
            $worker->startedAtNanoseconds = 0;
            $worker->discardResult = false;
            $worker->outgoing = $this->codec->encode($frame);
        }
    }

    /** Advances non-blocking task writes without corrupting frames on partial socket writes. */
    private function flushWorkerInputs(): void
    {
        foreach ($this->workers as $id => $worker) {
            if ($worker->task === null || $worker->outgoing === '') {
                continue;
            }
            $written = @fwrite($worker->input, $worker->outgoing, min(262_144, strlen($worker->outgoing)));
            if ($written === false) {
                if (!$worker->discardResult) {
                    $this->queueFailure($worker->task, 'dispatch-failed');
                }
                $this->terminateWorker($worker, 'dispatch-failed');
                $this->replaceWorker($id);
                continue;
            }
            if ($written < 1) {
                continue;
            }
            $worker->outgoing = (string) substr($worker->outgoing, $written);
            if ($worker->outgoing === '') {
                @fflush($worker->input);
                $worker->startedAtNanoseconds = hrtime(true);
            }
        }
    }

    private function nextTask(): ?WorkerFrame
    {
        $now = hrtime(true);
        $runningByLane = [];
        foreach (WorkerLane::cases() as $lane) {
            $runningByLane[$lane->value] = $this->runningCount($lane);
        }
        $oldestLane = null;
        $oldestAt = PHP_INT_MAX;
        foreach ($this->queues as $lane => $queue) {
            $workerLane = WorkerLane::from($lane);
            if ($queue !== []
                && $this->laneCapacity->canDispatch($workerLane, $runningByLane[$lane])
                && $queue[0][1] < $oldestAt) {
                $oldestAt = $queue[0][1];
                $oldestLane = $lane;
            }
        }
        if ($oldestLane !== null && $now - $oldestAt >= 500_000_000) {
            $entry = array_shift($this->queues[$oldestLane]);

            return $entry === null ? null : $entry[0];
        }
        $attempts = count(self::LANE_CYCLE);
        while ($attempts-- > 0) {
            $lane = self::LANE_CYCLE[$this->laneCursor++ % count(self::LANE_CYCLE)];
            if ($this->queues[$lane] !== []
                && $this->laneCapacity->canDispatch(WorkerLane::from($lane), $runningByLane[$lane])) {
                return array_shift($this->queues[$lane])[0];
            }
        }

        return null;
    }

    private function runningCount(WorkerLane $lane): int
    {
        $running = 0;
        foreach ($this->workers as $worker) {
            if (($worker->task?->metadata['lane'] ?? null) === $lane->value) {
                ++$running;
            }
        }

        return $running;
    }

    private function pollWorkers(): void
    {
        foreach ($this->workers as $worker) {
            $bytes = fread($worker->output, 262_144);
            if (!is_string($bytes) || $bytes === '') {
                continue;
            }
            foreach ($worker->decoder->push($bytes) as $frame) {
                if ($worker->task === null || $frame->taskId !== $worker->task->taskId
                    || !hash_equals($this->epoch, $frame->epoch)
                    || !in_array($frame->kind, [WorkerFrameKind::RESULT, WorkerFrameKind::FAILURE], true)) {
                    $this->terminateWorker($worker, 'protocol-violation');
                    break;
                }
                $memoryBytes = $frame->metadata['memory_bytes'] ?? null;
                if (is_int($memoryBytes) && $memoryBytes >= 0) {
                    $worker->memoryBytes = $memoryBytes;
                }
                if (!$worker->discardResult) {
                    $this->queueParent($frame);
                }
                unset($this->cancelledRunning[$frame->taskId]);
                $worker->task = null;
                $worker->startedAtNanoseconds = 0;
                $worker->discardResult = false;
            }
        }
    }

    private function expireAndRecoverWorkers(): void
    {
        $now = hrtime(true);
        foreach ($this->workers as $id => $worker) {
            if (feof($worker->output) || feof($worker->input)) {
                if ($worker->task !== null && !$worker->discardResult) {
                    $this->queueFailure($worker->task, 'worker-lost');
                }
                $this->replaceWorker($id);
                continue;
            }
            if ($worker->task === null) {
                continue;
            }
            $definition = CoreWorkerTaskCatalog::create()->get($worker->task->taskTypeId);
            $deadlineExpired = $worker->task->deadlineNanoseconds > 0
                && $worker->task->deadlineNanoseconds <= $now;
            $executionExpired = $definition !== null
                && $worker->startedAtNanoseconds > 0
                && $now - $worker->startedAtNanoseconds > $definition->timeoutMilliseconds * 1_000_000;
            if ($deadlineExpired || $executionExpired) {
                if (!$worker->discardResult) {
                    $this->queueFailure($worker->task, 'timeout');
                }
                $this->terminateWorker($worker, 'timeout');
                $this->replaceWorker($id);
            }
        }
        foreach ($this->queues as $lane => $queue) {
            while ($queue !== [] && $queue[0][0]->deadlineNanoseconds > 0 && $queue[0][0]->deadlineNanoseconds <= $now) {
                [$frame] = array_shift($queue);
                $this->queueFailure($frame, 'timeout');
            }
            $this->queues[$lane] = $queue;
        }
    }

    private function replaceWorker(int $id): void
    {
        if (isset($this->workers[$id])) {
            $this->closeWorker($this->workers[$id]);
            unset($this->workers[$id]);
        }
        $now = hrtime(true);
        $this->restartTimes = array_values(array_filter(
            $this->restartTimes,
            static fn(int $time): bool => $now - $time < 60_000_000_000,
        ));
        if (count($this->restartTimes) >= self::MAXIMUM_RESTARTS_PER_MINUTE || $this->stopping) {
            return;
        }
        $delayMilliseconds = min(1_000, 25 * (2 ** count($this->restartTimes))) + random_int(0, 25);
        usleep($delayMilliseconds * 1_000);
        $replacement = $this->startWorker();
        $this->restartTimes[] = (int) hrtime(true);
        ++$this->restarts;
        if ($replacement !== null) {
            $this->workers[$id] = $replacement;
        }
    }

    private function terminateWorker(BrokerWorker $worker, string $reason): void
    {
        unset($this->cancelledRunning[$worker->task->taskId ?? 0]);
        if (is_resource($worker->process)) {
            @proc_terminate($worker->process);
        }
        $worker->task = null;
        $worker->outgoing = '';
        $worker->startedAtNanoseconds = 0;
    }

    private function queueFailure(WorkerFrame $frame, string $code): void
    {
        $this->queueParent(new WorkerFrame(
            WorkerFrameKind::FAILURE,
            $this->epoch,
            $frame->taskId,
            $frame->taskTypeId,
            $frame->schemaVersion,
            metadata: ['code' => $code],
        ));
    }

    private function cancelAllQueued(string $code): void
    {
        foreach ($this->queues as $lane => $queue) {
            foreach ($queue as [$frame]) {
                $this->queueFailure($frame, $code);
            }
            $this->queues[$lane] = [];
        }
    }

    private function queueParent(WorkerFrame $frame): void
    {
        $this->parentOutput .= $this->codec->encode($frame);
        if (strlen($this->parentOutput) > 134_217_728) {
            $this->stopping = true;
        }
    }

    private function flushParent(): void
    {
        if ($this->parentOutput === '') {
            return;
        }
        $remainingBudget = 1_048_576;
        while ($this->parentOutput !== '' && $remainingBudget > 0 && is_resource($this->parentStream)) {
            $attemptBytes = min($remainingBudget, strlen($this->parentOutput), 262_144);
            $written = @fwrite($this->parent(), $this->parentOutput, $attemptBytes);
            if (!is_int($written) || $written < 1) {
                break;
            }
            $this->parentOutput = (string) substr($this->parentOutput, $written);
            $remainingBudget -= $written;
        }
    }

    private function closeAll(): void
    {
        $shutdown = $this->codec->encode(new WorkerFrame(WorkerFrameKind::SHUTDOWN, $this->epoch));
        foreach ($this->workers as $worker) {
            @fwrite($worker->input, $shutdown);
            $this->closeWorker($worker);
        }
        $this->workers = [];
        $this->flushParent();
        if (is_resource($this->parentStream) && $this->parentStream !== STDIN && $this->parentStream !== STDOUT) {
            fclose($this->parentStream);
        }
        $this->parentStream = null;
    }

    /** @return resource|null */
    private function openParentStream()
    {
        if ($this->parentEndpoint === null || $this->parentTokenHex === null) {
            return STDIN;
        }
        $token = hex2bin($this->parentTokenHex);
        $stream = is_string($token) ? @stream_socket_client($this->parentEndpoint, $errorCode, $errorMessage, 5) : false;
        if (!is_resource($stream) || !$this->writeBlocking($stream, $token)) {
            return null;
        }

        return $stream;
    }

    private function closeWorker(BrokerWorker $worker): void
    {
        @fclose($worker->input);
        if ($worker->output !== $worker->input) {
            @fclose($worker->output);
        }
        $status = @proc_get_status($worker->process);
        if ($status['running']) {
            @proc_terminate($worker->process);
        }
        @proc_close($worker->process);
    }

    /**
     * @param resource $process
     * @param resource $input
     * @param resource $output
     */
    private function closeProcess($process, $input, $output): void
    {
        @fclose($input);
        if ($output !== $input) {
            @fclose($output);
        }
        @proc_terminate($process);
        @proc_close($process);
    }

    /** @param resource $stream */
    private function writeBlocking($stream, string $bytes): bool
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

    /** @param resource $stream */
    private function readWorkerFrameBlocking($stream): ?WorkerFrame
    {
        $fixed = $this->readExactly($stream, WorkerFrameCodec::FIXED_HEADER_BYTES);
        if ($fixed === null) {
            return null;
        }
        $lengths = $this->codec->lengths($fixed);
        if ($lengths === null) {
            return null;
        }
        $body = $this->readExactly($stream, $lengths['headerLength'] + $lengths['payloadLength']);

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

    /** @return resource */
    private function parent()
    {
        if (!is_resource($this->parentStream)) {
            throw new \RuntimeException('Worker broker parent channel is unavailable.');
        }

        return $this->parentStream;
    }

    private function hasOutstanding(): bool
    {
        return $this->queuedCount() > 0 || $this->busyCount() > 0 || $this->parentOutput !== '';
    }

    private function queuedCount(): int
    {
        return array_sum(array_map('count', $this->queues));
    }

    private function busyCount(): int
    {
        return count(array_filter($this->workers, static fn(BrokerWorker $worker): bool => $worker->task !== null));
    }
}
