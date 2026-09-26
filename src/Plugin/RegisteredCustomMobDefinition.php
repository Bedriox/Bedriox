<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin;

use Bedriox\Api\Entity\CustomMobDefinition;

/** @internal */
final readonly class RegisteredCustomMobDefinition
{
    public function __construct(
        public string $owner,
        public CustomMobDefinition $definition,
        public int $ownerEpoch,
        public int $generation,
    ) {}
}
