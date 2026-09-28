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

namespace Bedriox\Server\Authentication;

use Bedriox\Protocol\Security\Base64Url;
use Bedriox\Server\Authentication\Discovery\KeyId;
use Bedriox\Server\Authentication\Discovery\RefreshRequestingJwkProvider;
use Firebase\JWT\JWK;
use OpenSSLAsymmetricKey;
use Throwable;

/** @internal */
final readonly class RsaJwkKeyResolver
{
    public function __construct(private JwkProvider $provider, private FullAuthenticationLimits $limits) {}

    public function resolve(string $wantedKid): OpenSSLAsymmetricKey
    {
        try {
            $keys = $this->provider->keys();
        } catch (Throwable) {
            throw new AuthenticationException('Trusted JWK snapshot is unavailable.');
        }
        if ($keys === [] || count($keys) > $this->limits->maximumJwkCount) {
            throw new AuthenticationException('Trusted JWK snapshot is invalid.');
        }
        $seen = [];
        $selected = null;
        foreach ($keys as $jwk) {
            $kid = $jwk['kid'] ?? null;
            if (!is_string($kid) || $kid === '' || strlen($kid) > $this->limits->maximumKidBytes
                || preg_match('/\A[A-Za-z0-9._~-]+\z/D', $kid) !== 1 || isset($seen[$kid])) {
                throw new AuthenticationException('Trusted JWK snapshot has invalid or duplicate key identifiers.');
            }
            $seen[$kid] = true;
            if ($kid === $wantedKid) {
                $selected = $this->validate($jwk);
            }
        }
        if ($selected === null) {
            if ($this->provider instanceof RefreshRequestingJwkProvider) {
                $this->provider->requestRefreshFor(new KeyId($wantedKid));
            }
            throw new AuthenticationException('FULL token key identifier is not trusted.');
        }
        try {
            $key = JWK::parseKey($selected, 'RS256');
            $material = $key?->getKeyMaterial();
        } catch (Throwable) {
            throw new AuthenticationException('Trusted RSA key cannot be imported.');
        }
        if (!$material instanceof OpenSSLAsymmetricKey) {
            throw new AuthenticationException('Trusted RSA key cannot be imported.');
        }
        $details = openssl_pkey_get_details($material);
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA
            || !is_int($details['bits'] ?? null) || $details['bits'] < 2048) {
            throw new AuthenticationException('Trusted RSA key is not a strong RSA public key.');
        }
        return $material;
    }

    /**
     * @param array<string, mixed> $jwk
     * @return array<string, string>
     */
    private function validate(array $jwk): array
    {
        if (($jwk['kty'] ?? null) !== 'RSA' || ($jwk['use'] ?? null) !== 'sig'
            || (array_key_exists('alg', $jwk) && $jwk['alg'] !== 'RS256')) {
            throw new AuthenticationException('Trusted JWK is not an RS256 signing key.');
        }
        foreach (['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth'] as $privateMember) {
            if (array_key_exists($privateMember, $jwk)) {
                throw new AuthenticationException('Trusted JWK contains private key material.');
            }
        }
        $encodedN = $jwk['n'] ?? null;
        $encodedE = $jwk['e'] ?? null;
        $n = $this->unsignedInteger($encodedN, $this->limits->maximumRsaModulusBytes, 'modulus');
        $e = $this->unsignedInteger($encodedE, $this->limits->maximumRsaExponentBytes, 'exponent');
        $first = ord($n[0]);
        $significantBits = (strlen($n) - 1) * 8 + (int) floor(log($first, 2)) + 1;
        if ($significantBits < 2048 || !$this->validExponent($e)) {
            throw new AuthenticationException('Trusted JWK RSA parameters are too weak.');
        }
        /** @var string $kid */
        $kid = $jwk['kid'];
        if (!is_string($encodedN) || !is_string($encodedE)) {
            throw new AuthenticationException('Trusted JWK RSA parameters are invalid.');
        }
        return ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $kid, 'n' => $encodedN, 'e' => $encodedE];
    }

    private function unsignedInteger(mixed $encoded, int $maximumBytes, string $name): string
    {
        if (!is_string($encoded) || $encoded === '') {
            throw new AuthenticationException("Trusted JWK {$name} is invalid.");
        }
        try {
            $bytes = Base64Url::decode($encoded, $maximumBytes);
        } catch (Throwable) {
            throw new AuthenticationException("Trusted JWK {$name} is invalid.");
        }
        if ($bytes === '' || $bytes[0] === "\0") {
            throw new AuthenticationException("Trusted JWK {$name} is not a canonical unsigned integer.");
        }
        return $bytes;
    }

    private function validExponent(string $bytes): bool
    {
        $value = 0;
        foreach (str_split($bytes) as $byte) {
            $octet = ord($byte);
            if ($value > intdiv(PHP_INT_MAX - $octet, 256)) {
                return false;
            }
            $value = $value * 256 + $octet;
        }
        return $value >= 3 && ($value & 1) === 1;
    }
}
