<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Login;

use Bedriox\Protocol\Identity\VerifiedClientData;
use Bedriox\Protocol\Packet\AuthenticationType;
use Bedriox\Protocol\Packet\LoginAuthentication;
use Bedriox\Protocol\Packet\LoginPacket;
use Bedriox\Protocol\ProtocolVersion;
use Bedriox\Protocol\Security\OpenSslEphemeralKeyFactory;
use Bedriox\Server\Authentication\AuthenticationException;
use Bedriox\Server\Login\AuthenticatedLogin;
use Bedriox\Server\Login\AuthenticationMode;
use Bedriox\Server\Login\DevelopmentLoginAuthenticator;
use Bedriox\Server\Login\LoginAuthenticator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DevelopmentLoginAuthenticatorTest extends TestCase
{
    #[DataProvider('supportedAuthenticationTypes')]
    public function testRoutesEachSupportedTypeToItsStrictAuthenticator(
        AuthenticationType $type,
        AuthenticationMode $expectedMode,
        int $expectedFullCalls,
        int $expectedSelfSignedCalls,
    ): void {
        $full = new RecordingLoginAuthenticator();
        $selfSigned = new RecordingLoginAuthenticator();
        $authenticator = new DevelopmentLoginAuthenticator($full, $selfSigned);

        $authenticator->authenticate($this->packet($type), AuthenticationMode::SELF_SIGNED);

        self::assertSame($expectedFullCalls, $full->calls);
        self::assertSame($expectedSelfSignedCalls, $selfSigned->calls);
        self::assertSame($expectedMode, ($expectedFullCalls === 1 ? $full : $selfSigned)->lastMode);
    }

    /** @return iterable<string, array{AuthenticationType, AuthenticationMode, int, int}> */
    public static function supportedAuthenticationTypes(): iterable
    {
        yield 'verified retail' => [AuthenticationType::Full, AuthenticationMode::FULL, 1, 0];
        yield 'load client' => [AuthenticationType::SelfSigned, AuthenticationMode::SELF_SIGNED, 0, 1];
    }

    public function testRefusesUseOutsideDevelopmentMode(): void
    {
        $authenticator = new DevelopmentLoginAuthenticator(
            new RecordingLoginAuthenticator(),
            new RecordingLoginAuthenticator(),
        );

        $this->expectException(AuthenticationException::class);
        $authenticator->authenticate($this->packet(AuthenticationType::Full), AuthenticationMode::FULL);
    }

    public function testGuestAuthenticationIsRejected(): void
    {
        $authenticator = new DevelopmentLoginAuthenticator(
            new RecordingLoginAuthenticator(),
            new RecordingLoginAuthenticator(),
        );

        $this->expectException(AuthenticationException::class);
        $authenticator->authenticate($this->packet(AuthenticationType::Guest), AuthenticationMode::SELF_SIGNED);
    }

    private function packet(AuthenticationType $type): LoginPacket
    {
        $authentication = $type === AuthenticationType::SelfSigned
            ? new LoginAuthentication($type, certificateChain: ['certificate'])
            : new LoginAuthentication($type, token: 'token');

        return new LoginPacket(ProtocolVersion::CURRENT, $authentication, 'client');
    }
}

final class RecordingLoginAuthenticator implements LoginAuthenticator
{
    public int $calls = 0;
    public ?AuthenticationMode $lastMode = null;

    public function authenticate(LoginPacket $packet, AuthenticationMode $mode): AuthenticatedLogin
    {
        ++$this->calls;
        $this->lastMode = $mode;

        return new AuthenticatedLogin(
            'Player',
            '00000000-0000-0000-0000-000000000001',
            '',
            (new OpenSslEphemeralKeyFactory(dirname(__DIR__) . '/Fixtures/openssl.cnf'))->generate()->publicKey,
            new VerifiedClientData(1, 1, "\0\0\0\0", 0, 0, '', '{}', []),
        );
    }
}
