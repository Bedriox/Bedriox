<?php

declare(strict_types=1);

namespace Bedriox\Server\Observability;

use Bedriox\Server\Observability\Internal\LogWriterConfigCodec;
use Bedriox\Server\Observability\Internal\LogWriterProcessProgram;
use Bedriox\Server\Worker\Internal\ProcessEnvironment;
use Bedriox\Server\Worker\Protocol\WorkerFrame;
use Bedriox\Server\Worker\Protocol\WorkerFrameCodec;
use Bedriox\Server\Worker\Protocol\WorkerFrameDecoder;
use Bedriox\Server\Worker\Protocol\WorkerFrameKind;
use RuntimeException;

/** Nonblocking parent endpoint for the dedicated ordered routine-log process. */
final class BackgroundLogWriter
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
    private string $outgoing = '';
    private ?QueuedLogLine $inFlight = null;
    private int $inFlightTaskId = 0;
    private int $nextTaskId = 1;
    private int $acknowledged = 0;
    private int $serviceFailures = 0;
    private bool $available = false;
    private bool $shuttingDown = false;

    private function __construct(
        private readonly BoundedLogQueue $queue,
        private readonly string $applicationVersion,
        private readonly string $path,
        private readonly int $maximumFileBytes,
        private readonly int $history,
        private readonly string $entryPoint,
    ) {
        if ($applicationVersion === '' || strlen($applicationVersion) > 128) {
            throw new \InvalidArgumentException('Background log writer application version is invalid.');
        }
        $this->epoch = random_bytes(16);
        $this->codec = new WorkerFrameCodec();
        $this->decoder = new WorkerFrameDecoder($this->codec, 1_048_576);
    }

    public static function start(
        string $applicationVersion,
        string $path,
        int $maximumFileBytes,
        int $history,
        ?BoundedLogQueue $queue = null,
        ?string $entryPoint = null,
    ): self {
        $writer = new self(
            $queue ?? new BoundedLogQueue(4_096, 8_388_608, 262_144, 128, 524_288),
            $applicationVersion,
            $path,
            $maximumFileBytes,
            $history,
            $entryPoint ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'bedriox-io.php',
        );
        $writer->launch();

        return $writer;
    }

    public function enqueue(int $sequence, LogLevel $level, string $formattedRedactedLine): LogQueueSubmission
    {
        return $this->queue->enqueue($sequence, $level, $formattedRedactedLine);
    }

    public function poll(): void
    {
        $process = $this->process;
        if (!$this->available || !is_resource($process)) {
            return;
        }
        $this->scheduleHead();
        $this->flushOutgoing();
        $bytes = is_resource($this->output) ? @fread($this->output, 262_144) : false;
        if (is_string($bytes) && $bytes !== '') {
            try {
                foreach ($this->decoder->push($bytes, 16) as $frame) {
                    $this->accept($frame);
                }
            } catch (\Throwable) {
                $this->failService();

                return;
            }
        }
        $process = $this->process;
        if (!$this->available || !is_resource($process)) {
            return;
        }
        $status = @proc_get_status($process);
        if (!$status['running']) {
            $this->failService();
        }
    }

    /** Returns false when the deadline expires or the writer becomes unavailable. */
    public function flush(int $timeoutMilliseconds): bool
    {
        if ($timeoutMilliseconds < 1 || $timeoutMilliseconds > 60_000) {
            throw new \InvalidArgumentException('Background log flush timeout is invalid.');
        }
        $deadline = hrtime(true) + $timeoutMilliseconds * 1_000_000;
        do {
            $this->poll();
            if ($this->queue->head() === null && $this->inFlight === null && $this->outgoing === '') {
                return true;
            }
            if (!$this->available) {
                return false;
            }
            usleep(1_000);
        } while (hrtime(true) < $deadline);

        return false;
    }

    /** Flushes pending lines before requesting a bounded clean process exit. */
    public function shutdown(int $timeoutMilliseconds = 5_000): bool
    {
        if ($this->shuttingDown) {
            return !$this->available;
        }
        $this->shuttingDown = true;
        $durable = $this->flush($timeoutMilliseconds);
        if ($this->available) {
            $this->outgoing .= $this->codec->encode(new WorkerFrame(WorkerFrameKind::SHUTDOWN, $this->epoch));
            $deadline = hrtime(true) + $timeoutMilliseconds * 1_000_000;
            do {
                $this->flushOutgoing();
                $status = is_resource($this->process) ? @proc_get_status($this->process) : false;
                if (!is_array($status) || !$status['running']) {
                    break;
                }
                usleep(1_000);
            } while (hrtime(true) < $deadline);
        }
        $this->closeProcess();

        return $durable;
    }

    public function snapshot(): BackgroundLogWriterSnapshot
    {
        return new BackgroundLogWriterSnapshot(
            $this->available,
            $this->inFlight !== null,
            $this->queue->snapshot(),
            $this->acknowledged,
            $this->serviceFailures,
        );
    }

    public function __destruct()
    {
        if (!$this->shuttingDown) {
            $this->shutdown(1_000);
        }
    }

    private function launch(): void
    {
        if (!is_file($this->entryPoint)) {
            throw new RuntimeException('Background log writer entry point is unavailable.');
        }
        $listener = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        if (!is_resource($listener)) {
            throw new RuntimeException('Background log writer IPC listener could not be created.');
        }
        $endpoint = stream_socket_get_name($listener, false);
        if (!is_string($endpoint) || $endpoint === '') {
            @fclose($listener);
            throw new RuntimeException('Background log writer IPC endpoint is unavailable.');
        }
        $token = random_bytes(32);
        $pipes = [];
        $process = @proc_open(
            ProcessEnvironment::phpCommand(
                $this->entryPoint,
                'log',
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
            throw new RuntimeException('Background log writer could not be started.');
        }
        $connection = @stream_socket_accept($listener, 5);
        @fclose($listener);
        if (!is_resource($connection)) {
            @proc_terminate($process);
            throw new RuntimeException('Background log writer did not connect to its local IPC endpoint.');
        }
        stream_set_blocking($connection, true);
        stream_set_timeout($connection, 5);
        $receivedToken = $this->readExact($connection, strlen($token));
        if (!is_string($receivedToken) || !hash_equals($token, $receivedToken)) {
            @fclose($connection);
            @proc_terminate($process);
            throw new RuntimeException('Background log writer IPC authentication failed.');
        }
        $this->process = $process;
        $this->input = $connection;
        $this->output = $connection;
        stream_set_blocking($connection, true);
        $nonce = bin2hex(random_bytes(16));
        $config = (new LogWriterConfigCodec())->encode($this->path, $this->maximumFileBytes, $this->history);
        $this->writeBlocking($this->codec->encode(new WorkerFrame(
            WorkerFrameKind::HELLO,
            $this->epoch,
            metadata: LogWriterProcessProgram::identity($this->applicationVersion, $nonce),
            payload: $config,
        )));
        $ready = $this->readBlockingFrame(5_000);
        if ($ready === null || $ready->kind !== WorkerFrameKind::READY || !hash_equals($this->epoch, $ready->epoch)
            || $ready->metadata !== LogWriterProcessProgram::identity($this->applicationVersion, $nonce)) {
            $this->closeProcess();
            throw new RuntimeException('Background log writer handshake failed.');
        }
        stream_set_blocking($connection, false);
        $this->available = true;
    }

    private function scheduleHead(): void
    {
        if ($this->inFlight !== null || $this->outgoing !== '') {
            return;
        }
        $line = $this->queue->head();
        if (!$line instanceof QueuedLogLine) {
            return;
        }
        $taskId = $this->nextTaskId++;
        if ($this->nextTaskId > 0xffffffff) {
            $this->nextTaskId = 1;
        }
        $this->inFlight = $line;
        $this->inFlightTaskId = $taskId;
        $this->outgoing = $this->codec->encode(new WorkerFrame(
            WorkerFrameKind::SUBMIT,
            $this->epoch,
            $taskId,
            LogWriterProcessProgram::TASK_TYPE,
            LogWriterProcessProgram::SCHEMA_VERSION,
            metadata: ['sequence' => $line->sequence],
            payload: $line->line,
        ));
    }

    private function accept(WorkerFrame $frame): void
    {
        if ($this->inFlight === null || !hash_equals($this->epoch, $frame->epoch)
            || $frame->taskId !== $this->inFlightTaskId
            || $frame->taskTypeId !== LogWriterProcessProgram::TASK_TYPE
            || $frame->schemaVersion !== LogWriterProcessProgram::SCHEMA_VERSION) {
            $this->failService();

            return;
        }
        if ($frame->kind === WorkerFrameKind::RESULT) {
            $this->queue->acknowledge($this->inFlight->sequence);
            $this->inFlight = null;
            $this->inFlightTaskId = 0;
            ++$this->acknowledged;

            return;
        }
        $this->queue->recordWriteFailure();
        $this->failService();
    }

    private function flushOutgoing(): void
    {
        if ($this->outgoing === '' || !is_resource($this->input)) {
            return;
        }
        $written = @fwrite($this->input, $this->outgoing);
        if (is_int($written) && $written > 0) {
            $this->outgoing = substr($this->outgoing, $written);
        }
    }

    private function failService(): void
    {
        if (!$this->available) {
            return;
        }
        ++$this->serviceFailures;
        $this->available = false;
        $this->outgoing = '';
        $this->inFlight = null;
        $this->inFlightTaskId = 0;
        $this->closeProcess();
    }

    private function writeBlocking(string $bytes): void
    {
        while ($bytes !== '' && is_resource($this->input)) {
            $written = @fwrite($this->input, $bytes);
            if (!is_int($written) || $written < 1) {
                throw new RuntimeException('Background log writer handshake write failed.');
            }
            $bytes = substr($bytes, $written);
        }
        if (is_resource($this->input)) {
            fflush($this->input);
        }
    }

    private function readBlockingFrame(int $timeoutMilliseconds): ?WorkerFrame
    {
        if (!is_resource($this->output)) {
            return null;
        }
        stream_set_timeout($this->output, intdiv($timeoutMilliseconds, 1_000), ($timeoutMilliseconds % 1_000) * 1_000);
        $header = $this->readExact($this->output, WorkerFrameCodec::FIXED_HEADER_BYTES);
        if ($header === null) {
            return null;
        }
        $lengths = $this->codec->lengths($header);
        if ($lengths === null) {
            return null;
        }
        $body = $this->readExact($this->output, $lengths['headerLength'] + $lengths['payloadLength']);

        return $body === null ? null : $this->codec->decode($header . $body);
    }

    /** @param resource $stream */
    private function readExact($stream, int $length): ?string
    {
        if ($length < 0) {
            throw new \InvalidArgumentException('IPC read length cannot be negative.');
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
            $this->{$property} = null;
        }
        if (is_resource($this->process)) {
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
        $this->available = false;
    }
}
