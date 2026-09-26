<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Ai;

use Bedriox\Server\Entity\AbstractMobEntity;

/** Sensor instances must be stateless and safe to share between entities. */
interface AiSensor
{
    public function identifier(): string;

    public function intervalTicks(): int;

    public function sense(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void;
}
