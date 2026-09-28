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

use Bedriox\Protocol\Exception\InvalidValueException;
use Bedriox\Protocol\Exception\MalformedDataException;
use Bedriox\Protocol\Identity\CertificateChainMode;
use Bedriox\Protocol\Identity\ClientDataJwtVerifier;
use Bedriox\Protocol\Identity\IdentityProofLimits;
use Bedriox\Protocol\Identity\LegacyCertificateChainVerifier;
use Bedriox\Protocol\Packet\AuthenticationType;
use Bedriox\Protocol\Packet\LoginPacket;
use Bedriox\Server\Authentication\AuthenticationClock;
use Bedriox\Server\Authentication\AuthenticationException;
use JsonException;

/** Explicit, insecure development authentication. This is never a fallback from FULL authentication. */
final readonly class ExplicitSelfSignedLoginAuthenticator implements LoginAuthenticator
{
    private LegacyCertificateChainVerifier $certificateVerifier;

    public function __construct(
        private AuthenticationClock $clock,
        private ClientDataJwtVerifier $clientDataVerifier,
        ?IdentityProofLimits $identityLimits = null,
    ) {
        $this->certificateVerifier = LegacyCertificateChainVerifier::forExplicitSelfSigned($identityLimits);
    }

    public function authenticate(LoginPacket $packet, AuthenticationMode $mode): AuthenticatedLogin
    {
        if ($mode !== AuthenticationMode::SELF_SIGNED) {
            throw new AuthenticationException('Explicit self-signed authenticator cannot authenticate another mode.');
        }
        $authentication = $packet->authentication;
        if ($authentication->type !== AuthenticationType::SelfSigned
            || $authentication->token !== null
            || $authentication->certificateChain === null
            || count($authentication->certificateChain) !== 1) {
            throw new AuthenticationException('Explicit self-signed Certificate authentication is required.');
        }

        try {
            $certificateJson = json_encode(
                ['chain' => $authentication->certificateChain],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
            $identity = $this->certificateVerifier->verify(
                $certificateJson,
                CertificateChainMode::SelfSignedExplicit,
                $this->clock->nowEpochSeconds(),
            );
            $clientData = $this->clientDataVerifier->verify($packet->clientJwt, $identity->identityPublicKey);
        } catch (JsonException|InvalidValueException|MalformedDataException) {
            throw new AuthenticationException('Explicit self-signed authentication proof is invalid.');
        }

        return new AuthenticatedLogin(
            $identity->displayName,
            $identity->identity,
            $identity->xuid,
            $identity->identityPublicKey,
            $clientData,
        );
    }
}
