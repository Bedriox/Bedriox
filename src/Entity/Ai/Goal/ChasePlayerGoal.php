<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Ai\Goal;

use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiControl;
use Bedriox\Server\Entity\Ai\AiGoal;
use Bedriox\Server\Entity\Ai\AiMemoryStore;
use Bedriox\Server\Entity\Ai\AiPlayerSnapshot;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\HorizontalSteering;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use InvalidArgumentException;

final readonly class ChasePlayerGoal implements AiGoal
{
    public function __construct(
        private string $identifier,
        private int $priority,
        private float $stoppingDistance,
        private float $speed,
    ) {
        if (!is_finite($stoppingDistance) || $stoppingDistance <= 0.0 || $stoppingDistance > 16.0
            || !is_finite($speed) || $speed <= 0.0 || $speed > 1.0) {
            throw new InvalidArgumentException('Chase goal bounds are invalid.');
        }
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function evaluationIntervalTicks(): int
    {
        return 1;
    }

    public function controls(): array
    {
        return [AiControl::MOVE, AiControl::LOOK, AiControl::TARGET];
    }

    public function canStart(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        $target = $this->target($entity, $memory, $context);

        return $target !== null
            && $target->distanceSquaredTo($entity->internalPosition()) > $this->stoppingDistance ** 2;
    }

    public function shouldContinue(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        return $this->canStart($entity, $memory, $context);
    }

    public function start(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $this->steer($entity, $memory, $context);
    }

    public function tick(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $this->steer($entity, $memory, $context);
    }

    public function stop(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        HorizontalSteering::stop($entity, $context->tick);
    }

    private function steer(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $target = $this->target($entity, $memory, $context);
        if ($target !== null) {
            HorizontalSteering::toward($entity, $target->position, $this->speed, $context->tick);
        }
    }

    private function target(
        AbstractMobEntity $entity,
        AiMemoryStore $memory,
        AiTickContext $context,
    ): ?AiPlayerSnapshot {
        $target = $memory->get(VanillaAiMemories::nearestPlayer(), $context->tick);

        return $entity->isAlive() && $target instanceof AiPlayerSnapshot ? $target : null;
    }
}
