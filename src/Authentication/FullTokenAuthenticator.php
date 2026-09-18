<?php

declare(strict_types=1);

namespace Bedriox\Server\Authentication;

use Bedriox\Protocol\Identity\ClientDataJwtVerifier;
use Bedriox\Protocol\Packet\AuthenticationType;
use Bedriox\Protocol\Packet\LoginAuthentication;
use Bedriox\Protocol\Security\P384;
use Bedriox\Protocol\Security\SecurityLimits;
use Throwable;

final readonly class FullTokenAuthenticator
{
    public const string ISSUER = 'https://authorization.franchise.minecraft-services.net/';
    public const string AUDIENCE = 'api://auth-minecraft-services/multiplayer';

    private FullAuthenticationLimits $limits;
    private BoundedFullTokenParser $parser;
    private RsaJwkKeyResolver $keys;

    public function __construct(
        JwkProvider $provider,
        private AuthenticationClock $clock,
        private ClientDataJwtVerifier $clientDataVerifier,
        ?FullAuthenticationLimits $limits = null,
    ) {
        $this->limits = $limits ?? new FullAuthenticationLimits();
        $this->parser = new BoundedFullTokenParser($this->limits);
        $this->keys = new RsaJwkKeyResolver($provider, $this->limits);
    }

    public function authenticate(
        LoginAuthentication $authentication,
        #[\SensitiveParameter]
        string $clientDataJwt,
    ): AuthenticatedIdentity {
        if ($authentication->type !== AuthenticationType::Full || $authentication->token === null) {
            throw new AuthenticationException('FULL Token authentication is required.');
        }
        $token = $this->parser->parse($authentication->token);
        $key = $this->keys->resolve($token->kid);
        $details = openssl_pkey_get_details($key);
        $signatureBytes = is_array($details) && is_int($details['bits'] ?? null) ? intdiv($details['bits'] + 7, 8) : 0;
        if (strlen($token->signature) !== $signatureBytes) {
            throw new AuthenticationException('FULL token signature length is invalid.');
        }
        $verified = openssl_verify($token->signingInput, $token->signature, $key, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            throw new AuthenticationException('FULL token signature is invalid.');
        }

        $claims = $token->claims;
        if (($claims['iss'] ?? null) !== self::ISSUER || ($claims['aud'] ?? null) !== self::AUDIENCE) {
            throw new AuthenticationException('FULL token issuer or audience is invalid.');
        }
        $now = $this->clock->nowEpochSeconds();
        if ($now < 0) {
            throw new AuthenticationException('Authentication clock returned an invalid time.');
        }
        $exp = $this->requiredInteger($claims, 'exp');
        $sub = $this->requiredText($claims, 'sub', $this->limits->maximumSubjectBytes);
        if ($exp <= $now - $this->limits->maximumClockSkewSeconds) {
            throw new AuthenticationException('FULL token is expired.');
        }
        foreach (['nbf', 'iat'] as $timeClaim) {
            if (array_key_exists($timeClaim, $claims)
                && $this->requiredInteger($claims, $timeClaim) > $now + $this->limits->maximumClockSkewSeconds) {
                throw new AuthenticationException('FULL token is not yet valid.');
            }
        }
        $cpk = $this->requiredText($claims, 'cpk', $this->limits->maximumCpkBytes);
        $displayName = $this->requiredText($claims, 'xname', $this->limits->maximumDisplayNameBytes);
        $xuid = $this->requiredText($claims, 'xid', $this->limits->maximumXuidBytes);
        if (preg_match('/\A[0-9]+\z/D', $xuid) !== 1) {
            throw new AuthenticationException('FULL token xid is invalid.');
        }
        $minecraftId = array_key_exists('mid', $claims)
            ? $this->requiredText($claims, 'mid', $this->limits->maximumMinecraftIdBytes)
            : null;
        try {
            $clientKey = P384::importPublicDerBase64($cpk, new SecurityLimits(maximumSpkiDerBytes: $this->limits->maximumCpkBytes));
            $clientData = $this->clientDataVerifier->verify($clientDataJwt, $clientKey);
        } catch (Throwable) {
            throw new AuthenticationException('Client-data proof is invalid.');
        }
        return new AuthenticatedIdentity($sub, $displayName, $xuid, $minecraftId, $clientKey, $clientData);
    }

    /** @param array<string, mixed> $claims */
    private function requiredInteger(array $claims, string $name): int
    {
        $value = $claims[$name] ?? null;
        if (!is_int($value) || $value < 0) {
            throw new AuthenticationException("FULL token {$name} claim is invalid.");
        }
        return $value;
    }

    /** @param array<string, mixed> $claims */
    private function requiredText(array $claims, string $name, int $maximumBytes): string
    {
        $value = $claims[$name] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > $maximumBytes || preg_match('//u', $value) !== 1) {
            throw new AuthenticationException("FULL token {$name} claim is invalid.");
        }
        return $value;
    }
}
