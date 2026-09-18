<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Login;

use Bedriox\Protocol\Identity\ClientDataJwtVerifier;
use Bedriox\Protocol\Packet\AuthenticationType;
use Bedriox\Protocol\Packet\LoginAuthentication;
use Bedriox\Protocol\Packet\LoginPacket;
use Bedriox\Protocol\ProtocolVersion;
use Bedriox\Protocol\Security\Base64Url;
use Bedriox\Protocol\Security\CompactJws;
use Bedriox\Protocol\Security\OpenSslEphemeralKeyFactory;
use Bedriox\Protocol\Security\P384;
use Bedriox\Protocol\Security\P384KeyPair;
use Bedriox\Server\Authentication\AuthenticationClock;
use Bedriox\Server\Authentication\AuthenticationException;
use Bedriox\Server\Login\AuthenticationMode;
use Bedriox\Server\Login\ExplicitSelfSignedLoginAuthenticator;
use PHPUnit\Framework\TestCase;

final class ExplicitSelfSignedLoginAuthenticatorTest extends TestCase
{
    public const int NOW = 2_000_000_000;

    public function testExplicitSelfSignedCertificateAndClientProofAuthenticateEndToEnd(): void
    {
        $identity = $this->generate();
        $login = $this->authenticator()->authenticate(
            $this->packet($this->certificate($identity), $this->clientJwt($identity)),
            AuthenticationMode::SELF_SIGNED,
        );

        self::assertSame('Offline Player', $login->displayName);
        self::assertSame('123e4567-e89b-42d3-a456-426614174000', $login->identity);
        self::assertSame('', $login->xuid);
        self::assertSame(
            P384::exportPublicDerBase64($identity->publicKey),
            P384::exportPublicDerBase64($login->identityPublicKey),
        );
        self::assertSame("\x01\x02\x03\x04", $login->clientData->skin);
    }

    public function testFullModeAndWrongOrTokenEnvelopesNeverFallback(): void
    {
        $identity = $this->generate();
        $valid = $this->packet($this->certificate($identity), $this->clientJwt($identity));
        $attempts = [
            [$valid, AuthenticationMode::FULL],
            [new LoginPacket(
                ProtocolVersion::CURRENT,
                new LoginAuthentication(AuthenticationType::Full, token: 'opaque-full-token'),
                $this->clientJwt($identity),
            ), AuthenticationMode::SELF_SIGNED],
            [new LoginPacket(
                ProtocolVersion::CURRENT,
                new LoginAuthentication(AuthenticationType::SelfSigned, token: 'not-a-certificate'),
                $this->clientJwt($identity),
            ), AuthenticationMode::SELF_SIGNED],
        ];

        foreach ($attempts as [$packet, $mode]) {
            try {
                $this->authenticator()->authenticate($packet, $mode);
                self::fail('Authentication mode or envelope was reinterpreted.');
            } catch (AuthenticationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testInvalidCertificateSignatureIsRejected(): void
    {
        $identity = $this->generate();
        $this->expectException(AuthenticationException::class);
        $this->authenticator()->authenticate(
            $this->packet($this->mutateSignature($this->certificate($identity)), $this->clientJwt($identity)),
            AuthenticationMode::SELF_SIGNED,
        );
    }

    public function testExpiredCertificateIsRejectedUsingInjectedClock(): void
    {
        $identity = $this->generate();
        $this->expectException(AuthenticationException::class);
        $this->authenticator()->authenticate(
            $this->packet($this->certificate($identity, self::NOW), $this->clientJwt($identity)),
            AuthenticationMode::SELF_SIGNED,
        );
    }

    public function testClientDataProofMustUseCertifiedIdentityKey(): void
    {
        $identity = $this->generate();
        $wrongIdentity = $this->generate();
        $this->expectException(AuthenticationException::class);
        $this->authenticator()->authenticate(
            $this->packet($this->certificate($identity), $this->clientJwt($wrongIdentity)),
            AuthenticationMode::SELF_SIGNED,
        );
    }

    private function authenticator(): ExplicitSelfSignedLoginAuthenticator
    {
        $clock = new class implements AuthenticationClock {
            public function nowEpochSeconds(): int
            {
                return ExplicitSelfSignedLoginAuthenticatorTest::NOW;
            }
        };
        return new ExplicitSelfSignedLoginAuthenticator($clock, new ClientDataJwtVerifier());
    }

    private function packet(string $certificate, string $clientJwt): LoginPacket
    {
        return new LoginPacket(
            ProtocolVersion::CURRENT,
            new LoginAuthentication(AuthenticationType::SelfSigned, certificateChain: [$certificate]),
            $clientJwt,
        );
    }

    private function certificate(P384KeyPair $identity, int $expires = self::NOW + 60): string
    {
        return CompactJws::sign(
            ['alg' => 'ES384', 'x5u' => P384::exportPublicDerBase64($identity->publicKey)],
            [
                'identityPublicKey' => P384::exportPublicDerBase64($identity->publicKey),
                'extraData' => [
                    'displayName' => 'Offline Player',
                    'identity' => '123e4567-e89b-42d3-a456-426614174000',
                    'XUID' => '',
                ],
                'nbf' => self::NOW - 60,
                'exp' => $expires,
            ],
            $identity->privateKey,
        );
    }

    private function clientJwt(P384KeyPair $identity): string
    {
        return CompactJws::sign(
            ['alg' => 'ES384'],
            [
                'SkinImageWidth' => 1,
                'SkinImageHeight' => 1,
                'SkinData' => base64_encode("\x01\x02\x03\x04"),
                'CapeImageWidth' => 0,
                'CapeImageHeight' => 0,
                'CapeData' => '',
                'SkinGeometryData' => base64_encode('{}'),
                'AnimatedImageData' => [],
            ],
            $identity->privateKey,
        );
    }

    private function mutateSignature(string $token): string
    {
        $parts = explode('.', $token);
        $signature = Base64Url::decode($parts[2], 96);
        $signature[0] = chr(ord($signature[0]) ^ 1);
        $parts[2] = Base64Url::encode($signature);
        return implode('.', $parts);
    }

    private function generate(): P384KeyPair
    {
        return (new OpenSslEphemeralKeyFactory(self::configurationFile()))->generate();
    }

    private static function configurationFile(): string
    {
        return dirname(__DIR__) . '/Fixtures/openssl.cnf';
    }
}
