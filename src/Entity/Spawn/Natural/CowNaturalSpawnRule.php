<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Spawn\Natural;

/** Conservative overworld surface admission for the first naturally spawning animal. */
final readonly class CowNaturalSpawnRule implements NaturalSpawnRule
{
    public function allows(NaturalSpawnContext $context): bool
    {
        if ($context->medium !== NaturalSpawnMedium::GROUND || $context->lightLevel < 9) {
            return false;
        }

        foreach (['ocean', 'river', 'beach', 'desert', 'peak', 'slope'] as $excluded) {
            if (str_contains($context->biome, $excluded)) {
                return false;
            }
        }

        return true;
    }
}
