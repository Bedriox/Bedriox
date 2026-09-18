<?php

declare(strict_types=1);

namespace Bedriox\Server\Authentication\Discovery;

use InvalidArgumentException;

final readonly class KeyId
{
    public function __construct(public string $value)
    {
        if ($value === '' || strlen($value) > 128 || preg_match('/\A[A-Za-z0-9._~-]+\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Key identifier is invalid.');
        }
    }
}
