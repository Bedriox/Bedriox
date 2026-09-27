<?php

declare(strict_types=1);

namespace Bedriox\Server\Transport;

use Bedriox\RakNet\ConnectedHandshakeDiagnosticBatch;
use Bedriox\RakNet\ConnectedHandshakeDiagnosticEvent;
use Bedriox\RakNet\ConnectedHandshakeRejectionReason;
use Bedriox\RakNet\ConnectedHandshakeStage;
use Bedriox\RakNet\DiscoveryStatus;
use Bedriox\RakNet\Protocol\Reliability;
use Bedriox\RakNet\ReceivedPayload;
use Bedriox\RakNet\SessionClosedEvent;
use Bedriox\RakNet\SessionCloseReason;
use Bedriox\RakNet\SessionInfo;
use Bedriox\RakNet\SessionOpenedEvent;
use Bedriox\RakNet\SessionTransportFailureReason;
use Bedriox\RakNet\TransportConfig;
use Bedriox\Server\Runtime\RuntimeDiagnostics;
use Bedriox\Server\Transport\Process\TransportProcessFrame;
use Bedriox\Server\Transport\Process\TransportProcessFrameCodec;
use Bedriox\Server\Transport\Process\TransportProcessFrameDecoder;
use Bedriox\Server\Transport\Process\TransportProcessFrameKind;
use Bedriox\Server\Transport\Process\TransportProcessProgram;
use Bedriox\Server\Worker\Internal\IpcSocketTuning;
use Bedriox\Server\Worker\Internal\ProcessEnvironment;
use OverflowException;
use RuntimeException;
use Throwable;

/** Bridges the simulation process to a dedicated RakNet/UDP process. */
final class ProcessDiscoveryServerTransport implements ConnectedTransport
{
    private const int MAXIMUM_BUFFERED_BYTES = 67_108_864;
    private const int READ_BYTES_PER_POLL = 4_194_304;
    private const int WRITE_BYTES_PER_POLL = 4_194_304;

    /** @var resource|null */
    private $process = null;
    /** @var resource|null */
    private $stream = null;
    private readonly TransportProcessFrameCodec $codec;
    private readonly TransportProcessFrameDecoder $decoder;
    private readonly RakNetHandshakeDiagnosticReporter $diagnosticReporter;
    private string $outgoing = '';
    private int $outgoingOffset = 0;
    /** @var array<string, int> */
    private array $sessionIdsByEndpoint = [];
    /** @var array<int, SessionInfo> */
    private array $sessionsById = [];
    /** @var list<SessionOpenedEvent|SessionClosedEvent> */
    private array $sessionEvents = [];
    /** @var list<ReceivedPayload> */
    private array $receivedPayloads = [];
    private int $receivedPayloadBytes = 0;
    private bool $closed = false;
    private string $localAddress = '';
    private int $localPort = 0;

    private function __construct(
        private readonly int $maximumSessionEvents,
        private readonly int $maximumReceivedPayloads,
        private readonly int $maximumReceivedPayloadBytes,
        RuntimeDiagnostics $diagnostics,
        private readonly string $entryPoint,
    ) {
        $this->codec = new TransportProcessFrameCodec();
        $this->decoder = new TransportProcessFrameDecoder($this->codec);
        $this->diagnosticReporter = new RakNetHandshakeDiagnosticReporter($diagnostics);
    }

    public static function start(
        TransportConfig $config,
        int $serverGuid,
        DiscoveryStatus $status,
        ?RuntimeDiagnostics $diagnostics = null,
        ?string $entryPoint = null,
    ): self {
        $entryPoint ??= dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bootstrap'
            . DIRECTORY_SEPARATOR . 'bedriox-transport.php';
        $transport = new self(
            $config->maximumSessionEvents,
            $config->maximumReceivedPayloads,
            $config->maximumReceivedPayloadBytes,
            $diagnostics ?? RuntimeDiagnostics::disabled(),
            $entryPoint,
        );
        $transport->launch($config, $serverGuid, $status);

        return $transport;
    }

    public function localAddress(): string
    {
        return $this->localAddress;
    }

    public function localPort(): int
    {
        return $this->localPort;
    }

