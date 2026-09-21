<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

use Bedriox\Api\TranslatableMessage;

final readonly class DeathPresentation
{
    public function __construct(
        public string|TranslatableMessage|null $deathMessage,
        public string|TranslatableMessage|null $deathScreenMessage,
    ) {}
}
