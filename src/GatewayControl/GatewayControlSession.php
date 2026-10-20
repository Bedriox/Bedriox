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

namespace Bedriox\Server\GatewayControl;

use Bedriox\Protocol\GatewayControl\ControlFrame;
use Bedriox\Protocol\GatewayControl\ControlFrameCipher;
use Bedriox\Protocol\GatewayControl\ControlFrameCodec;
use Bedriox\Protocol\GatewayControl\ControlHandshakeClaims;
use Bedriox\Protocol\GatewayControl\ControlHandshakeStage;
use Bedriox\Protocol\GatewayControl\ControlHandshakeToken;
use Bedriox\Protocol\GatewayControl\ControlMessageType;
use Closure;
use RuntimeException;
use Throwable;

/** One fail-closed, mutually authenticated Gateway control link. */
final class GatewayControlSession
{
    private const int MAXIMUM_JSON_BYTES = 32_768;
    private const int MAXIMUM_PLUGIN_PAYLOAD_BYTES = 24_000;
    private const int MAXIMUM_PENDING_PLUGIN_MESSAGES = 256;
    private const int CLOCK_SKEW_SECONDS = 5;
    private ?string $gatewayNonce = null;
    private ?string $serverNonce = null;
    private ?int $helloAcceptedAt = null;
    private ?ControlFrameCipher $inboundCipher = null;
    private ?ControlFrameCipher $outboundCipher = null;
    private int $inboundSequence = 0;
    private int $outboundSequence = 0;
    private bool $ready = false;
    private bool $closed = false;
    /** @var array<string, string> request ID => canonical channel */
    private array $pendingPluginMessages = [];

    public function __construct(
        private readonly GatewayControlConfig $config,
        private readonly GatewayControlNonceGuard $nonces,
        private readonly ?Closure $pluginMessageHandler = null,
        private readonly ?Closure $pluginMessageRejectionHandler = null,
    ) {}

    public function ready(): bool
    {
        return $this->ready && !$this->closed;
    }

    /** @return list<string> */
    public function accept(string $bytes, int $now, int $onlinePlayers, int $maximumPlayers, bool &$draining): array
    {
        if ($this->closed) {
            throw new RuntimeException('Gateway control session is closed.');
        }
        try {
            $frame = ControlFrameCodec::decode($bytes);
            if ($this->gatewayNonce === null) {
                return [$this->acceptHello($frame, $now)];
            }
            if ($this->inboundCipher === null) {
                return [$this->acceptAuthentication($frame, $now)];
            }
            if (!$frame->encrypted() || $frame->sequence !== $this->inboundSequence) {
                throw new RuntimeException('Gateway control frame is unauthenticated, replayed, or out of sequence.');
            }
            $opened = $this->inboundCipher->open($frame);
            ++$this->inboundSequence;

            return $this->acceptReadyMessage($opened, $onlinePlayers, $maximumPlayers, $draining);
        } catch (Throwable $exception) {
            $this->closed = true;
            throw new RuntimeException('Gateway control message was rejected.', previous: $exception);
        }
    }

    public function serverStopping(): ?string
    {
        return $this->ready() ? $this->encrypted(ControlMessageType::ServerStopping, self::json([
            'status' => 'stopping',
            'reason' => 'server is stopping',
        ])) : null;
    }

    public function serverStatus(string $status, string $reason): ?string
    {
        if (!$this->ready() || !in_array($status, ['healthy', 'degraded', 'maintenance', 'draining', 'unhealthy'], true)
            || strlen($reason) > 256 || preg_match('//u', $reason) !== 1) {
            return null;
        }

        return $this->encrypted(ControlMessageType::ServerStatus, self::json([
            'status' => $status,
            'reason' => $reason,
        ]));
    }