    public function poll(int $maximumDatagrams): int
    {
        $stream = $this->stream;
        if ($this->closed || !is_resource($stream)) {
            throw new RuntimeException('Cannot poll a closed transport process.');
        }
        if ($maximumDatagrams < 1 || $maximumDatagrams > 4_096) {
            throw new \InvalidArgumentException('Transport poll limit is invalid.');
        }
        $this->flushOutgoing();
        $read = 0;
        while ($read < self::READ_BYTES_PER_POLL) {
            $bytes = @fread($stream, min(1_048_576, self::READ_BYTES_PER_POLL - $read));
            if ($bytes === false) {
                throw new RuntimeException('Transport process IPC read failed.');
            }
            if ($bytes === '') {
                break;
            }
            $read += strlen($bytes);
            $this->decoder->append($bytes);
        }
        $handled = 0;
        foreach ($this->decoder->drain(min(8_192, max(256, $maximumDatagrams * 4))) as $frame) {
            $this->acceptFrame($frame);
            ++$handled;
        }
        if (feof($stream)) {
            throw new RuntimeException('RakNet transport process exited unexpectedly.');
        }

        return $handled;
    }

    public function drainSessionEvents(): array
    {
        $events = $this->sessionEvents;
        $this->sessionEvents = [];

        return $events;
    }

    public function drainReceivedPayloads(): array
    {
        $payloads = $this->receivedPayloads;
        $this->receivedPayloads = [];
        $this->receivedPayloadBytes = 0;

        return $payloads;
    }

    public function sendPayload(
        string $remoteAddress,
        int $remotePort,
        string $payload,
        Reliability $reliability,
        int $orderingChannel = 0,
    ): void {
        $sessionId = $this->sessionIdsByEndpoint[self::endpointKey($remoteAddress, $remotePort)] ?? null;
        if ($sessionId === null) {
            throw new RuntimeException('Cannot send to an unknown transport-process endpoint.');
        }
        $this->queue(new TransportProcessFrame(
            TransportProcessFrameKind::SEND_PAYLOAD,
            $sessionId,
            $payload,
            $reliability,
            $reliability === Reliability::ReliableOrdered ? $orderingChannel : null,
        ));
    }

    public function removeSession(string $remoteAddress, int $remotePort): bool
    {
        $sessionId = $this->sessionIdsByEndpoint[self::endpointKey($remoteAddress, $remotePort)] ?? null;
        if ($sessionId === null || $this->closed) {
            return false;
        }
        $this->queue(new TransportProcessFrame(TransportProcessFrameKind::REMOVE_SESSION, $sessionId));

        return true;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $stream = $this->stream;
        if (is_resource($stream)) {
            try {
                $this->queue(new TransportProcessFrame(TransportProcessFrameKind::SHUTDOWN));
                $deadline = hrtime(true) + 500_000_000;
                do {
                    $this->flushOutgoing();
                    if ($this->outgoingBytes() === 0) {
                        break;
                    }
                    usleep(1_000);
                } while (hrtime(true) < $deadline);
            } catch (Throwable) {
            }
            fclose($stream);
        }
        $this->stream = null;
        $this->closeProcess();
        $this->sessionIdsByEndpoint = [];
        $this->sessionsById = [];
        $this->sessionEvents = [];
        $this->receivedPayloads = [];
        $this->receivedPayloadBytes = 0;
        $this->outgoing = '';
        $this->outgoingOffset = 0;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function launch(TransportConfig $config, int $serverGuid, DiscoveryStatus $status): void
    {
        if (!is_file($this->entryPoint)) {
            throw new RuntimeException('RakNet transport process entry point is unavailable.');
        }
        $listener = @stream_socket_server(
            'tcp://127.0.0.1:0',
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        $address = is_resource($listener) ? @stream_socket_get_name($listener, false) : false;
        if (!is_resource($listener) || !is_string($address) || $address === '') {
            throw new RuntimeException('RakNet transport IPC listener could not be created.');
        }
        $token = random_bytes(32);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $pipes = [];
        $process = @proc_open(
            ProcessEnvironment::phpCommand(
                $this->entryPoint,
                'tcp://' . $address,
                bin2hex($token),
                TransportProcessProgram::encodeConfiguration($config, $serverGuid, $status),
            ),
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'a'], 2 => ['file', $null, 'a']],
            $pipes,
            dirname($this->entryPoint, 2),
            ProcessEnvironment::allowlisted(),
            ['bypass_shell' => true, 'blocking_pipes' => false, 'create_process_group' => true],
        );
        if (!is_resource($process)) {
            fclose($listener);
            throw new RuntimeException('RakNet transport process could not be started.');
        }
        $connection = @stream_socket_accept($listener, 5);
        fclose($listener);
        if (!is_resource($connection)) {
            @proc_terminate($process);
            @proc_close($process);
            throw new RuntimeException('RakNet transport process did not connect.');
        }
        IpcSocketTuning::apply($connection);
        stream_set_timeout($connection, 5);
        $receivedToken = self::readExactly($connection, strlen($token));
        if (!is_string($receivedToken) || !hash_equals($token, $receivedToken)) {
            fclose($connection);
            @proc_terminate($process);
            @proc_close($process);
            throw new RuntimeException('RakNet transport process authentication failed.');
        }
        $ready = $this->readBlockingFrame($connection);
        if ($ready === null || $ready->kind !== TransportProcessFrameKind::READY
            || !is_string($ready->metadata['address'] ?? null)
            || !is_int($ready->metadata['port'] ?? null)) {
            fclose($connection);
            @proc_terminate($process);
            @proc_close($process);
            throw new RuntimeException('RakNet transport process failed to bind and become ready.');
        }
        $this->localAddress = $ready->metadata['address'];
        $this->localPort = $ready->metadata['port'];
        $this->process = $process;
        $this->stream = $connection;
        stream_set_blocking($connection, false);
    }

