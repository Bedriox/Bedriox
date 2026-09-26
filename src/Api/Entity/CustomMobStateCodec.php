<?php

declare(strict_types=1);

namespace Bedriox\Api\Entity;

interface CustomMobStateCodec
{
    public function encode(CustomMobBehavior $behavior): CustomEntityState;

    public function restore(CustomMobBehavior $behavior, CustomEntityState $state): void;
}
