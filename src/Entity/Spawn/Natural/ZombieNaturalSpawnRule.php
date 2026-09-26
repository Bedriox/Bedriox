<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Spawn\Natural;

/** First hostile surface rule; candidates remain rejected while sky light is above the hostile threshold. */
final readonly class ZombieNaturalSpawnRule implements NaturalSpawnRule
{
    public function allows(NaturalSpawnContext $context): bool
    {
        return $context->medium === NaturalSpawnMedium::GROUND && $context->lightLevel <= 7;
    }
}