    private function acceptFrame(TransportProcessFrame $frame): void
    {
        if ($frame->kind === TransportProcessFrameKind::SESSION_OPENED) {
            $session = $this->decodeOpenedSession($frame);
            if (count($this->sessionEvents) >= $this->maximumSessionEvents) {
                throw new OverflowException('Transport process session-event queue limit reached.');
            }
            $key = self::endpointKey($session->remoteAddress, $session->remotePort);
            $this->sessionIdsByEndpoint[$key] = $frame->sessionId;
            $this->sessionsById[$frame->sessionId] = $session;
            $this->sessionEvents[] = new SessionOpenedEvent($session);
            return;
        }
        if ($frame->kind === TransportProcessFrameKind::SESSION_CLOSED) {
            $session = $this->sessionsById[$frame->sessionId] ?? null;
            if (!$session instanceof SessionInfo) {
                return;
            }
            if (count($this->sessionEvents) >= $this->maximumSessionEvents) {
                throw new OverflowException('Transport process session-event queue limit reached.');
            }
            $reasonValue = $frame->metadata['reason'] ?? null;
            $reason = is_string($reasonValue) ? SessionCloseReason::tryFrom($reasonValue) : null;
            $failureValue = $frame->metadata['transport_failure'] ?? null;
            $failure = is_string($failureValue) ? SessionTransportFailureReason::tryFrom($failureValue) : null;
            $detail = $frame->metadata['transport_failure_detail'] ?? null;
            $this->sessionEvents[] = new SessionClosedEvent(
                $session,
                $reason ?? SessionCloseReason::TransportFailure,
                $failure,
                is_string($detail) ? $detail : null,
            );
            unset(
                $this->sessionIdsByEndpoint[self::endpointKey($session->remoteAddress, $session->remotePort)],
                $this->sessionsById[$frame->sessionId],
            );
            return;
        }
        if ($frame->kind === TransportProcessFrameKind::RECEIVED_PAYLOAD) {
            $session = $this->sessionsById[$frame->sessionId] ?? null;
            if (!$session instanceof SessionInfo || !$frame->reliability instanceof Reliability) {
                return;
            }
            $bytes = strlen($frame->payload);
            if (count($this->receivedPayloads) >= $this->maximumReceivedPayloads
                || $bytes > $this->maximumReceivedPayloadBytes - $this->receivedPayloadBytes) {
                throw new OverflowException('Transport process received-payload queue limit reached.');
            }
            $this->receivedPayloads[] = new ReceivedPayload(
                $session->remoteAddress,
                $session->remotePort,
                $frame->payload,
                $frame->reliability,
                $frame->orderingChannel,
            );
            $this->receivedPayloadBytes += $bytes;
            return;
        }
        if ($frame->kind === TransportProcessFrameKind::HANDSHAKE_DIAGNOSTICS) {
            $this->acceptDiagnostics($frame);
            return;
        }
        if ($frame->kind === TransportProcessFrameKind::FAILURE) {
            $code = $frame->metadata['code'] ?? 'unknown';
            throw new RuntimeException('RakNet transport process reported failure: ' . (is_string($code) ? $code : 'unknown'));
        }
        throw new RuntimeException('RakNet transport process emitted an invalid parent frame.');
    }

    private function decodeOpenedSession(TransportProcessFrame $frame): SessionInfo
    {
        $address = $frame->metadata['address'] ?? null;
        $port = $frame->metadata['port'] ?? null;
        $guid = $frame->metadata['guid'] ?? null;
        $mtu = $frame->metadata['mtu'] ?? null;
        $protocol = $frame->metadata['protocol'] ?? null;
        if (!is_string($address) || !is_int($port) || !is_string($guid) || !is_int($mtu) || !is_int($protocol)) {
            throw new RuntimeException('RakNet transport process opened-session metadata is invalid.');
        }

        return new SessionInfo($address, $port, (int) $guid, $mtu, $protocol);
    }

