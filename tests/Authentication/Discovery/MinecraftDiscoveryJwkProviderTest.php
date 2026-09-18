<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Authentication\Discovery;

use Bedriox\Protocol\Security\Base64Url;
use Bedriox\Server\Authentication\AuthenticationClock;
use Bedriox\Server\Authentication\Discovery\DiscoveryException;
use Bedriox\Server\Authentication\Discovery\DiscoveryLimits;
use Bedriox\Server\Authentication\Discovery\EndpointPolicy;
use Bedriox\Server\Authentication\Discovery\HttpsJsonTransport;
use Bedriox\Server\Authentication\Discovery\KeyId;
use Bedriox\Server\Authentication\Discovery\MinecraftDiscoveryJwkProvider;
use PHPUnit\Framework\TestCase;

final class MinecraftDiscoveryJwkProviderTest extends TestCase
{
    public function testColdRefreshUsesFixedBoundedThreeStageDiscovery(): void
    {
        $clock = new MutableClock(100);
        $http = new FakeHttpsJsonTransport(self::responses(self::key('old')));
        $provider = new MinecraftDiscoveryJwkProvider($http, $clock);
        self::assertSame([], $http->calls);
        self::assertTrue($provider->needsRefresh());
        $provider->refresh();
        self::assertSame('old', $provider->keys()[0]['kid']);
        self::assertSame([
            MinecraftDiscoveryJwkProvider::DISCOVERY_URL => 65536,
            MinecraftDiscoveryJwkProvider::AUTH_SERVICE . '/.well-known/openid-configuration' => 65536,
            MinecraftDiscoveryJwkProvider::AUTH_SERVICE . '/.well-known/keys' => 262144,
        ], $http->limitsByUrl);
    }

    public function testRotationAndUnknownKidRefreshAreBounded(): void
    {
        $clock = new MutableClock(100);
        $http = new FakeHttpsJsonTransport(array_merge(
            self::responses(self::key('old')),
            self::responses(self::key('new')),
        ));
        $provider = new MinecraftDiscoveryJwkProvider($http, $clock, new DiscoveryLimits(unknownKidRefreshIntervalSeconds: 60));
        $provider->refresh();
        self::assertSame('old', $provider->keys()[0]['kid']);
        self::assertTrue($provider->requestRefreshFor(new KeyId('new')));
        self::assertTrue($provider->needsRefresh());
        self::assertSame('old', $provider->keys()[0]['kid']);
        $provider->refresh();
        self::assertSame('new', $provider->keys()[0]['kid']);
        $calls = $http->calls;
        self::assertFalse($provider->requestRefreshFor(new KeyId('absent')));
        self::assertSame($calls, $http->calls, 'Unknown-kid refresh is rate limited.');
    }

    public function testLastKnownGoodSurvivesBadRefreshButHardStaleFailsClosed(): void
    {
        $clock = new MutableClock(100);
        $http = new FakeHttpsJsonTransport(array_merge(
            self::responses(self::key('old')),
            [new DiscoveryException('synthetic status failure'), new DiscoveryException('synthetic status failure')],
        ));
        $limits = new DiscoveryLimits(refreshTtlSeconds: 10, hardStaleSeconds: 20);
        $provider = new MinecraftDiscoveryJwkProvider($http, $clock, $limits);
        $provider->refresh();
        self::assertSame('old', $provider->keys()[0]['kid']);
        $clock->now = 111;
        self::assertTrue($provider->needsRefresh());
        try {
            $provider->refresh();
            self::fail('Bad refresh did not report failure.');
        } catch (DiscoveryException) {
            self::addToAssertionCount(1);
        }
        self::assertSame('old', $provider->keys()[0]['kid']);
        $clock->now = 121;
        $this->expectException(DiscoveryException::class);
        $provider->keys();
    }

