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

namespace Bedriox\Server\Authentication\Discovery;

use Bedriox\Protocol\Security\Base64Url;
use Bedriox\Server\Authentication\AuthenticationClock;
use Bedriox\Server\Authentication\FullTokenAuthenticator;
use Throwable;

final class MinecraftDiscoveryJwkProvider implements RefreshRequestingJwkProvider
{
    public const string DISCOVERY_URL = 'https://client.discovery.minecraft-services.net/api/v1.0/discovery/MinecraftPE/builds/1.0.0.0';
    public const string AUTH_SERVICE = 'https://authorization.franchise.minecraft-services.net';

    private ?JwkSnapshot $snapshot = null;
    private ?int $lastUnknownKidRefresh = null;
    private bool $refreshing = false;
    private bool $refreshRequested = false;
    private ?int $lastObservedTime = null;
    private int $consecutiveRefreshFailures = 0;
    private ?int $nextRefreshAttemptAt = null;

    public function __construct(
        private readonly HttpsJsonTransport $transport,
        private readonly AuthenticationClock $clock,
        private readonly DiscoveryLimits $limits = new DiscoveryLimits(),
    ) {}

    public function keys(): array
    {
        $now = $this->now();
        if ($this->snapshot === null || $now >= $this->snapshot->hardExpiresAt) {
            throw new DiscoveryException('No current trusted JWK snapshot is available.');
        }
        return $this->snapshot->keys;
    }

    public function requestRefreshFor(KeyId $keyId): bool
    {
        if ($this->snapshot !== null && $this->snapshot->contains($keyId)) {
            return false;
        }
        $now = $this->now();
        if ($this->lastUnknownKidRefresh === null
            || $now >= self::saturatingAdd($this->lastUnknownKidRefresh, $this->limits->unknownKidRefreshIntervalSeconds)) {
            $this->lastUnknownKidRefresh = $now;
            $this->refreshRequested = true;
            return true;
        }
        return false;
    }

    public function needsRefresh(): bool
    {
        $now = $this->now();
        if ($this->nextRefreshAttemptAt !== null && $now < $this->nextRefreshAttemptAt) {
            return false;
        }
        return $this->snapshot === null || $this->refreshRequested
            || $now >= $this->snapshot->refreshedAt + $this->limits->refreshTtlSeconds;
    }

    /** Returns false when no refresh is due or failure backoff is active. */
    public function refresh(bool $force = false): bool
    {
        $now = $this->now();
        if ($this->nextRefreshAttemptAt !== null && $now < $this->nextRefreshAttemptAt) {
            return false;
        }
        if (!$force && $this->snapshot !== null && !$this->refreshRequested
            && $now < $this->snapshot->refreshedAt + $this->limits->refreshTtlSeconds) {
            return false;
        }
        if ($this->refreshing) {
            throw new DiscoveryException('JWK refresh is already in progress.');
        }
        $this->refreshing = true;
        try {
            $candidate = $this->fetch($now);
            $this->snapshot = $candidate;
            $this->refreshRequested = false;
            $this->consecutiveRefreshFailures = 0;
            $this->nextRefreshAttemptAt = null;
            return true;
        } catch (Throwable $exception) {
            if ($this->consecutiveRefreshFailures < 64) {
                ++$this->consecutiveRefreshFailures;
            }
            $this->nextRefreshAttemptAt = self::saturatingAdd($now, $this->failureDelay());
            if ($exception instanceof DiscoveryException) {
                throw $exception;
            }
            throw new DiscoveryException('JWK refresh failed.');
        } finally {
            $this->refreshing = false;
        }
    }