    public function pluginMessage(string $channel, string $payload): ?string
    {
        if (!$this->ready() || !self::validChannel($channel) || strlen($payload) > self::MAXIMUM_PLUGIN_PAYLOAD_BYTES) {
            return null;
        }
        if (count($this->pendingPluginMessages) >= self::MAXIMUM_PENDING_PLUGIN_MESSAGES) {
            array_shift($this->pendingPluginMessages);
        }
        $requestId = ControlFrame::requestId();
        $frame = $this->encrypted(ControlMessageType::PluginMessage, self::json([
            'channel' => $channel,
            'payload' => base64_encode($payload),
        ]), $requestId);
        $this->pendingPluginMessages[$requestId] = $channel;

        return $frame;
    }

    private function acceptHello(ControlFrame $frame, int $now): string
    {
        if ($frame->type !== ControlMessageType::Hello || $frame->encrypted() || $frame->sequence !== 0) {
            throw new RuntimeException('Expected a plaintext Gateway control Hello.');
        }
        $claims = ControlHandshakeToken::verify($frame->payload, $this->config->secret);
        $this->validateClaims($claims, ControlHandshakeStage::Hello, $now);
        if (!$this->nonces->accept($claims->gatewayNonce, $claims->expiresAt, $now)) {
            throw new RuntimeException('Gateway control nonce was replayed or replay storage is full.');
        }
        $this->gatewayNonce = $claims->gatewayNonce;
        $this->helloAcceptedAt = $now;
        $this->serverNonce = ControlHandshakeClaims::nonce();
        $challenge = new ControlHandshakeClaims(
            ControlHandshakeStage::Challenge,
            $this->config->issuer,
            $this->config->audience,
            $this->config->keyId,
            $this->gatewayNonce,
            $this->serverNonce,
            $now,
            $now + $this->config->maximumHandshakeAgeSeconds,
        );

        return self::plain(ControlMessageType::Challenge, ControlHandshakeToken::issue($challenge, $this->config->secret));
    }

    private function acceptAuthentication(ControlFrame $frame, int $now): string
    {
        if ($frame->type !== ControlMessageType::Authenticate || $frame->encrypted() || $frame->sequence !== 0) {
            throw new RuntimeException('Expected a plaintext Gateway control Authenticate.');
        }
        if ($this->helloAcceptedAt === null || $now - $this->helloAcceptedAt > $this->config->maximumHandshakeAgeSeconds) {
            throw new RuntimeException('Gateway control authentication timed out.');
        }
        $claims = ControlHandshakeToken::verify($frame->payload, $this->config->secret);
        $this->validateClaims($claims, ControlHandshakeStage::Authenticate, $now);
        if (!hash_equals((string) $this->gatewayNonce, $claims->gatewayNonce)
            || !hash_equals((string) $this->serverNonce, (string) $claims->serverNonce)) {
            throw new RuntimeException('Gateway control authentication nonces do not match.');
        }
        $key = ControlHandshakeToken::deriveSessionKey($this->config->secret, $claims);
        $this->inboundCipher = new ControlFrameCipher($key, 'gateway-to-server');
        $this->outboundCipher = new ControlFrameCipher($key, 'server-to-gateway');
        $this->ready = true;

        return $this->encrypted(ControlMessageType::Ready, self::json(['status' => 'ready']));
    }

