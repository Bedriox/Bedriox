<?php

declare(strict_types=1);

namespace Bedriox\Api\Entity;

use InvalidArgumentException;

final class EmptyCustomMobStateCodec implements CustomMobStateCodec
{
    public function encode(CustomMobBehavior $behavior): CustomEntityState
    {
        return new CustomEntityState(1, '');
    }

    public function restore(CustomMobBehavior $behavior, CustomEntityState $state): void
    {
        if ($state->schemaVersion !== 1 || $state->size() !== 0) {
            throw new InvalidArgumentException('This custom mob does not support persistent plugin state.');
        }
    }
}
