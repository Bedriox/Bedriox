<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Authentication;

use Bedriox\Protocol\Identity\ClientDataJwtVerifier;
use Bedriox\Protocol\Packet\AuthenticationType;
use Bedriox\Protocol\Packet\LoginAuthentication;
use Bedriox\Protocol\Security\Base64Url;
use Bedriox\Protocol\Security\CompactJws;
use Bedriox\Protocol\Security\OpenSslEphemeralKeyFactory;
use Bedriox\Protocol\Security\P384;
use Bedriox\Protocol\Security\P384KeyPair;
use Bedriox\Server\Authentication\AuthenticationClock;
use Bedriox\Server\Authentication\AuthenticationException;
use Bedriox\Server\Authentication\Discovery\KeyId;
use Bedriox\Server\Authentication\Discovery\RefreshRequestingJwkProvider;
use Bedriox\Server\Authentication\FullTokenAuthenticator;
use Bedriox\Server\Authentication\JwkProvider;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FullTokenAuthenticatorTest extends TestCase
{
    public const int NOW = 2_000_000_000;

    private OpenSSLAsymmetricKey $rsa;
    /** @var array<string, mixed> */
    private array $jwk;
    private P384KeyPair $client;

    protected function setUp(): void
    {
        $rsa = openssl_pkey_new([
            'config' => self::configurationFile(),
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $rsa);
        $details = openssl_pkey_get_details($rsa);
        self::assertIsArray($details);
        self::assertIsArray($details['rsa']);
        $this->rsa = $rsa;
        $modulus = $details['rsa']['n'] ?? null;
        $exponent = $details['rsa']['e'] ?? null;
        self::assertIsString($modulus);
        self::assertIsString($exponent);
        $this->jwk = [
            'kty' => 'RSA',
            'use' => 'sig',
            'kid' => 'retail-test-key',
            'n' => Base64Url::encode($modulus),
            'e' => Base64Url::encode($exponent),
        ];
        $this->client = (new OpenSslEphemeralKeyFactory(self::configurationFile()))->generate();
    }

    public function testSyntheticRsaJwkAndClientProofAuthenticate(): void
    {
        $identity = $this->authenticator([$this->jwk])->authenticate(
            new LoginAuthentication(AuthenticationType::Full, $this->token()),
            $this->clientJwt(),
        );
        self::assertSame('retail-subject', $identity->subject);
        self::assertSame('Veno Player', $identity->displayName);
        self::assertSame('123456789', $identity->xuid);
        self::assertSame('minecraft-id', $identity->minecraftId);
        self::assertSame("\x01\x02\x03\x04", $identity->clientData->skin);
        self::assertTrue((new \ReflectionProperty($identity, 'subject'))->isReadOnly());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidClaimProvider(): iterable
    {
        yield 'issuer' => [['iss' => 'https://evil.invalid/']];
        yield 'audience array' => [['aud' => [FullTokenAuthenticator::AUDIENCE]]];
        yield 'expired' => [['exp' => self::NOW - 60]];
        yield 'future nbf' => [['nbf' => self::NOW + 61]];
        yield 'future iat' => [['iat' => self::NOW + 61]];
        yield 'floating exp' => [['exp' => (float) self::NOW + 60.5]];
        yield 'null nbf' => [['nbf' => null]];
        yield 'missing subject' => [['sub' => null]];
        yield 'non-decimal xid' => [['xid' => 'player']];
        yield 'missing cpk' => [['cpk' => null]];
    }

    /** @param array<string, mixed> $replacement */
    #[DataProvider('invalidClaimProvider')]
    public function testInvalidClaimsAreRejected(array $replacement): void
    {
        $this->expectException(AuthenticationException::class);
        $this->authenticator([$this->jwk])->authenticate(
            new LoginAuthentication(AuthenticationType::Full, $this->token($replacement)),
            $this->clientJwt(),
        );
    }

    public function testWrongAuthenticationTypeAndClientProofAreRejected(): void
    {
        try {
            $this->authenticator([$this->jwk])->authenticate(
                new LoginAuthentication(AuthenticationType::SelfSigned, certificateChain: ['x']),
                $this->clientJwt(),
            );
            self::fail('Non-FULL authentication was accepted.');
        } catch (AuthenticationException) {
            self::addToAssertionCount(1);
        }
        $other = (new OpenSslEphemeralKeyFactory(self::configurationFile()))->generate();
        $this->expectException(AuthenticationException::class);
        $this->authenticator([$this->jwk])->authenticate(
            new LoginAuthentication(AuthenticationType::Full, $this->token()),
            CompactJws::sign(['alg' => 'ES384'], self::clientClaims(), $other->privateKey),
        );
    }

    public function testAlgorithmConfusionMutationAndUnknownKidAreRejected(): void
    {
        foreach ([
            $this->token(header: ['alg' => 'HS256', 'kid' => 'retail-test-key']),
            $this->token(header: ['kid' => 'retail-test-key', 'crit' => null]),
            $this->token(header: ['kid' => 'retail-test-key', 'b64' => null]),
            $this->token(header: ['alg' => 'RS256', 'kid' => 'unknown']),
            $this->mutateSignature($this->token()),
            'not.a.jwt.with.too.many.parts',
        ] as $token) {
            try {
                $this->authenticator([$this->jwk])->authenticate(
                    new LoginAuthentication(AuthenticationType::Full, $token),
                    $this->clientJwt(),
                );
                self::fail('Invalid token was accepted.');
            } catch (AuthenticationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testUnknownKidOnlyRequestsDeferredRefresh(): void
    {
        $provider = new class ($this->jwk) implements RefreshRequestingJwkProvider {
            public ?string $requested = null;
            /** @param array<string, mixed> $key */
            public function __construct(private readonly array $key) {}
            public function keys(): array
            {
                return [$this->key];
            }
            public function requestRefreshFor(KeyId $keyId): bool
            {
                $this->requested = $keyId->value;
                return true;
            }
        };
        $clock = new class implements AuthenticationClock {
            public function nowEpochSeconds(): int
            {
                return FullTokenAuthenticatorTest::NOW;
            }
        };
        try {
            (new FullTokenAuthenticator($provider, $clock, new ClientDataJwtVerifier()))->authenticate(
                new LoginAuthentication(AuthenticationType::Full, $this->token(header: ['kid' => 'rotated-key'])),
                $this->clientJwt(),
            );
            self::fail('Unknown key was accepted.');
        } catch (AuthenticationException) {
            self::assertSame('rotated-key', $provider->requested);
        }
    }

    public function testMalformedWeakAndDuplicateJwksAreRejected(): void
    {
        $weak = $this->jwk;
        $weak['n'] = Base64Url::encode(str_repeat("\xff", 255));
        $malformed = $this->jwk;
        $malformed['e'] = '***';
        $wrongUse = $this->jwk;
        $wrongUse['use'] = 'enc';
        foreach ([[$weak], [$malformed], [$wrongUse], [$this->jwk, $this->jwk]] as $keys) {
            try {
                $this->authenticator($keys)->authenticate(
                    new LoginAuthentication(AuthenticationType::Full, $this->token()),
                    $this->clientJwt(),
                );
                self::fail('Invalid JWK snapshot was accepted.');
            } catch (AuthenticationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testCpkMustBeCanonicalP384(): void
    {
        $p256 = openssl_pkey_new([
            'config' => self::configurationFile(),
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $p256);
        $details = openssl_pkey_get_details($p256);
        self::assertIsArray($details);
        $pem = $details['key'] ?? null;
        self::assertIsString($pem);
        $der = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $pem);
        self::assertIsString($der);
        foreach (['not-a-key', $der] as $cpk) {
            try {
                $this->authenticator([$this->jwk])->authenticate(
                    new LoginAuthentication(AuthenticationType::Full, $this->token(['cpk' => $cpk])),
                    $this->clientJwt(),
                );
                self::fail('Invalid client public key was accepted.');
            } catch (AuthenticationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @param list<array<string, mixed>> $keys */
    private function authenticator(array $keys): FullTokenAuthenticator
    {
        $provider = new class ($keys) implements JwkProvider {
            /** @param list<array<string, mixed>> $trusted */
            public function __construct(private readonly array $trusted) {}
            public function keys(): array
            {
                return $this->trusted;
            }
        };
        $clock = new class implements AuthenticationClock {
            public function nowEpochSeconds(): int
            {
                return FullTokenAuthenticatorTest::NOW;
            }
        };
        return new FullTokenAuthenticator($provider, $clock, new ClientDataJwtVerifier());
    }

    /**
     * @param array<string, mixed> $claims
     * @param array<string, mixed>|null $header
     */
    private function token(array $claims = [], ?array $header = null): string
    {
        $payload = array_replace([
            'iss' => FullTokenAuthenticator::ISSUER,
            'aud' => FullTokenAuthenticator::AUDIENCE,
            'exp' => self::NOW + 300,
            'nbf' => self::NOW - 30,
            'iat' => self::NOW - 30,
            'sub' => 'retail-subject',
            'cpk' => P384::exportPublicDerBase64($this->client->publicKey),
            'xname' => 'Veno Player',
            'xid' => '123456789',
            'mid' => 'minecraft-id',
        ], $claims);
        $protected = array_replace(['alg' => 'RS256'], $header ?? ['kid' => 'retail-test-key']);
        $headerJson = json_encode($protected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $input = Base64Url::encode($headerJson) . '.' . Base64Url::encode($payloadJson);
        $signature = '';
        self::assertTrue(openssl_sign($input, $signature, $this->rsa, OPENSSL_ALGO_SHA256));
        self::assertIsString($signature);
        return $input . '.' . Base64Url::encode($signature);
    }

    private function clientJwt(): string
    {
        return CompactJws::sign(['alg' => 'ES384'], self::clientClaims(), $this->client->privateKey);
    }

    /** @return array<string, mixed> */
    private static function clientClaims(): array
    {
        return [
            'SkinImageWidth' => 1, 'SkinImageHeight' => 1, 'SkinData' => base64_encode("\x01\x02\x03\x04"),
            'CapeImageWidth' => 0, 'CapeImageHeight' => 0, 'CapeData' => '',
            'SkinGeometryData' => base64_encode('{}'), 'AnimatedImageData' => [],
        ];
    }

    private function mutateSignature(string $token): string
    {
        $parts = explode('.', $token);
        $signature = Base64Url::decode($parts[2], 512);
        $signature[0] = chr(ord($signature[0]) ^ 1);
        $parts[2] = Base64Url::encode($signature);
        return implode('.', $parts);
    }

    private static function configurationFile(): string
    {
        return dirname(__DIR__) . '/Fixtures/openssl.cnf';
    }
}
