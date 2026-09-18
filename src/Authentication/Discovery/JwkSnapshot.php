<?php

declare(strict_types=1);

namespace Bedriox\Server\Authentication\Discovery;

final readonly class JwkSnapshot
{
    /** @param list<array<string, mixed>> $keys */
    public function __construct(public array $keys, public int $refreshedAt, public int $hardExpiresAt) {}

    public function contains(KeyId $keyId): bool
    {
        foreach ($this->keys as $key) {
            if (($key['kid'] ?? null) === $keyId->value) {
                return true;
            }
        }
        return false;
    }
}