    private function acceptDiagnostics(TransportProcessFrame $frame): void
    {
        $rawEvents = $frame->metadata['events'] ?? null;
        $dropped = $frame->metadata['dropped'] ?? null;
        if (!is_array($rawEvents) || !is_int($dropped) || $dropped < 0) {
            throw new RuntimeException('RakNet transport diagnostic metadata is invalid.');
        }
        $events = [];
        foreach ($rawEvents as $raw) {
            if (!is_array($raw)) {
                throw new RuntimeException('RakNet transport diagnostic event is invalid.');
            }
            $address = $raw['address'] ?? null;
            $port = $raw['port'] ?? null;
            $stageValue = $raw['stage'] ?? null;
            $reasonValue = $raw['reason'] ?? null;
            if (!is_string($address) || !is_int($port) || !is_string($stageValue) || !is_string($reasonValue)) {
                throw new RuntimeException('RakNet transport diagnostic event metadata is invalid.');
            }
            $stage = ConnectedHandshakeStage::tryFrom($stageValue);
            $reason = ConnectedHandshakeRejectionReason::tryFrom($reasonValue);
            $reliabilityValue = $raw['reliability'] ?? null;
            $reliability = is_int($reliabilityValue) ? Reliability::tryFrom($reliabilityValue) : null;
            if ($stage === null || $reason === null) {
                throw new RuntimeException('RakNet transport diagnostic enum is invalid.');
            }
            $events[] = new ConnectedHandshakeDiagnosticEvent(
                $address,
                $port,
                $stage,
                $reason,
                is_int($raw['datagram_id'] ?? null) ? $raw['datagram_id'] : null,
                is_int($raw['control_packet_id'] ?? null) ? $raw['control_packet_id'] : null,
                is_int($raw['payload_length'] ?? null) ? $raw['payload_length'] : null,
                $reliability,
                is_int($raw['ordering_channel'] ?? null) ? $raw['ordering_channel'] : null,
            );
        }
        $this->diagnosticReporter->report(new ConnectedHandshakeDiagnosticBatch($events, $dropped));
    }

    private function queue(TransportProcessFrame $frame): void
    {
        if (!is_resource($this->stream)) {
            throw new RuntimeException('RakNet transport process is unavailable.');
        }
        $encoded = $this->codec->encode($frame);
        if (strlen($encoded) > self::MAXIMUM_BUFFERED_BYTES - $this->outgoingBytes()) {
            throw new OverflowException('RakNet transport process output queue limit reached.');
        }
        $this->outgoing .= $encoded;
    }

    private function flushOutgoing(): void
    {
        if ($this->outgoingBytes() === 0 || !is_resource($this->stream)) {
            return;
        }
        $writtenTotal = 0;
        while ($this->outgoingBytes() > 0 && $writtenTotal < self::WRITE_BYTES_PER_POLL) {
            $attempt = min(262_144, self::WRITE_BYTES_PER_POLL - $writtenTotal, $this->outgoingBytes());
            $written = @fwrite($this->stream, substr($this->outgoing, $this->outgoingOffset, $attempt));
            if ($written === false) {
                throw new RuntimeException('RakNet transport process IPC write failed.');
            }
            if ($written < 1) {
                break;
            }
            $this->outgoingOffset += $written;
            $writtenTotal += $written;
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

    /** @param resource $stream */
    private function readBlockingFrame($stream): ?TransportProcessFrame
    {
        $header = self::readExactly($stream, TransportProcessFrameCodec::PREFIX_BYTES);
        if (!is_string($header)) {
            return null;
        }
        /** @var array{length: int} $decoded */
        $decoded = unpack('Nlength', $header);
        $length = $decoded['length'];
        if ($length < 5 || $length > TransportProcessFrameCodec::MAXIMUM_FRAME_BYTES) {
            return null;
        }
        $body = self::readExactly($stream, $length);

        return is_string($body) ? $this->codec->decode($body) : null;
    }

    /** @param resource $stream */
    private static function readExactly($stream, int $length): ?string
    {
        if ($length < 1) {
            return null;
        }
        $bytes = '';
        while (strlen($bytes) < $length) {
            $remaining = $length - strlen($bytes);
            if ($remaining < 1) {
                break;
            }
            $chunk = @fread($stream, $remaining);
            if (!is_string($chunk) || $chunk === '') {
                return null;
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }

    private function closeProcess(): void
    {
        if (!is_resource($this->process)) {
            $this->process = null;
            return;
        }
        $status = @proc_get_status($this->process);
        if ($status['running']) {
            @proc_terminate($this->process);
        }
        @proc_close($this->process);
        $this->process = null;
    }

    private static function endpointKey(string $address, int $port): string
    {
        return $address . ':' . $port;
    }
}
