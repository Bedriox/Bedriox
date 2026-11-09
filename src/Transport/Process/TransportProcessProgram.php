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

use Bedriox\NetherNet\Identity\PeerIdentity;
use Bedriox\NetherNet\Identity\SelfSignedIdentityProvider;
use Bedriox\NetherNet\Identity\ServerIdentity;
use Bedriox\NetherNet\Info\GameType;
use Bedriox\NetherNet\Info\MutableServerInfoProvider;
use Bedriox\NetherNet\Info\ServerInfo;
use Bedriox\NetherNet\Logging\NullLogger;
use Bedriox\NetherNet\Protocol\DisconnectReason;
use Bedriox\NetherNet\Protocol\Reliability;
use Bedriox\NetherNet\Server\NetherNetServer;
use Bedriox\NetherNet\Server\ServerConfiguration;
use Bedriox\NetherNet\Server\ServerEventListener;
use Bedriox\NetherNet\Server\ServerInterface;
use Bedriox\NetherNet\Signaling\Http\HttpSignaling;
use Bedriox\NetherNet\Signaling\Http\ReverseProxy;
use Bedriox\NetherNet\Signaling\Lan\LanSignaling;
use Bedriox\NetherNet\Transport\ConnectionBudgetConfiguration;
use Bedriox\NetherNet\Transport\WebRtc\SctpConfiguration;
use Bedriox\NetherNet\Transport\WebRtc\WebRtcTransportFactory;
use Bedriox\NetherNet\Utils\InternetAddress;
use Bedriox\Server\Transport\NetherNetTransportConfig;
use Bedriox\Server\Worker\Internal\IpcSocketTuning;
use JsonException;
use RuntimeException;
use Throwable;

