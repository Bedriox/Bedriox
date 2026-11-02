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

namespace Bedriox\Server\Login;

use Bedriox\Protocol\Identity\ClientDataJwtVerifier;
use Bedriox\Protocol\Identity\GatewayForwardingToken;
use Bedriox\Protocol\Packet\AuthenticationType;
use Bedriox\Protocol\Packet\LoginPacket;
use Bedriox\Server\Authentication\AuthenticationClock;
use Bedriox\Server\Authentication\AuthenticationException;
use Bedriox\Server\Security\TrustedProxyEndpointPolicy;
use Bedriox\Server\Transport\SessionInfo;

final readonly class ProxyLoginAuthenticator implements PeerAwareLoginAuthenticator
{
    private TrustedProxyEndpointPolicy $trustedEndpoints;

    /** @param list<string> $trustedCidrs */
    public function __construct(
        #[\SensitiveParameter]
        private string $secret,
        private string $audience,
        private string $issuer,
        private array $trustedCidrs,
        private AuthenticationClock $clock,
        private ClientDataJwtVerifier $clientData,
        private ProxyTokenReplayGuard $replayGuard,
        private int $maximumTokenAgeSeconds = 15,
        private ?string $peerAddress = null,
    ) {
        if ($this->audience === '' || $this->issuer === '' || $this->trustedCidrs === []) {
            throw new AuthenticationException('Proxy authentication configuration is incomplete.');
        }
        $this->trustedEndpoints = new TrustedProxyEndpointPolicy($this->trustedCidrs);
    }

    public function forPeer(SessionInfo $session): LoginAuthenticator
    {
        return new self(
            $this->secret,
            $this->audience,
            $this->issuer,
            $this->trustedCidrs,
            $this->clock,
            $this->clientData,
            $this->replayGuard,
            $this->maximumTokenAgeSeconds,
            $session->remoteAddress,
        );
    }

    public function authenticate(LoginPacket $packet, AuthenticationMode $mode): AuthenticatedLogin
    {
        if ($mode !== AuthenticationMode::GATEWAY || $packet->authentication->type !== AuthenticationType::Full
            || $packet->authentication->token === null) {
            throw new AuthenticationException('GATEWAY authentication requires a signed forwarding token.');
        }
        if ($this->peerAddress === null || !$this->trustedEndpoints->allows($this->peerAddress, 1)) {
            throw new AuthenticationException('Gateway source address is not trusted.');
        }

        try {
            $claims = GatewayForwardingToken::verify($packet->authentication->token, $this->secret);
        } catch (\Throwable $exception) {
            throw new AuthenticationException('Gateway forwarding token is invalid.', previous: $exception);
        }
        $now = $this->clock->nowEpochSeconds();
        if (!hash_equals($this->audience, $claims->audience) || !hash_equals($this->issuer, $claims->issuer)
            || $claims->protocolVersion !== $packet->protocolVersion
            || $claims->notBefore > $now || $claims->expiresAt < $now
            || $claims->issuedAt > $now || $now - $claims->issuedAt > $this->maximumTokenAgeSeconds) {
            throw new AuthenticationException('Gateway forwarding claims do not match this backend.');
        }
        $publicKey = $claims->publicKey();
        try {
            $clientData = $this->clientData->verify($packet->clientJwt, $publicKey);
        } catch (\Throwable $exception) {
            throw new AuthenticationException('Forwarded client data is invalid.', previous: $exception);
        }
        $this->replayGuard->accept($claims->tokenId, $claims->expiresAt, $now);

        return new AuthenticatedLogin(
            $claims->displayName,
            $claims->identity,
            $claims->xuid,
            $publicKey,
            $clientData,
            $claims->runtimeEntityId,
            $claims->operator,
        );
    }

}