    public function testColdTransportStatusRedirectAndOversizeFailuresClose(): void
    {
        foreach (['status', 'redirect', 'oversize'] as $failure) {
            $http = new FakeHttpsJsonTransport([new DiscoveryException("synthetic {$failure} failure")]);
            $provider = new MinecraftDiscoveryJwkProvider($http, new MutableClock(1));
            try {
                $provider->keys();
                self::fail('Cold transport failure was accepted.');
            } catch (DiscoveryException) {
                self::addToAssertionCount(1);
            }
            self::assertSame([], $http->calls, 'Snapshot reads must never perform HTTP.');
            try {
                $provider->refresh();
            } catch (DiscoveryException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testEndpointPolicyRejectsSchemeHostCredentialsPortIpAndFragment(): void
    {
        foreach ([
            'http://authorization.franchise.minecraft-services.net/.well-known/keys',
            'https://evil.invalid/.well-known/keys',
            'https://user@authorization.franchise.minecraft-services.net/.well-known/keys',
            'https://authorization.franchise.minecraft-services.net:444/.well-known/keys',
            'https://127.0.0.1/.well-known/keys',
            'https://authorization.franchise.minecraft-services.net/.well-known/keys#fragment',
        ] as $url) {
            try {
                EndpointPolicy::assertAllowed($url);
                self::fail('Unsafe endpoint was accepted.');
            } catch (DiscoveryException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testServiceIssuerJwksHostJsonAndKeysAreValidatedAtomically(): void
    {
        $cases = [
            [self::json(['result' => ['serviceEnvironments' => ['auth' => ['prod' => ['serviceUri' => 'https://evil.invalid']]]]])],
            [self::discovery(), self::json(['issuer' => 'https://evil.invalid/', 'jwks_uri' => MinecraftDiscoveryJwkProvider::AUTH_SERVICE . '/.well-known/keys'])],
            [self::discovery(), self::openId('https://evil.invalid/keys')],
            [self::discovery(), self::openId(), '{"keys":[],"keys":[]}'],
            [self::discovery(), self::openId(), self::json(['keys' => [self::key('same'), self::key('same')]])],
            [self::discovery(), self::openId(), self::json(['keys' => [['kty' => 'EC', 'use' => 'sig', 'kid' => 'ec', 'n' => 'a', 'e' => 'a']]])],
            [self::discovery(), self::openId(), self::json(['keys' => [array_replace(self::key('null-alg'), ['alg' => null])]])],
        ];
        foreach ($cases as $responses) {
            try {
                (new MinecraftDiscoveryJwkProvider(new FakeHttpsJsonTransport($responses), new MutableClock(1)))->refresh();
                self::fail('Invalid discovery chain was accepted.');
            } catch (DiscoveryException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testJsonDepthTokenUtf8AndKeyCountLimits(): void
    {
        $manyKeys = [];
        for ($index = 0; $index < 65; ++$index) {
            $manyKeys[] = self::key('key-' . $index);
        }
        $cases = [
            [["\xff"]],
            [[self::json(['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => ['i' => 1]]]]]]]]])]],
            [self::responses(self::key('old')), new DiscoveryLimits(maximumJsonTokens: 4)],
            [[self::discovery(), self::openId(), self::json(['keys' => $manyKeys])]],
        ];
        foreach ($cases as $case) {
            $responses = $case[0];
            $limits = $case[1] ?? new DiscoveryLimits();
            try {
                (new MinecraftDiscoveryJwkProvider(new FakeHttpsJsonTransport($responses), new MutableClock(1), $limits))->refresh();
                self::fail('A bounded JSON/JWKS limit was not enforced.');
            } catch (DiscoveryException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testClockRegressionFailsWithoutTransport(): void
    {
        $clock = new MutableClock(100);
        $http = new FakeHttpsJsonTransport(self::responses(self::key('old')));
        $provider = new MinecraftDiscoveryJwkProvider($http, $clock);
        $provider->refresh();
        $calls = $http->calls;
        $clock->now = 99;
        $this->expectException(DiscoveryException::class);
        try {
            $provider->keys();
        } finally {
            self::assertSame($calls, $http->calls);
        }
    }

    public function testFailedRefreshUsesCappedExponentialBackoffAndForceCannotBypassIt(): void
    {
        $clock = new MutableClock(100);
        $http = new FakeHttpsJsonTransport([
            new DiscoveryException('first failure'),
            new DiscoveryException('second failure'),
            new DiscoveryException('third failure'),
            ...self::responses(self::key('recovered')),
        ]);
        $provider = new MinecraftDiscoveryJwkProvider($http, $clock, new DiscoveryLimits(
            initialFailureRetrySeconds: 5,
            maximumFailureRetrySeconds: 10,
        ));

        foreach ([[100, 5], [105, 10], [115, 10]] as [$attemptAt, $delay]) {
            $clock->now = $attemptAt;
            try {
                $provider->refresh();
                self::fail('Synthetic refresh failure did not fail.');
            } catch (DiscoveryException) {
                self::addToAssertionCount(1);
            }
            $calls = $http->calls;
            self::assertFalse($provider->needsRefresh());
            self::assertFalse($provider->refresh(true), 'Force must not bypass failure backoff.');
            self::assertSame($calls, $http->calls);
            $clock->now = $attemptAt + $delay;
            self::assertTrue($provider->needsRefresh());
        }

        self::assertTrue($provider->refresh());
        self::assertSame('recovered', $provider->keys()[0]['kid']);
        self::assertFalse($provider->needsRefresh());
        self::assertFalse($provider->refresh());
    }

    public function testForceBypassesFreshnessButNotSafetyChecks(): void
    {
        $clock = new MutableClock(100);
        $http = new FakeHttpsJsonTransport(array_merge(
            self::responses(self::key('old')),
            self::responses(self::key('forced')),
        ));
        $provider = new MinecraftDiscoveryJwkProvider($http, $clock);
        self::assertTrue($provider->refresh());
        self::assertFalse($provider->refresh());
        self::assertTrue($provider->refresh(true));
        self::assertSame('forced', $provider->keys()[0]['kid']);
    }

    /**
     * @param array<string, mixed> $key
     * @return list<string>
     */
    private static function responses(array $key): array
    {
        return [self::discovery(), self::openId(), self::json(['keys' => [$key]])];
    }

    private static function discovery(): string
    {
        return self::json(['result' => ['serviceEnvironments' => ['auth' => ['prod' => ['serviceUri' => MinecraftDiscoveryJwkProvider::AUTH_SERVICE]]]]]);
    }

    private static function openId(string $jwks = MinecraftDiscoveryJwkProvider::AUTH_SERVICE . '/.well-known/keys'): string
    {
        return self::json(['issuer' => 'https://authorization.franchise.minecraft-services.net/', 'jwks_uri' => $jwks]);
    }

    /** @return array<string, mixed> */
    private static function key(string $kid): array
    {
        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'kid' => $kid,
            'n' => Base64Url::encode(str_repeat("\xff", 256)),
            'e' => Base64Url::encode("\x01\x00\x01"),
        ];
    }

    /** @param array<string, mixed> $value */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}

final class MutableClock implements AuthenticationClock
{
    public function __construct(public int $now) {}

    public function nowEpochSeconds(): int
    {
        return $this->now;
    }
}

final class FakeHttpsJsonTransport implements HttpsJsonTransport
{
    /** @var list<string|DiscoveryException> */
    private array $responses;
    /** @var list<string> */
    public array $calls = [];
    /** @var array<string, int> */
    public array $limitsByUrl = [];

    /** @param list<string|DiscoveryException> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function get(string $url, int $maximumResponseBytes): string
    {
        $this->calls[] = $url;
        $this->limitsByUrl[$url] = $maximumResponseBytes;
        $response = array_shift($this->responses);
        if ($response instanceof DiscoveryException) {
            throw $response;
        }
        if (!is_string($response)) {
            throw new DiscoveryException('No synthetic response remains.');
        }
        if (strlen($response) > $maximumResponseBytes) {
            throw new DiscoveryException('Synthetic response is oversized.');
        }
        return $response;
    }
}
