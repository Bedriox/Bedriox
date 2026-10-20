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

namespace Bedriox\Server\Tests\GatewayControl;

use Bedriox\Protocol\GatewayControl\ControlFrame;
use Bedriox\Protocol\GatewayControl\ControlFrameCipher;
use Bedriox\Protocol\GatewayControl\ControlFrameCodec;
use Bedriox\Protocol\GatewayControl\ControlHandshakeClaims;
use Bedriox\Protocol\GatewayControl\ControlHandshakeStage;
use Bedriox\Protocol\GatewayControl\ControlHandshakeToken;
use Bedriox\Protocol\GatewayControl\ControlMessageType;
use Bedriox\Server\GatewayControl\GatewayControlConfig;
use Bedriox\Server\GatewayControl\GatewayControlServer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GatewayControlServerTest extends TestCase
{
    private const string SECRET = '0123456789abcdef0123456789abcdef';
    private const int NOW = 2_000_000_000;

    public function testMutualHandshakeHealthAndReplayRejection(): void
    {
        $server = $this->server();
        [$hello, $helloClaims] = $this->hello();
        $challengeBytes = $server->openAndAccept('127.0.0.1:40000', $hello, self::NOW, 3, 20)[0];
        $challengeFrame = ControlFrameCodec::decode($challengeBytes);
        self::assertSame(ControlMessageType::Challenge, $challengeFrame->type);
        self::assertFalse($challengeFrame->encrypted());
        $challenge = ControlHandshakeToken::verify($challengeFrame->payload, self::SECRET);
        self::assertSame($helloClaims->gatewayNonce, $challenge->gatewayNonce);
        self::assertNotNull($challenge->serverNonce);

        $authentication = new ControlHandshakeClaims(
            ControlHandshakeStage::Authenticate,
            'bedriox-gateway',
            'lobby',
            'primary',
            $challenge->gatewayNonce,
            $challenge->serverNonce,
            self::NOW,
            self::NOW + 30,
        );
        $authenticate = ControlFrameCodec::encode(new ControlFrame(
            ControlMessageType::Authenticate,
            ControlFrame::requestId(),
            0,
            ControlHandshakeToken::issue($authentication, self::SECRET),
        ));
        $readyBytes = $server->accept('127.0.0.1:40000', $authenticate, self::NOW, 3, 20)[0];
        $key = ControlHandshakeToken::deriveSessionKey(self::SECRET, $authentication);
        $fromServer = new ControlFrameCipher($key, 'server-to-gateway');
        $fromGateway = new ControlFrameCipher($key, 'gateway-to-server');
        $ready = $fromServer->open(ControlFrameCodec::decode($readyBytes));
        self::assertSame(ControlMessageType::Ready, $ready->type);
        self::assertSame(0, $ready->sequence);

        $health = $fromGateway->seal(new ControlFrame(
            ControlMessageType::HealthRequest,
            ControlFrame::requestId(),
            0,
            '{}',
        ));
        $healthBytes = ControlFrameCodec::encode($health);
        $responseBytes = $server->accept('127.0.0.1:40000', $healthBytes, self::NOW, 3, 20)[0];
        $response = $fromServer->open(ControlFrameCodec::decode($responseBytes));
        self::assertSame(ControlMessageType::HealthResponse, $response->type);
        self::assertSame(1, $response->sequence);
        self::assertSame(['status' => 'healthy', 'players' => 3, 'max_players' => 20], json_decode($response->payload, true));

        $this->expectException(RuntimeException::class);
        $server->accept('127.0.0.1:40000', $healthBytes, self::NOW, 3, 20);
    }

    public function testRejectsExpiredMismatchedAndReplayedHelloWithoutFallback(): void
    {
        $server = $this->server();
        [$hello] = $this->hello();
        $server->openAndAccept('127.0.0.1:40000', $hello, self::NOW, 0, 20);

        try {
            $server->openAndAccept('127.0.0.1:40001', $hello, self::NOW, 0, 20);
            self::fail('Expected replayed nonce rejection.');
        } catch (RuntimeException) {
            self::assertFalse($server->has('127.0.0.1:40001'));
        }

        $expired = new ControlHandshakeClaims(
            ControlHandshakeStage::Hello,
            'wrong-issuer',
            'lobby',
            'primary',
            ControlHandshakeClaims::nonce(),
            null,
            self::NOW - 31,
            self::NOW - 1,
        );
        $frame = ControlFrameCodec::encode(new ControlFrame(
            ControlMessageType::Hello,
            ControlFrame::requestId(),
            0,
            ControlHandshakeToken::issue($expired, self::SECRET),
        ));
        $this->expectException(RuntimeException::class);
        $server->openAndAccept('127.0.0.1:40002', $frame, self::NOW, 0, 20);
    }

    public function testDiscriminatorRequiresControlMagic(): void
    {
        self::assertTrue(GatewayControlServer::identifies('BXGCpayload'));
        self::assertFalse(GatewayControlServer::identifies("\xfe\x00\x00\x00"));
        self::assertFalse(GatewayControlServer::identifies('BXG'));
    }

    public function testUnregisteredPluginMessageRejectionIdentifiesCanonicalChannel(): void
    {
        $server = $this->server();
        [$hello] = $this->hello();
        $challengeFrame = ControlFrameCodec::decode(
            $server->openAndAccept('127.0.0.1:40000', $hello, self::NOW, 0, 20)[0],
        );
        $challenge = ControlHandshakeToken::verify($challengeFrame->payload, self::SECRET);
        $authentication = new ControlHandshakeClaims(
            ControlHandshakeStage::Authenticate,
            'bedriox-gateway',
            'lobby',
            'primary',
            $challenge->gatewayNonce,
            $challenge->serverNonce,
            self::NOW,
            self::NOW + 30,
        );
        $authenticate = ControlFrameCodec::encode(new ControlFrame(
            ControlMessageType::Authenticate,
            ControlFrame::requestId(),
            0,
            ControlHandshakeToken::issue($authentication, self::SECRET),
        ));
        $key = ControlHandshakeToken::deriveSessionKey(self::SECRET, $authentication);
        $fromServer = new ControlFrameCipher($key, 'server-to-gateway');
        $fromGateway = new ControlFrameCipher($key, 'gateway-to-server');
        $fromServer->open(ControlFrameCodec::decode(
            $server->accept('127.0.0.1:40000', $authenticate, self::NOW, 0, 20)[0],
        ));
        self::assertSame(1, $server->readySessionCount());

        $message = $fromGateway->seal(new ControlFrame(
            ControlMessageType::PluginMessage,
            ControlFrame::requestId(),
            0,
            json_encode([
                'channel' => 'example:missing',
                'payload' => base64_encode('hello'),
            ], JSON_THROW_ON_ERROR),
        ));
        $response = $fromServer->open(ControlFrameCodec::decode(
            $server->accept(
                '127.0.0.1:40000',
                ControlFrameCodec::encode($message),
                self::NOW,
                0,
                20,
            )[0],
        ));

        self::assertSame(ControlMessageType::PluginMessageRejected, $response->type);
        self::assertSame([
            'reason' => 'unhandled_plugin_channel',
            'channel' => 'example:missing',
        ], json_decode($response->payload, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testOutboundPluginMessageRejectionReturnsToRegisteredChannel(): void
    {
        $server = $this->server();
        $rejections = [];
        $server->registerPluginChannel(
            'example:status',
            static function (string $payload): void {},
            static function (string $reason) use (&$rejections): void {
                $rejections[] = $reason;
            },
        );
        [$hello] = $this->hello();
        $challengeFrame = ControlFrameCodec::decode(
            $server->openAndAccept('127.0.0.1:40000', $hello, self::NOW, 0, 20)[0],
        );
        $challenge = ControlHandshakeToken::verify($challengeFrame->payload, self::SECRET);
        $authentication = new ControlHandshakeClaims(
            ControlHandshakeStage::Authenticate,
            'bedriox-gateway',
            'lobby',
            'primary',
            $challenge->gatewayNonce,
            $challenge->serverNonce,
            self::NOW,
            self::NOW + 30,
        );
        $authenticate = ControlFrameCodec::encode(new ControlFrame(
            ControlMessageType::Authenticate,
            ControlFrame::requestId(),
            0,
            ControlHandshakeToken::issue($authentication, self::SECRET),
        ));
        $key = ControlHandshakeToken::deriveSessionKey(self::SECRET, $authentication);
        $fromServer = new ControlFrameCipher($key, 'server-to-gateway');
        $fromGateway = new ControlFrameCipher($key, 'gateway-to-server');
        $fromServer->open(ControlFrameCodec::decode(
            $server->accept('127.0.0.1:40000', $authenticate, self::NOW, 0, 20)[0],
        ));

        $outbound = $fromServer->open(ControlFrameCodec::decode(
            $server->broadcastPluginMessage('example:status', 'ready')['127.0.0.1:40000'],
        ));
        $rejection = $fromGateway->seal(new ControlFrame(
            ControlMessageType::PluginMessageRejected,
            $outbound->requestId,
            0,
            json_encode(['reason' => 'unhandled_plugin_channel'], JSON_THROW_ON_ERROR),
        ));

        self::assertSame([], $server->accept(
            '127.0.0.1:40000',
            ControlFrameCodec::encode($rejection),
            self::NOW,
            0,
            20,
        ));
        self::assertSame(['unhandled_plugin_channel'], $rejections);
        self::assertTrue($server->has('127.0.0.1:40000'));
    }

    private function server(): GatewayControlServer
    {
        return new GatewayControlServer(new GatewayControlConfig(
            self::SECRET,
            'bedriox-gateway',
            'lobby',
        ));
    }

    /** @return array{string, ControlHandshakeClaims} */
    private function hello(): array
    {
        $claims = new ControlHandshakeClaims(
            ControlHandshakeStage::Hello,
            'bedriox-gateway',
            'lobby',
            'primary',
            ControlHandshakeClaims::nonce(),
            null,
            self::NOW,
            self::NOW + 30,
        );
        return [ControlFrameCodec::encode(new ControlFrame(
            ControlMessageType::Hello,
            ControlFrame::requestId(),
            0,
            ControlHandshakeToken::issue($claims, self::SECRET),
        )), $claims];
    }
}
