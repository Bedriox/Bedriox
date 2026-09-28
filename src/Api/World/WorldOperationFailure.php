<?php

declare(strict_types=1);

namespace Bedriox\Api\World;

use InvalidArgumentException;

/** Bounded, public failure information which never exposes an internal exception. */
final readonly class WorldOperationFailure
{
    public function __construct(
        public WorldOperationFailureCode $code,
        public string $message,
    ) {
        if ($message === '' || strlen($message) > 512 || preg_match('//u', $message) !== 1
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $message) === 1) {
            throw new InvalidArgumentException('World operation failure message must be bounded safe UTF-8.');
        }
    }
}
