<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Ai;

use Bedriox\Server\Entity\AbstractMobEntity;

interface TargetAwareAiWorldView extends AiWorldView
{
    public function nearestPlayer(AbstractMobEntity $entity, float $radius): ?AiPlayerSnapshot;
}
