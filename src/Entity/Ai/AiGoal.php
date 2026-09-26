<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Ai;

use Bedriox\Server\Entity\AbstractMobEntity;

/** Goal definitions are immutable and shared; per-entity state belongs in memory. */
interface AiGoal
{
    public function identifier(): string;

    public function priority(): int;

    public function evaluationIntervalTicks(): int;

    /** @return list<AiControl> */
    public function controls(): array;

    public function canStart(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool;

    public function shouldContinue(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool;

    public function start(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void;

    public function tick(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void;

    public function stop(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void;
}