/** Owns signaling, ICE, SCTP and NetherNet sessions outside the simulation process. */
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
    private bool $stopping = false;
    private ?NetherNetServer $activeServer = null;
    private ?MutableServerInfoProvider $serverInfo = null;
    private float $nextSecuritySnapshotAt = 0.0;

    private function __construct(
        private readonly string $parentEndpoint,
        private readonly string $parentToken,
        private readonly NetherNetTransportConfig $config,
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
            return (new self($parentEndpoint, $token, self::decodeConfiguration($configuration)))->execute();
        } catch (Throwable) {
            return 65;
        }
    }

    public static function encodeConfiguration(NetherNetTransportConfig $config): string
    {
        try {
            $json = json_encode(get_object_vars($config), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
            if (!extension_loaded('webrtc')) {
                throw new RuntimeException('The NetherNet transport requires ext-webrtc.');
            }
            $server = $this->createServer();
            $server->start();
        } catch (Throwable $exception) {
            $this->queue(new TransportProcessFrame(
                TransportProcessFrameKind::FAILURE,
                metadata: [
                    'code' => extension_loaded('webrtc') ? 'bind_failed' : 'webrtc_extension_unavailable',
                    'exception' => $exception::class,
                ],
            ));
            $this->flushBlocking();
            fclose($stream);
            return 68;
        }
        $this->activeServer = $server;
        $this->queue(new TransportProcessFrame(TransportProcessFrameKind::READY, metadata: [
            'address' => $this->config->bindAddress,
            'port' => $this->config->port,
            'lan_port' => $this->config->lanPort,
            'pid' => getmypid(),
        ]));
        $this->flushBlocking();
        stream_set_blocking($stream, false);

        try {
            while (!$this->stopping) {
                $work = $this->readCommands();
                $server->tick();
                $this->publishSecuritySnapshot($server);
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
            $server->shutdown();
            $this->activeServer = null;
            $this->closeStream();
            return 69;
        }

        $server->shutdown();
        $this->activeServer = null;
        $this->closeStream();

        return 0;
    }

    private function publishSecuritySnapshot(NetherNetServer $server): void
    {
        $now = microtime(true);
        if ($now < $this->nextSecuritySnapshotAt) {
            return;
        }
        $this->nextSecuritySnapshotAt = $now + 1.0;
        $snapshot = $server->getSecuritySnapshot();
        $this->queue(new TransportProcessFrame(TransportProcessFrameKind::SECURITY_SNAPSHOT, metadata: [
            'active_sessions' => $snapshot->activeSessions,
            'maximum_sessions' => $snapshot->maximumSessions,
            'active_negotiations' => $snapshot->activeNegotiations,
            'active_address_blocks' => $snapshot->activeAddressBlocks,
            'negotiation_flood_blocks' => $snapshot->negotiationFloodBlocks,
            'session_limit_rejections' => $snapshot->sessionLimitRejections,
            'blocked_peer_rejections' => $snapshot->blockedPeerRejections,
        ]));
    }

    private function createServer(): NetherNetServer
    {
        $identity = self::loadOrCreateIdentity($this->config->identityPath);
        (new SctpConfiguration(heartbeatInterval: 2_000, maxRetransmitAttempts: 5))->applyGlobally();

        $listener = new class ($this) implements ServerEventListener {
            public ?ServerInterface $server = null;

            public function __construct(private readonly TransportProcessProgram $program) {}

            public function onSessionOpen(
                int $sessionId,
                string $networkId,
                InternetAddress $address,
                ?string $clientAddress,
                ?PeerIdentity $identity,
            ): void {
                $this->program->sessionOpened($sessionId, $networkId, $address, $clientAddress);
            }

            public function onSessionClose(int $sessionId, DisconnectReason $reason): void
            {
                $this->program->sessionClosed($sessionId, $reason);
            }

            public function canAcceptPackets(): bool
            {
                return $this->program->canAcceptPackets();
            }

            public function onPacketReceive(int $sessionId, string $payload, Reliability $reliability): void
            {
                $this->program->packetReceived($sessionId, $payload, $reliability);
            }

            public function onPacketAck(int $sessionId, int $receiptId): void
            {
                $this->program->payloadAcknowledged($sessionId, $receiptId);
            }

            public function onBandwidthStatsUpdate(int $bytesSentDiff, int $bytesReceivedDiff): void
            {
                $this->program->bandwidthUpdated($bytesSentDiff, $bytesReceivedDiff);
            }

            public function onPingMeasure(int $sessionId, int $pingMs): void
            {
                $this->program->pingMeasured($sessionId, $pingMs);
            }
        };

        $budget = new ConnectionBudgetConfiguration(
            maxReceiveQueueSize: min($this->config->maximumReceivedPayloadBytes, ConnectionBudgetConfiguration::DEFAULT_MAX_RECEIVE_QUEUE_SIZE),
            maxReceiveQueueMessages: min($this->config->maximumReceivedPayloads, ConnectionBudgetConfiguration::DEFAULT_MAX_RECEIVE_QUEUE_MESSAGES),
        );
        $server = NetherNetServer::create(new ServerConfiguration(
            identityProvider: new SelfSignedIdentityProvider($identity),
            logger: new NullLogger(),
            transportFactory: new WebRtcTransportFactory(
                bindAddress: $this->config->bindAddress,
                portRangeBegin: $this->config->port,
                portRangeEnd: $this->config->port,
                iceUdpMuxEnabled: true,
            ),
            budget: $budget,
            gatheringTimeout: $this->config->gatheringTimeoutSeconds,
            channelTimeout: $this->config->channelTimeoutSeconds,
            maxRemoteCandidates: $this->config->maximumRemoteCandidates,
            maxNegotiations: $this->config->maximumNegotiations,
            maxNegotiationsPerAddress: $this->config->maximumNegotiationsPerAddress,
            maxSessions: $this->config->maximumSessions,
            advertisedAddresses: $this->config->advertisedAddresses === [] ? null : $this->config->advertisedAddresses,
        ), $listener);
        $listener->server = $server;

        $info = new MutableServerInfoProvider(new ServerInfo(
            serverName: $this->config->serverName,
            protocol: $this->config->protocol,
            gameVersion: $this->config->gameVersion,
            levelName: $this->config->levelName,
            gameType: GameType::SURVIVAL,
            maxPlayerCount: $this->config->maximumPlayers,
            acceptsOnlineAuth: $this->config->acceptsOnlineAuthentication,
            acceptsSelfSignedAuth: $this->config->acceptsSelfSignedAuthentication,
        ));
        $this->serverInfo = $info;
        $proxy = $this->config->trustedProxyCidrs === []
            ? null
            : new ReverseProxy(ReverseProxy::HEADER_FORWARDED_FOR, $this->config->trustedProxyCidrs);
        $server->addSignaling(new HttpSignaling(
            new InternetAddress($this->config->bindAddress, $this->config->port, 4),
            infoProvider: $info,
            reverseProxy: $proxy,
            maxConnections: $this->config->maximumSignalingConnections,
        ));
        if ($this->config->lanPort !== 0) {
            $server->addSignaling(new LanSignaling(
                $info,
                $this->config->networkId,
                new InternetAddress($this->config->bindAddress, $this->config->lanPort, 4),
                maxConnections: $this->config->maximumSignalingConnections,
            ), optional: true);
        }

        return $server;
    }

    public function sessionOpened(int $sessionId, string $networkId, InternetAddress $address, ?string $clientAddress): void
    {
        $this->queue(new TransportProcessFrame(TransportProcessFrameKind::SESSION_OPENED, $sessionId, metadata: [
            'network_id' => $networkId,
            'address' => $address->ip,
            'port' => $address->port,
            'client_address' => $clientAddress,
        ]));
    }

    public function sessionClosed(int $sessionId, DisconnectReason $reason): void
    {
        $this->queue(new TransportProcessFrame(TransportProcessFrameKind::SESSION_CLOSED, $sessionId, metadata: [
            'reason' => $reason->value,
        ]));
    }

    public function canAcceptPackets(): bool
    {
        return $this->outgoingBytes() < self::MAXIMUM_OUTGOING_BYTES / 2;
    }

    public function packetReceived(int $sessionId, string $payload, Reliability $reliability): void
    {
        $this->queue(new TransportProcessFrame(
            TransportProcessFrameKind::RECEIVED_PAYLOAD,
            $sessionId,
            $payload,
            $reliability,
        ));
    }

    public function bandwidthUpdated(int $bytesSent, int $bytesReceived): void
    {
        $this->queue(new TransportProcessFrame(TransportProcessFrameKind::BANDWIDTH_STATS, metadata: [
            'sent' => max(0, $bytesSent),
            'received' => max(0, $bytesReceived),
        ]));
    }

    public function pingMeasured(int $sessionId, int $pingMilliseconds): void
    {
        $this->queue(new TransportProcessFrame(TransportProcessFrameKind::PING_MEASURED, $sessionId, metadata: [
            'milliseconds' => max(0, $pingMilliseconds),
        ]));
    }

    public function payloadAcknowledged(int $sessionId, int $receiptId): void
    {
        if ($receiptId < 1) {
            return;
        }
        $this->queue(new TransportProcessFrame(
            TransportProcessFrameKind::PAYLOAD_ACKNOWLEDGED,
            $sessionId,
            metadata: ['receipt_id' => $receiptId],
        ));
    }

    private function readCommands(): bool
    {
        if (!is_resource($this->stream)) {
            return false;
        }
        $bytes = @fread($this->stream, self::READ_BYTES_PER_CYCLE);
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
            if ($frame->kind === TransportProcessFrameKind::REMOVE_SESSION) {
                $this->activeServer?->closeSession($frame->sessionId);
                continue;
            }
            if ($frame->kind === TransportProcessFrameKind::SEND_PAYLOAD && $frame->reliability !== null) {
                $this->activeServer?->sendPacket(
                    $frame->sessionId,
                    $frame->payload,
                    $frame->reliability,
                    $frame->receiptId,
                );
                continue;
            }
            if ($frame->kind === TransportProcessFrameKind::UPDATE_PLAYER_COUNT) {
                $onlinePlayers = $frame->metadata['online_players'] ?? null;
                $current = $this->serverInfo?->getServerInfo();
                if (!is_int($onlinePlayers) || $onlinePlayers < 0 || $current === null) {
                    throw new RuntimeException('Transport player-count update is invalid.');
                }
                $this->serverInfo->setServerInfo(new ServerInfo(
                    $current->serverName,
                    $current->protocol,
                    $current->gameVersion,
                    $current->levelName,
                    $current->gameType,
                    min($onlinePlayers, $current->maxPlayerCount),
                    $current->maxPlayerCount,
                    $current->editorWorld,
                    $current->hardcore,
                    $current->acceptsOnlineAuth,
                    $current->acceptsSelfSignedAuth,
                    $current->nonce,
                ));
                continue;
            }
            if ($frame->kind === TransportProcessFrameKind::UPDATE_ADVERTISEMENT) {
                $serverName = $frame->metadata['server_name'] ?? null;
                $onlinePlayers = $frame->metadata['online_players'] ?? null;
                $maximumPlayers = $frame->metadata['maximum_players'] ?? null;
                $current = $this->serverInfo?->getServerInfo();
                if (!is_string($serverName) || $serverName === '' || strlen($serverName) > 128
                    || preg_match('//u', $serverName) !== 1 || !is_int($onlinePlayers)
                    || !is_int($maximumPlayers) || $onlinePlayers < 0 || $maximumPlayers < 1
                    || $onlinePlayers > $maximumPlayers || $current === null) {
                    throw new RuntimeException('Transport advertisement update is invalid.');
                }
                $this->serverInfo->setServerInfo(new ServerInfo(
                    $serverName,
                    $current->protocol,
                    $current->gameVersion,
                    $current->levelName,
                    $current->gameType,
                    $onlinePlayers,
                    $maximumPlayers,
                    $current->editorWorld,
                    $current->hardcore,
                    $current->acceptsOnlineAuth,
                    $current->acceptsSelfSignedAuth,
                    $current->nonce,
                ));
            }
        }

        return $bytes !== '' || $frames !== [];
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

    private function closeStream(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
    }

    private static function loadOrCreateIdentity(string $path): ServerIdentity
    {
        $pem = @file_get_contents($path);
        if (is_string($pem) && $pem !== '') {
            return ServerIdentity::fromPrivateKeyPem($pem);
        }
        $identity = ServerIdentity::generate();
        $pem = $identity->exportPrivateKeyPem();
        $handle = @fopen($path, 'x+b');
        if (!is_resource($handle)) {
            $existing = @file_get_contents($path);
            if (!is_string($existing) || $existing === '') {
                throw new RuntimeException('NetherNet server identity could not be created.');
            }

            return ServerIdentity::fromPrivateKeyPem($existing);
        }
        try {
            if (fwrite($handle, $pem) !== strlen($pem) || !fflush($handle)) {
                throw new RuntimeException('NetherNet server identity could not be written.');
            }
        } finally {
            fclose($handle);
        }
        @chmod($path, 0600);

        return $identity;
    }

    private static function decodeConfiguration(string $encoded): NetherNetTransportConfig
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
        if (!is_array($data)) {
            throw new RuntimeException('Transport process configuration shape is invalid.');
        }

        return new NetherNetTransportConfig(
            bindAddress: self::stringSetting($data, 'bindAddress'),
            port: self::integerSetting($data, 'port'),
            lanPort: self::integerSetting($data, 'lanPort'),
            networkId: self::integerSetting($data, 'networkId'),
            identityPath: self::stringSetting($data, 'identityPath'),
            serverName: self::stringSetting($data, 'serverName'),
            protocol: self::integerSetting($data, 'protocol'),
            gameVersion: self::stringSetting($data, 'gameVersion'),
            levelName: self::stringSetting($data, 'levelName'),
            maximumPlayers: self::integerSetting($data, 'maximumPlayers'),
            acceptsOnlineAuthentication: self::booleanSetting($data, 'acceptsOnlineAuthentication'),
            acceptsSelfSignedAuthentication: self::booleanSetting($data, 'acceptsSelfSignedAuthentication'),
            maximumSessions: self::integerSetting($data, 'maximumSessions'),
            maximumSessionEvents: self::integerSetting($data, 'maximumSessionEvents'),
            maximumReceivedPayloads: self::integerSetting($data, 'maximumReceivedPayloads'),
            maximumReceivedPayloadBytes: self::integerSetting($data, 'maximumReceivedPayloadBytes'),
            maximumSignalingConnections: self::integerSetting($data, 'maximumSignalingConnections'),
            maximumNegotiations: self::integerSetting($data, 'maximumNegotiations'),
            maximumNegotiationsPerAddress: self::integerSetting($data, 'maximumNegotiationsPerAddress'),
            maximumRemoteCandidates: self::integerSetting($data, 'maximumRemoteCandidates'),
            gatheringTimeoutSeconds: self::floatSetting($data, 'gatheringTimeoutSeconds'),
            channelTimeoutSeconds: self::floatSetting($data, 'channelTimeoutSeconds'),
            advertisedAddresses: self::stringListSetting($data, 'advertisedAddresses'),
            trustedProxyCidrs: self::stringListSetting($data, 'trustedProxyCidrs'),
        );
    }

    /** @param array<array-key, mixed> $data */
    private static function stringSetting(array $data, string $name): string
    {
        $value = $data[$name] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException('Transport process string setting is invalid.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    private static function integerSetting(array $data, string $name): int
    {
        $value = $data[$name] ?? null;
        if (!is_int($value)) {
            throw new RuntimeException('Transport process integer setting is invalid.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    private static function booleanSetting(array $data, string $name): bool
    {
        $value = $data[$name] ?? null;
        if (!is_bool($value)) {
            throw new RuntimeException('Transport process boolean setting is invalid.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    private static function floatSetting(array $data, string $name): float
    {
        $value = $data[$name] ?? null;
        if (!is_float($value) && !is_int($value)) {
            throw new RuntimeException('Transport process floating-point setting is invalid.');
        }

        return (float) $value;
    }

    /**
     * @param  array<array-key, mixed> $data
     * @return list<string>
     */
    private static function stringListSetting(array $data, string $name): array
    {
        $value = $data[$name] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException('Transport process list setting is invalid.');
        }
        $result = [];
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                throw new RuntimeException('Transport process list entry is invalid.');
            }
            $result[] = $entry;
        }

        return $result;
    }
}
