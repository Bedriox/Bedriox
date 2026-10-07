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

namespace Bedriox\Server\Transport\Process;

use Bedriox\RakNet\ConnectedHandshakeDiagnosticBatch;
use Bedriox\RakNet\DiscoveryServer;
use Bedriox\RakNet\DiscoveryStatus;
use Bedriox\RakNet\Protocol\Reliability;
use Bedriox\RakNet\Security\TransportSecurityPolicy;
use Bedriox\RakNet\SessionClosedEvent;
use Bedriox\RakNet\SessionInfo;
use Bedriox\RakNet\SessionOpenedEvent;
use Bedriox\RakNet\TransportConfig;
use Bedriox\Server\Worker\Internal\IpcSocketTuning;
use JsonException;
use RuntimeException;
use Throwable;

/** Owns UDP and RakNet state outside the simulation process. */
final class TransportProcessProgram
{
    private const int READ_BYTES_PER_CYCLE = 4_194_304;
    private const int WRITE_BYTES_PER_CYCLE = 4_194_304;
    private const int MAXIMUM_OUTGOING_BYTES = 67_108_864;

    /** @var resource|null */
    private $stream = null;
    private readonly TransportProcessFrameCodec $codec;
    private readonly TransportProcessFrameDecoder $decoder;
    private string $outgoing = '';
    private int $outgoingOffset = 0;
    /** @var array<string, int> */
    private array $sessionIdsByEndpoint = [];
    /** @var array<int, SessionInfo> */
    private array $sessionsById = [];
    private int $nextSessionId = 1;
    private bool $stopping = false;
    private int $nextSecuritySnapshotNanoseconds = 0;

    private function __construct(
        private readonly string $parentEndpoint,
        private readonly string $parentToken,
        private readonly TransportConfig $config,
        private readonly int $serverGuid,
        private readonly DiscoveryStatus $status,
    ) {
        $this->codec = new TransportProcessFrameCodec();
        $this->decoder = new TransportProcessFrameDecoder($this->codec);
    }

    public static function run(string $parentEndpoint, string $parentTokenHex, string $configuration): int
    {
        $token = hex2bin($parentTokenHex);
        if ($parentEndpoint === '' || !is_string($token) || strlen($token) !== 32) {
            return 64;
        }
        try {
            [$config, $serverGuid, $status] = self::decodeConfiguration($configuration);
            return (new self($parentEndpoint, $token, $config, $serverGuid, $status))->execute();
        } catch (Throwable) {
            return 65;
        }
    }