    /** @return list<string> */
    private function acceptReadyMessage(ControlFrame $frame, int $onlinePlayers, int $maximumPlayers, bool &$draining): array
    {
        if ($frame->type === ControlMessageType::HealthRequest) {
            return [$this->encrypted(ControlMessageType::HealthResponse, self::json([
                'status' => $draining ? 'draining' : 'healthy',
                'players' => $onlinePlayers,
                'max_players' => $maximumPlayers,
            ]), $frame->requestId)];
        }
        if ($frame->type === ControlMessageType::DrainRequest) {
            $draining = true;
            return [$this->encrypted(ControlMessageType::DrainResponse, self::json([
                'status' => 'draining',
                'reason' => 'drain requested by Gateway',
            ]), $frame->requestId)];
        }
        if ($frame->type === ControlMessageType::PluginMessage) {
            $value = self::object($frame->payload);
            $channel = $value['channel'] ?? null;
            $encoded = $value['payload'] ?? null;
            $payload = is_string($encoded) ? base64_decode($encoded, true) : false;
            if (!is_string($channel) || !self::validChannel($channel)
                || !is_string($payload) || strlen($payload) > self::MAXIMUM_PLUGIN_PAYLOAD_BYTES) {
                $rejection = ['reason' => 'invalid_plugin_message'];
                if (is_string($channel) && self::validChannel($channel)) {
                    $rejection['channel'] = $channel;
                }
                return [$this->encrypted(ControlMessageType::PluginMessageRejected, self::json($rejection), $frame->requestId)];
            }
            if ($this->pluginMessageHandler === null || ($this->pluginMessageHandler)($channel, $payload) !== true) {
                return [$this->encrypted(ControlMessageType::PluginMessageRejected, self::json([
                    'reason' => 'unhandled_plugin_channel',
                    'channel' => $channel,
                ]), $frame->requestId)];
            }
            return [];
        }
        if ($frame->type === ControlMessageType::PluginMessageRejected) {
            $channel = $this->pendingPluginMessages[$frame->requestId] ?? null;
            unset($this->pendingPluginMessages[$frame->requestId]);
            $value = self::object($frame->payload);
            $reason = $value['reason'] ?? null;
            if (!is_string($reason) || preg_match('/\A[a-z0-9_.-]{1,128}\z/D', $reason) !== 1) {
                throw new RuntimeException('Gateway plugin rejection is invalid.');
            }
            if (!is_string($channel)) {
                return [];
            }
            if ($this->pluginMessageRejectionHandler !== null) {
                ($this->pluginMessageRejectionHandler)($channel, $reason);
            }
            return [];
        }
        throw new RuntimeException('Gateway control message type is not valid in the ready state.');
    }

    private function validateClaims(ControlHandshakeClaims $claims, ControlHandshakeStage $stage, int $now): void
    {
        if ($claims->stage !== $stage
            || !hash_equals($this->config->issuer, $claims->issuer)
            || !hash_equals($this->config->audience, $claims->audience)
            || !hash_equals($this->config->keyId, $claims->keyId)
            || $claims->issuedAt > $now + self::CLOCK_SKEW_SECONDS
            || $claims->expiresAt < $now
            || $now - $claims->issuedAt > $this->config->maximumHandshakeAgeSeconds) {
            throw new RuntimeException('Gateway control handshake claims do not match this backend.');
        }
    }

    private static function plain(ControlMessageType $type, string $payload): string
    {
        return ControlFrameCodec::encode(new ControlFrame($type, ControlFrame::requestId(), 0, $payload));
    }

    private function encrypted(ControlMessageType $type, string $payload, ?string $requestId = null): string
    {
        if ($this->outboundCipher === null || $this->outboundSequence > 0xffffffff) {
            throw new RuntimeException('Gateway control outbound cipher or sequence is unavailable.');
        }
        $frame = new ControlFrame($type, $requestId ?? ControlFrame::requestId(), $this->outboundSequence++, $payload);

        return ControlFrameCodec::encode($this->outboundCipher->seal($frame));
    }

    /** @param array<string, int|string> $value */
    private static function json(array $value): string
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > self::MAXIMUM_JSON_BYTES) {
            throw new RuntimeException('Gateway control JSON payload exceeds its limit.');
        }
        return $json;
    }

    /** @return array<string, mixed> */
    private static function object(string $json): array
    {
        if (strlen($json) > self::MAXIMUM_JSON_BYTES) {
            throw new RuntimeException('Gateway control JSON payload exceeds its limit.');
        }
        $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException('Gateway control JSON payload must be an object.');
        }
        $object = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new RuntimeException('Gateway control JSON object keys must be strings.');
            }
            $object[$key] = $item;
        }
        return $object;
    }

    private static function validChannel(string $channel): bool
    {
        return strlen($channel) <= 128
            && preg_match('/\A[a-z0-9][a-z0-9_.-]*:[a-z0-9][a-z0-9_.-]*\z/D', $channel) === 1;
    }
}
