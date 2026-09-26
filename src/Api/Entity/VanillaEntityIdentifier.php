<?php

declare(strict_types=1);

namespace Bedriox\Api\Entity;

use InvalidArgumentException;

/** A current-version vanilla identity admitted by the immutable Data catalog. */
final readonly class VanillaEntityIdentifier implements VanillaEntityIdentity
{
    public function __construct(private string $id)
    {
        if (preg_match('/^minecraft:[a-z0-9_.\/-]+$/D', $id) !== 1 || strlen($id) > 128) {
            throw new InvalidArgumentException('Vanilla entity type must be a canonical minecraft identifier.');
        }
    }

    public function identifier(): string
    {
        return $this->id;
    }
}