    public static function encodeConfiguration(
        TransportConfig $config,
        int $serverGuid,
        DiscoveryStatus $status,
    ): string {
        try {
            $json = json_encode([
                'transport' => [
                    ...get_object_vars($config),
                    'security' => get_object_vars($config->security),
                ],
                'server_guid' => (string) $serverGuid,
                'status' => base64_encode($status->payload),
                'accepting' => $status->acceptingConnections,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Transport process configuration cannot be encoded.', 0, $exception);
        }

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    private function execute(): int
    {
        $stream = @stream_socket_client($this->parentEndpoint, $errorCode, $errorMessage, 5);
        if (!is_resource($stream)) {
            return 66;
        }
        $this->stream = $stream;
        IpcSocketTuning::apply($stream);
        stream_set_blocking($stream, true);
        if (!$this->writeBlocking($this->parentToken)) {
            fclose($stream);
            return 67;
        }
        try {
            $server = DiscoveryServer::bind($this->config, $this->serverGuid, $this->status);
        } catch (Throwable $exception) {
            $this->queue(new TransportProcessFrame(
                TransportProcessFrameKind::FAILURE,
                metadata: ['code' => 'bind_failed', 'exception' => $exception::class],
            ));
            $this->flushBlocking();
            fclose($stream);
            return 68;
        }
        $this->activeServer = $server;
        $this->queue(new TransportProcessFrame(TransportProcessFrameKind::READY, metadata: [
            'address' => $server->localAddress(),
            'port' => $server->localPort(),
            'pid' => getmypid(),
        ]));
        $this->flushBlocking();
        stream_set_blocking($stream, false);

        try {
            while (!$this->stopping) {
                $work = $this->readCommands();
                $handled = $server->poll(4_096);
                $work = $work || $handled > 0;
                foreach ($server->drainSessionEvents() as $event) {
                    $this->queueSessionEvent($event);
                    $work = true;
                }
                foreach ($server->drainReceivedPayloads() as $payload) {
                    $sessionId = $this->sessionIdsByEndpoint[self::endpointKey(
                        $payload->remoteAddress,
                        $payload->remotePort,
                    )] ?? null;
                    if ($sessionId === null) {
                        continue;
                    }
                    $this->queue(new TransportProcessFrame(
                        TransportProcessFrameKind::RECEIVED_PAYLOAD,
                        $sessionId,
                        $payload->payload,
                        $payload->reliability,
                        $payload->orderingChannel,
                    ));
                    $work = true;
                }
                $this->queueDiagnostics($server->drainHandshakeDiagnostics());
                $now = hrtime(true);
                if ($now >= $this->nextSecuritySnapshotNanoseconds) {
                    $snapshot = $server->securitySnapshot();
                    $this->queue(new TransportProcessFrame(
                        TransportProcessFrameKind::SECURITY_SNAPSHOT,
                        metadata: [
                            'receivedDatagrams' => $snapshot->receivedDatagrams,
                            'receivedBytes' => $snapshot->receivedBytes,
                            'droppedDatagrams' => $snapshot->droppedDatagrams,
                            'rateLimitedEndpoints' => $snapshot->rateLimitedEndpoints,
                            'malformedDatagrams' => $snapshot->malformedDatagrams,
                            'temporaryBlocks' => $snapshot->temporaryBlocks,
                            'activeBlocks' => $snapshot->activeBlocks,
                            'trackedAddresses' => $snapshot->trackedAddresses,
                            'trackedEndpoints' => $snapshot->trackedEndpoints,
                        ],
                    ));
                    $this->nextSecuritySnapshotNanoseconds = $now + 1_000_000_000;
                    $work = true;
                }
                $work = $this->flushOutgoing() || $work;
                if (!is_resource($this->stream) || feof($this->stream)) {
                    break;
                }
                if (!$work) {
                    usleep(250);
                }
            }
        } catch (Throwable $exception) {
            try {
                $this->queue(new TransportProcessFrame(
                    TransportProcessFrameKind::FAILURE,
                    metadata: ['code' => 'runtime_failed', 'exception' => $exception::class],
                ));
                $this->flushBlocking();
            } catch (Throwable) {
            }
            $server->close();
            $this->activeServer = null;
            $this->closeStream();
            return 69;
        }
        $server->close();
        $this->activeServer = null;
        $this->closeStream();

        return 0;
    }

    private function readCommands(): bool
    {
        if (!is_resource($this->stream)) {
            return false;
        }
        $bytes = @fread($this->stream, min(self::READ_BYTES_PER_CYCLE, 4_194_304));
        if ($bytes === false) {
            throw new RuntimeException('Transport process IPC read failed.');
        }
        if ($bytes !== '') {
            $this->decoder->append($bytes);
        }
        $frames = $this->decoder->drain(8_192);
        foreach ($frames as $frame) {
            if ($frame->kind === TransportProcessFrameKind::SHUTDOWN) {
                $this->stopping = true;
                continue;
            }
            $session = $this->sessionsById[$frame->sessionId] ?? null;
            if (!$session instanceof SessionInfo) {
                continue;
            }
            if ($frame->kind === TransportProcessFrameKind::REMOVE_SESSION) {
                $this->serverRemove($session);
                continue;
            }
            if ($frame->kind === TransportProcessFrameKind::SEND_PAYLOAD
                && $frame->reliability instanceof Reliability) {
                $this->serverSend($session, $frame);
            }
        }

        return $bytes !== '' || $frames !== [];
    }

    private ?DiscoveryServer $activeServer = null;

    private function serverRemove(SessionInfo $session): void
    {
        $this->activeServer?->removeSession($session->remoteAddress, $session->remotePort);
    }

    private function serverSend(SessionInfo $session, TransportProcessFrame $frame): void
    {
        $this->activeServer?->sendPayload(
            $session->remoteAddress,
            $session->remotePort,
            $frame->payload,
            $frame->reliability ?? throw new RuntimeException('Transport reliability is missing.'),
            $frame->orderingChannel ?? 0,
        );
    }

    private function queueSessionEvent(SessionOpenedEvent|SessionClosedEvent $event): void
    {
        $session = $event->session;
        $key = self::endpointKey($session->remoteAddress, $session->remotePort);
        if ($event instanceof SessionOpenedEvent) {
            $sessionId = $this->sessionIdsByEndpoint[$key] ?? $this->allocateSessionId();
            $this->sessionIdsByEndpoint[$key] = $sessionId;
            $this->sessionsById[$sessionId] = $session;
            $this->queue(new TransportProcessFrame(TransportProcessFrameKind::SESSION_OPENED, $sessionId, metadata: [
                'address' => $session->remoteAddress,
                'port' => $session->remotePort,
                'guid' => (string) $session->clientGuid,
                'mtu' => $session->mtu,
                'protocol' => $session->rakNetProtocolVersion,
            ]));
            return;
        }
        $sessionId = $this->sessionIdsByEndpoint[$key] ?? null;
        if ($sessionId === null) {
            return;
        }
        $this->queue(new TransportProcessFrame(TransportProcessFrameKind::SESSION_CLOSED, $sessionId, metadata: [
            'reason' => $event->reason->value,
            'transport_failure' => $event->transportFailure?->value,
            'transport_failure_detail' => $event->transportFailureDetail,
        ]));
        unset($this->sessionIdsByEndpoint[$key], $this->sessionsById[$sessionId]);
    }

    private function queueDiagnostics(ConnectedHandshakeDiagnosticBatch $batch): void
    {
        if ($batch->events === [] && $batch->droppedEventCount === 0) {
            return;
        }
        $events = [];
        foreach ($batch->events as $event) {
            $events[] = [
                'address' => $event->remoteAddress,
                'port' => $event->remotePort,
                'stage' => $event->stage->value,
                'reason' => $event->reason->value,
                'datagram_id' => $event->datagramId,
                'control_packet_id' => $event->controlPacketId,
                'payload_length' => $event->payloadLength,
                'reliability' => $event->reliability?->value,
                'ordering_channel' => $event->orderingChannel,
            ];
        }
        $this->queue(new TransportProcessFrame(TransportProcessFrameKind::HANDSHAKE_DIAGNOSTICS, metadata: [
            'events' => $events,
            'dropped' => $batch->droppedEventCount,
        ]));
    }

    private function queue(TransportProcessFrame $frame): void
    {
        $encoded = $this->codec->encode($frame);
        if (strlen($encoded) > self::MAXIMUM_OUTGOING_BYTES - $this->outgoingBytes()) {
            throw new RuntimeException('Transport process IPC output exceeded its buffer limit.');
        }
        $this->outgoing .= $encoded;
    }

    private function flushOutgoing(): bool
    {
        if ($this->outgoingBytes() === 0 || !is_resource($this->stream)) {
            return false;
        }
        $writtenTotal = 0;
        while ($this->outgoingBytes() > 0 && $writtenTotal < self::WRITE_BYTES_PER_CYCLE) {
            $attempt = min(262_144, self::WRITE_BYTES_PER_CYCLE - $writtenTotal, $this->outgoingBytes());
            $written = @fwrite($this->stream, substr($this->outgoing, $this->outgoingOffset, $attempt));
            if ($written === false) {
                throw new RuntimeException('Transport process IPC write failed.');
            }
            if ($written < 1) {
                break;
            }
            $this->outgoingOffset += $written;
            $writtenTotal += $written;
        }
        $this->compactOutgoing();

        return $writtenTotal > 0;
    }

    private function flushBlocking(): void
    {
        $stream = $this->stream;
        if (!is_resource($stream)) {
            return;
        }
        stream_set_blocking($stream, true);
        while ($this->outgoingBytes() > 0) {
            $written = @fwrite($stream, substr($this->outgoing, $this->outgoingOffset));
            if (!is_int($written) || $written < 1) {
                throw new RuntimeException('Transport process blocking IPC write failed.');
            }
            $this->outgoingOffset += $written;
        }
        $this->compactOutgoing();
        @fflush($stream);
    }

    private function writeBlocking(string $bytes): bool
    {
        while ($bytes !== '' && is_resource($this->stream)) {
            $written = @fwrite($this->stream, $bytes);
            if (!is_int($written) || $written < 1) {
                return false;
            }
            $bytes = (string) substr($bytes, $written);
        }

        return $bytes === '';
    }

    private function compactOutgoing(): void
    {
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

    private function allocateSessionId(): int
    {
        for ($attempt = 0; $attempt < 0xffff_ffff; ++$attempt) {
            $candidate = $this->nextSessionId++;
            if ($this->nextSessionId > 0xffff_ffff) {
                $this->nextSessionId = 1;
            }
            if (!isset($this->sessionsById[$candidate])) {
                return $candidate;
            }
        }
        throw new RuntimeException('Transport process session identifier space is exhausted.');
    }

    private function closeStream(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
    }

    private static function endpointKey(string $address, int $port): string
    {
        return $address . ':' . $port;
    }

    /** @return array{TransportConfig, int, DiscoveryStatus} */
    private static function decodeConfiguration(string $encoded): array
    {
        $encoded = strtr($encoded, '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $json = base64_decode($encoded, true);
        if (!is_string($json)) {
            throw new RuntimeException('Transport process configuration is not valid base64.');
        }
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Transport process configuration is not valid JSON.', 0, $exception);
        }
        if (!is_array($data) || !is_array($data['transport'] ?? null)
            || !is_string($data['server_guid'] ?? null) || !is_string($data['status'] ?? null)
            || !is_bool($data['accepting'] ?? null)) {
            throw new RuntimeException('Transport process configuration shape is invalid.');
        }
        $transport = $data['transport'];
        $bindAddress = $transport['bindAddress'] ?? null;
        if (!is_string($bindAddress)) {
            throw new RuntimeException('Transport process bind address is invalid.');
        }
        $integer = static function (string $key) use ($transport): int {
            $value = $transport[$key] ?? null;
            if (!is_int($value)) {
                throw new RuntimeException('Transport process integer setting is invalid.');
            }

            return $value;
        };
        $config = new TransportConfig(
            bindAddress: $bindAddress,
            port: $integer('port'),
            maximumTransmissionUnit: $integer('maximumTransmissionUnit'),
            maximumSessions: $integer('maximumSessions'),
            maximumPendingHandshakes: $integer('maximumPendingHandshakes'),
            handshakeTimeoutMilliseconds: $integer('handshakeTimeoutMilliseconds'),
            sessionIdleTimeoutMilliseconds: $integer('sessionIdleTimeoutMilliseconds'),
            sessionPingIntervalMilliseconds: $integer('sessionPingIntervalMilliseconds'),
            connectedSessionMaintenanceIntervalMilliseconds: $integer('connectedSessionMaintenanceIntervalMilliseconds'),
            maximumReceivedPayloads: $integer('maximumReceivedPayloads'),
            maximumReceivedPayloadBytes: $integer('maximumReceivedPayloadBytes'),
            maximumPendingOutboundDatagrams: $integer('maximumPendingOutboundDatagrams'),
            maximumPendingOutboundBytes: $integer('maximumPendingOutboundBytes'),
            maximumSessionEvents: $integer('maximumSessionEvents'),
            maximumHandshakeDiagnosticEvents: $integer('maximumHandshakeDiagnosticEvents'),
            socketReceiveBufferBytes: $integer('socketReceiveBufferBytes'),
            socketSendBufferBytes: $integer('socketSendBufferBytes'),
            security: self::decodeSecurityPolicy($transport['security'] ?? null),
        );
        $statusPayload = base64_decode($data['status'], true);
        if (!is_string($statusPayload)) {
            throw new RuntimeException('Transport status payload is invalid.');
        }

        $serverGuid = filter_var($data['server_guid'], FILTER_VALIDATE_INT);
        if (!is_int($serverGuid)) {
            throw new RuntimeException('Transport process server GUID is invalid.');
        }

        return [$config, $serverGuid, new DiscoveryStatus($statusPayload, $data['accepting'])];
    }

    private static function decodeSecurityPolicy(mixed $value): TransportSecurityPolicy
    {
        if (!is_array($value)) {
            throw new RuntimeException('Transport process security policy is invalid.');
        }
        $boolean = static function (string $key) use ($value): bool {
            $setting = $value[$key] ?? null;
            if (!is_bool($setting)) {
                throw new RuntimeException('Transport process security boolean is invalid.');
            }

            return $setting;
        };
        $integer = static function (string $key) use ($value): int {
            $setting = $value[$key] ?? null;
            if (!is_int($setting)) {
                throw new RuntimeException('Transport process security integer is invalid.');
            }

            return $setting;
        };

        return new TransportSecurityPolicy(
            enabled: $boolean('enabled'),
            automaticBlocking: $boolean('automaticBlocking'),
            globalDatagramsPerSecond: $integer('globalDatagramsPerSecond'),
            globalDatagramBurst: $integer('globalDatagramBurst'),
            globalBytesPerSecond: $integer('globalBytesPerSecond'),
            globalByteBurst: $integer('globalByteBurst'),
            unauthenticatedDatagramsPerSecond: $integer('unauthenticatedDatagramsPerSecond'),
            unauthenticatedDatagramBurst: $integer('unauthenticatedDatagramBurst'),
            unauthenticatedBytesPerSecond: $integer('unauthenticatedBytesPerSecond'),
            unauthenticatedByteBurst: $integer('unauthenticatedByteBurst'),
            connectedDatagramsPerSecond: $integer('connectedDatagramsPerSecond'),
            connectedDatagramBurst: $integer('connectedDatagramBurst'),
            connectedBytesPerSecond: $integer('connectedBytesPerSecond'),
            connectedByteBurst: $integer('connectedByteBurst'),
            handshakesPerSecond: $integer('handshakesPerSecond'),
            handshakeBurst: $integer('handshakeBurst'),
            malformedThreshold: $integer('malformedThreshold'),
            baseBlockSeconds: $integer('baseBlockSeconds'),
            maximumBlockSeconds: $integer('maximumBlockSeconds'),
            escalationWindowSeconds: $integer('escalationWindowSeconds'),
            maximumTrackedAddresses: $integer('maximumTrackedAddresses'),
            maximumTrackedEndpoints: $integer('maximumTrackedEndpoints'),
        );
    }
}
