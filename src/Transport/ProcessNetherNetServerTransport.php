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

namespace Bedriox\Server\Transport;

use Bedriox\NetherNet\Protocol\DisconnectReason;
use Bedriox\NetherNet\Protocol\Reliability;
use Bedriox\NetherNet\Server\ServerSecuritySnapshot;
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

/** Bridges the simulation process to the dedicated NetherNet process. */
final class ProcessNetherNetServerTransport implements ConnectedTransport
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
    private string $outgoing = '';
    private int $outgoingOffset = 0;
    /** @var array<int, SessionInfo> */
    private array $sessionsById = [];
    /** @var list<SessionOpenedEvent|SessionClosedEvent> */
    private array $sessionEvents = [];
    /** @var list<ReceivedPayload> */
    private array $receivedPayloads = [];
    /** @var list<PayloadAcknowledgedEvent> */
    private array $payloadAcknowledgements = [];
    private int $receivedPayloadBytes = 0;
    private bool $closed = false;
    private string $localAddress = '';
    private int $localPort = 0;
    private int $bytesSent = 0;
    private int $bytesReceived = 0;
    /** @var array<int, int> */
    private array $pingBySessionId = [];
    private ?ServerSecuritySnapshot $securitySnapshot = null;

    private function __construct(
        private readonly int $maximumSessionEvents,
        private readonly int $maximumReceivedPayloads,
        private readonly int $maximumReceivedPayloadBytes,
        private readonly string $entryPoint,
    ) {
        $this->codec = new TransportProcessFrameCodec();
        $this->decoder = new TransportProcessFrameDecoder($this->codec);
    }

    public static function start(NetherNetTransportConfig $config, ?string $entryPoint = null): self
    {
        $entryPoint ??= dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bootstrap'
            . DIRECTORY_SEPARATOR . 'bedriox-transport.php';
        $transport = new self(
            $config->maximumSessionEvents,
            $config->maximumReceivedPayloads,
            $config->maximumReceivedPayloadBytes,
            $entryPoint,
        );
        $transport->launch($config);

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

    /** @return array{sent: int, received: int} */
    public function bandwidth(): array
    {
        return ['sent' => $this->bytesSent, 'received' => $this->bytesReceived];
    }

    public function ping(int $sessionId): ?int
    {
        return $this->pingBySessionId[$sessionId] ?? null;
    }

    public function securitySnapshot(): ?ServerSecuritySnapshot
    {
        return $this->securitySnapshot;
    }

    public function poll(int $maximumDatagrams): int
    {
        $stream = $this->stream;
        if ($this->closed || !is_resource($stream)) {
            throw new RuntimeException('Cannot poll a closed NetherNet process.');
        }
        if ($maximumDatagrams < 1 || $maximumDatagrams > 4_096) {
            throw new \InvalidArgumentException('Transport poll limit is invalid.');
        }
        $this->flushOutgoing();
        $read = 0;
        while ($read < self::READ_BYTES_PER_POLL) {
            $bytes = @fread($stream, min(1_048_576, self::READ_BYTES_PER_POLL - $read));
            if ($bytes === false) {
                throw new RuntimeException('NetherNet process IPC read failed.');
            }
            if ($bytes === '') {
                break;
            }
            $read += strlen($bytes);
            $this->decoder->append($bytes);
        }
        $handled = 0;
        foreach ($this->decoder->drain(min(8_192, max(64, $maximumDatagrams * 2))) as $frame) {
            $this->acceptFrame($frame);
            ++$handled;
        }
        if (feof($stream)) {
            throw new RuntimeException('NetherNet process exited unexpectedly.');
        }

        return $handled;
    }

    public function drainSessionEvents(): array
    {
        $events = $this->sessionEvents;
        $this->sessionEvents = [];

        return $events;
    }

    public function drainPayloadAcknowledgements(): array
    {
        $events = $this->payloadAcknowledgements;
        $this->payloadAcknowledgements = [];

        return $events;
    }

    public function drainReceivedPayloads(): array
    {
        $payloads = $this->receivedPayloads;
        $this->receivedPayloads = [];
        $this->receivedPayloadBytes = 0;

        return $payloads;
    }

    public function sendPayload(int $sessionId, string $payload, Reliability $reliability, ?int $receiptId = null): void
    {
        if (!isset($this->sessionsById[$sessionId])) {
            throw new RuntimeException('Cannot send to an unknown NetherNet session.');
        }
        $this->queue(new TransportProcessFrame(
            TransportProcessFrameKind::SEND_PAYLOAD,
            $sessionId,
            $payload,
            $reliability,
            receiptId: $receiptId,
        ));
    }

    public function removeSession(int $sessionId): bool
    {
        if (!isset($this->sessionsById[$sessionId]) || $this->closed) {
            return false;
        }
        $this->queue(new TransportProcessFrame(TransportProcessFrameKind::REMOVE_SESSION, $sessionId));

        return true;
    }

    public function updatePlayerCount(int $onlinePlayers): void
    {
        if ($onlinePlayers < 0 || $this->closed) {
            throw new \InvalidArgumentException('NetherNet advertised player count is invalid.');
        }
        $this->queue(new TransportProcessFrame(
            TransportProcessFrameKind::UPDATE_PLAYER_COUNT,
            metadata: ['online_players' => $onlinePlayers],
        ));
    }

    public function updateAdvertisement(string $serverName, int $onlinePlayers, int $maximumPlayers): void
    {
        if ($serverName === '' || strlen($serverName) > 128 || preg_match('//u', $serverName) !== 1
            || $onlinePlayers < 0 || $maximumPlayers < 1 || $onlinePlayers > $maximumPlayers || $this->closed) {
            throw new \InvalidArgumentException('NetherNet advertisement update is invalid.');
        }
        $this->queue(new TransportProcessFrame(
            TransportProcessFrameKind::UPDATE_ADVERTISEMENT,
            metadata: [
                'server_name' => $serverName,
                'online_players' => $onlinePlayers,
                'maximum_players' => $maximumPlayers,
            ],
        ));
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
        $this->sessionsById = [];
        $this->sessionEvents = [];
        $this->receivedPayloads = [];
        $this->receivedPayloadBytes = 0;
        $this->pingBySessionId = [];
        $this->securitySnapshot = null;
        $this->outgoing = '';
        $this->outgoingOffset = 0;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function launch(NetherNetTransportConfig $config): void
    {
        if (!is_file($this->entryPoint)) {
            throw new RuntimeException('NetherNet process entry point is unavailable.');
        }
        $listener = @stream_socket_server(
            'tcp://127.0.0.1:0',
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        $address = is_resource($listener) ? @stream_socket_get_name($listener, false) : false;
        if (!is_resource($listener) || !is_string($address) || $address === '') {
            throw new RuntimeException('NetherNet IPC listener could not be created.');
        }
        $token = random_bytes(32);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $pipes = [];
        $process = @proc_open(
            ProcessEnvironment::phpCommand(
                $this->entryPoint,
                'tcp://' . $address,
                bin2hex($token),
                TransportProcessProgram::encodeConfiguration($config),
            ),
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'a'], 2 => ['file', $null, 'a']],
            $pipes,
            dirname($this->entryPoint, 2),
            ProcessEnvironment::allowlisted(),
            ['bypass_shell' => true, 'blocking_pipes' => false, 'create_process_group' => true],
        );
        if (!is_resource($process)) {
            fclose($listener);
            throw new RuntimeException('NetherNet process could not be started.');
        }
        $connection = @stream_socket_accept($listener, 5);
        fclose($listener);
        if (!is_resource($connection)) {
            @proc_terminate($process);
            @proc_close($process);
            throw new RuntimeException('NetherNet process did not connect.');
        }
        IpcSocketTuning::apply($connection);
        stream_set_timeout($connection, 5);
        $receivedToken = self::readExactly($connection, strlen($token));
        if (!is_string($receivedToken) || !hash_equals($token, $receivedToken)) {
            fclose($connection);
            @proc_terminate($process);
            @proc_close($process);
            throw new RuntimeException('NetherNet process authentication failed.');
        }
        $ready = $this->readBlockingFrame($connection);
        if ($ready?->kind === TransportProcessFrameKind::FAILURE) {
            $code = $ready->metadata['code'] ?? 'unknown';
            fclose($connection);
            @proc_terminate($process);
            @proc_close($process);
            throw new RuntimeException('NetherNet process could not start: ' . (is_string($code) ? $code : 'unknown'));
        }
        if ($ready === null || $ready->kind !== TransportProcessFrameKind::READY
            || !is_string($ready->metadata['address'] ?? null)
            || !is_int($ready->metadata['port'] ?? null)) {
            fclose($connection);
            @proc_terminate($process);
            @proc_close($process);
            throw new RuntimeException('NetherNet process failed to bind and become ready.');
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
            if (count($this->sessionEvents) >= $this->maximumSessionEvents) {
                throw new OverflowException('NetherNet session-event queue limit reached.');
            }
            $session = $this->decodeOpenedSession($frame);
            $this->sessionsById[$frame->sessionId] = $session;
            $this->sessionEvents[] = new SessionOpenedEvent($session);
            return;
        }
        if ($frame->kind === TransportProcessFrameKind::SESSION_CLOSED) {
            $session = $this->sessionsById[$frame->sessionId] ?? null;
            if ($session === null) {
                return;
            }
            if (count($this->sessionEvents) >= $this->maximumSessionEvents) {
                throw new OverflowException('NetherNet session-event queue limit reached.');
            }
            $reason = is_int($frame->metadata['reason'] ?? null)
                ? DisconnectReason::tryFrom($frame->metadata['reason'])
                : null;
            $this->sessionEvents[] = new SessionClosedEvent(
                $session,
                $reason ?? DisconnectReason::CONNECTION_FAILED,
            );
            unset($this->sessionsById[$frame->sessionId], $this->pingBySessionId[$frame->sessionId]);
            return;
        }
        if ($frame->kind === TransportProcessFrameKind::RECEIVED_PAYLOAD) {
            if ($frame->reliability !== null && isset($this->sessionsById[$frame->sessionId])) {
                $this->acceptReceivedPayload($frame->sessionId, $frame->payload, $frame->reliability);
            }
            return;
        }
        if ($frame->kind === TransportProcessFrameKind::PAYLOAD_ACKNOWLEDGED) {
            $receiptId = $frame->metadata['receipt_id'] ?? null;
            if (is_int($receiptId) && $receiptId > 0 && isset($this->sessionsById[$frame->sessionId])) {
                $this->payloadAcknowledgements[] = new PayloadAcknowledgedEvent($frame->sessionId, $receiptId);
            }
            return;
        }
        if ($frame->kind === TransportProcessFrameKind::BANDWIDTH_STATS) {
            $sent = $frame->metadata['sent'] ?? null;
            $received = $frame->metadata['received'] ?? null;
            if (is_int($sent) && is_int($received) && $sent >= 0 && $received >= 0) {
                $this->bytesSent += $sent;
                $this->bytesReceived += $received;
            }
            return;
        }
        if ($frame->kind === TransportProcessFrameKind::PING_MEASURED) {
            $milliseconds = $frame->metadata['milliseconds'] ?? null;
            if (is_int($milliseconds) && $milliseconds >= 0 && isset($this->sessionsById[$frame->sessionId])) {
                $this->pingBySessionId[$frame->sessionId] = $milliseconds;
            }
            return;
        }
        if ($frame->kind === TransportProcessFrameKind::SECURITY_SNAPSHOT) {
            $values = [];
            foreach ([
                'active_sessions',
                'maximum_sessions',
                'active_negotiations',
                'active_address_blocks',
                'negotiation_flood_blocks',
                'session_limit_rejections',
                'blocked_peer_rejections',
            ] as $name) {
                $value = $frame->metadata[$name] ?? null;
                if (!is_int($value) || $value < 0) {
                    throw new RuntimeException('NetherNet security snapshot is invalid.');
                }
                $values[$name] = $value;
            }
            $this->securitySnapshot = new ServerSecuritySnapshot(
                $values['active_sessions'],
                $values['maximum_sessions'],
                $values['active_negotiations'],
                $values['active_address_blocks'],
                $values['negotiation_flood_blocks'],
                $values['session_limit_rejections'],
                $values['blocked_peer_rejections'],
            );
            return;
        }
        if ($frame->kind === TransportProcessFrameKind::FAILURE) {
            $code = $frame->metadata['code'] ?? 'unknown';
            throw new RuntimeException('NetherNet process reported failure: ' . (is_string($code) ? $code : 'unknown'));
        }
        throw new RuntimeException('NetherNet process emitted an invalid parent frame.');
    }

    private function acceptReceivedPayload(int $sessionId, string $payload, Reliability $reliability): void
    {
        $bytes = strlen($payload);
        if (count($this->receivedPayloads) >= $this->maximumReceivedPayloads
            || $bytes > $this->maximumReceivedPayloadBytes - $this->receivedPayloadBytes) {
            throw new OverflowException('NetherNet received-payload queue limit reached.');
        }
        $this->receivedPayloads[] = new ReceivedPayload($sessionId, $payload, $reliability);
        $this->receivedPayloadBytes += $bytes;
    }

    private function decodeOpenedSession(TransportProcessFrame $frame): SessionInfo
    {
        $networkId = $frame->metadata['network_id'] ?? null;
        $address = $frame->metadata['address'] ?? null;
        $port = $frame->metadata['port'] ?? null;
        $clientAddress = $frame->metadata['client_address'] ?? null;
        if (!is_string($networkId) || !is_string($address) || !is_int($port)
            || ($clientAddress !== null && !is_string($clientAddress))) {
            throw new RuntimeException('NetherNet opened-session metadata is invalid.');
        }

        return new SessionInfo($frame->sessionId, $networkId, $address, $port, $clientAddress);
    }

    private function queue(TransportProcessFrame $frame): void
    {
        if (!is_resource($this->stream)) {
            throw new RuntimeException('NetherNet process is unavailable.');
        }
        $encoded = $this->codec->encode($frame);
        if (strlen($encoded) > self::MAXIMUM_BUFFERED_BYTES - $this->outgoingBytes()) {
            throw new OverflowException('NetherNet process output queue limit reached.');
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
                throw new RuntimeException('NetherNet process IPC write failed.');
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
}
