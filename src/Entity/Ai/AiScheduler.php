<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Ai;

use Bedriox\Api\Entity\MobActivationState;
use Bedriox\Server\Entity\AbstractMobEntity;
use Closure;

/** Fair bounded AI dispatcher. Physics remains outside this scheduler. */
final class AiScheduler
{
    private int $cursor = 0;

    public function __construct(
        private readonly AiClock $clock = new SystemAiClock(),
        private readonly MobActivationPolicy $activation = new MobActivationPolicy(),
    ) {}

    /**
     * @param list<AbstractMobEntity> $entities
     * @param null|Closure(AbstractMobEntity, AiTickContext): void $afterScheduledTick
     */
    public function tick(
        array $entities,
        AiTickContext $context,
        bool $enabled,
        AiWorkBudget $budget = new AiWorkBudget(),
        ?Closure $afterScheduledTick = null,
    ): AiSchedulerMetrics {
        if ($entities === []) {
            return new AiSchedulerMetrics(0, 0, 0, 0, 0, 0, 0, 0, 0, false);
        }

        $start = $this->clock->nanoseconds();
        $count = count($entities);
        for ($index = 1; $index < $count; ++$index) {
            if ($entities[$index - 1]->getRuntimeId() <= $entities[$index]->getRuntimeId()) {
                continue;
            }
            usort($entities, static fn(AbstractMobEntity $left, AbstractMobEntity $right): int =>
                $left->getRuntimeId() <=> $right->getRuntimeId());
            break;
        }
        $considered = $ticked = $active = $reduced = $sleeping = 0;
        $sensors = $evaluated = $goals = 0;
        $exhausted = false;

        for ($offset = 0; $offset < $count && $considered < $budget->maximumEntities; ++$offset) {
            if ($offset > 0 && ($offset % 16) === 0
                && $this->clock->nanoseconds() - $start >= $budget->maximumNanoseconds) {
                $exhausted = true;
                break;
            }
            $index = ($this->cursor + $offset) % $count;
            $entity = $entities[$index];
            ++$considered;
            $state = $this->activation->classify(
                $entity,
                $context->world->nearestPlayerDistanceSquared($entity),
            );
            $entity->setActivationState($state, $context);
            match ($state) {
                MobActivationState::ACTIVE, MobActivationState::FORCED => ++$active,
                MobActivationState::REDUCED => ++$reduced,
                MobActivationState::SLEEPING => ++$sleeping,
            };
            if ($state === MobActivationState::SLEEPING
                || ($state === MobActivationState::REDUCED
                    && ($context->tick + $entity->getRuntimeId()) % 5 !== 0)) {
                continue;
            }
            $result = $entity->tickAi($context, $enabled);
            if ($enabled) {
                $afterScheduledTick?->__invoke($entity, $context);
            }
            ++$ticked;
            $sensors += $result->sensorsRun;
            $evaluated += $result->goalsEvaluated;
            $goals += $result->goalsTicked;
        }

        $this->cursor = ($this->cursor + max(1, $considered)) % $count;
        $elapsed = max(0, $this->clock->nanoseconds() - $start);
        $exhausted = $exhausted || $considered < $count;

        return new AiSchedulerMetrics(
            $considered,
            $ticked,
            $active,
            $reduced,
            $sleeping,
            $sensors,
            $evaluated,
            $goals,
            $elapsed,
            $exhausted,
        );
    }
}