    private function fetch(int $now): JwkSnapshot
    {
        $discovery = $this->json(self::DISCOVERY_URL, $this->limits->maximumDiscoveryBytes);
        $result = self::objectMember($discovery, 'result');
        $environments = self::objectMember($result, 'serviceEnvironments');
        $auth = self::objectMember($environments, 'auth');
        $prod = self::objectMember($auth, 'prod');
        $service = $prod['serviceUri'] ?? null;
        if ($service !== self::AUTH_SERVICE) {
            throw new DiscoveryException('Discovery auth.prod service URI is invalid.');
        }
        $openIdUrl = $service . '/.well-known/openid-configuration';
        EndpointPolicy::assertAllowed($openIdUrl);
        $openId = $this->json($openIdUrl, $this->limits->maximumOpenIdBytes);
        if (($openId['issuer'] ?? null) !== FullTokenAuthenticator::ISSUER) {
            throw new DiscoveryException('OpenID issuer is invalid.');
        }
        $jwksUrl = $openId['jwks_uri'] ?? null;
        if (!is_string($jwksUrl)) {
            throw new DiscoveryException('OpenID JWKS URI is missing.');
        }
        EndpointPolicy::assertAllowed($jwksUrl);
        $jwks = $this->json($jwksUrl, $this->limits->maximumJwksBytes);
        $keys = $jwks['keys'] ?? null;
        if (!is_array($keys) || !array_is_list($keys) || $keys === [] || count($keys) > $this->limits->maximumJwkCount) {
            throw new DiscoveryException('JWKS key list is invalid.');
        }
        $validated = [];
        $seen = [];
        foreach ($keys as $key) {
            if (!is_array($key) || array_is_list($key) || ($key['kty'] ?? null) !== 'RSA'
                || ($key['use'] ?? null) !== 'sig'
                || (array_key_exists('alg', $key) && $key['alg'] !== 'RS256')) {
                throw new DiscoveryException('JWKS contains a non-RSA signing key.');
            }
            $kid = $key['kid'] ?? null;
            try {
                $keyId = is_string($kid) ? new KeyId($kid) : null;
            } catch (Throwable) {
                $keyId = null;
            }
            if ($keyId === null || isset($seen[$keyId->value]) || !is_string($key['n'] ?? null) || !is_string($key['e'] ?? null)) {
                throw new DiscoveryException('JWKS contains invalid or duplicate key identifiers.');
            }
            self::assertRsaParameters($key['n'], $key['e']);
            $seen[$keyId->value] = true;
            $normalized = [
                'kty' => 'RSA',
                'use' => 'sig',
                'kid' => $keyId->value,
                'n' => $key['n'],
                'e' => $key['e'],
            ];
            if (isset($key['alg'])) {
                $normalized['alg'] = 'RS256';
            }
            $validated[] = $normalized;
        }
        return new JwkSnapshot($validated, $now, $now + $this->limits->hardStaleSeconds);
    }

    /** @return array<string, mixed> */
    private function json(string $url, int $maximumBytes): array
    {
        EndpointPolicy::assertAllowed($url);
        return BoundedJsonDocument::decode($this->transport->get($url, $maximumBytes), $maximumBytes, $this->limits);
    }

    private function now(): int
    {
        $now = $this->clock->nowEpochSeconds();
        if ($now < 0 || $now > PHP_INT_MAX - $this->limits->hardStaleSeconds
            || ($this->lastObservedTime !== null && $now < $this->lastObservedTime)) {
            throw new DiscoveryException('Discovery clock returned invalid time.');
        }
        $this->lastObservedTime = $now;
        return $now;
    }

    private function failureDelay(): int
    {
        $delay = $this->limits->initialFailureRetrySeconds;
        for ($failure = 1; $failure < $this->consecutiveRefreshFailures; ++$failure) {
            if ($delay >= $this->limits->maximumFailureRetrySeconds
                || $delay > intdiv($this->limits->maximumFailureRetrySeconds, 2)) {
                return $this->limits->maximumFailureRetrySeconds;
            }
            $delay *= 2;
        }
        return min($delay, $this->limits->maximumFailureRetrySeconds);
    }

    private static function saturatingAdd(int $base, int $delta): int
    {
        return $base > PHP_INT_MAX - $delta ? PHP_INT_MAX : $base + $delta;
    }

    /**
     * @param array<string, mixed> $object
     * @return array<string, mixed>
     */
    private static function objectMember(array $object, string $name): array
    {
        $value = $object[$name] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            throw new DiscoveryException('Discovery document structure is invalid.');
        }
        $result = [];
        foreach ($value as $key => $member) {
            if (!is_string($key)) {
                throw new DiscoveryException('Discovery object key is invalid.');
            }
            $result[$key] = $member;
        }
        return $result;
    }

    private static function assertRsaParameters(string $encodedN, string $encodedE): void
    {
        try {
            $n = Base64Url::decode($encodedN, 512);
            $e = Base64Url::decode($encodedE, 8);
        } catch (Throwable) {
            throw new DiscoveryException('JWKS RSA parameters are invalid.');
        }
        if (strlen($n) < 256 || $n[0] === "\0" || $e === '' || $e[0] === "\0") {
            throw new DiscoveryException('JWKS RSA parameters are weak or non-canonical.');
        }
        $first = ord($n[0]);
        if ((strlen($n) - 1) * 8 + (int) floor(log($first, 2)) + 1 < 2048) {
            throw new DiscoveryException('JWKS RSA modulus is too weak.');
        }
        $exponent = 0;
        foreach (str_split($e) as $byte) {
            $octet = ord($byte);
            if ($exponent > intdiv(PHP_INT_MAX - $octet, 256)) {
                throw new DiscoveryException('JWKS RSA exponent is invalid.');
            }
            $exponent = $exponent * 256 + $octet;
        }
        if ($exponent < 3 || ($exponent & 1) !== 1) {
            throw new DiscoveryException('JWKS RSA exponent is invalid.');
        }
    }
}
