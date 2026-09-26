<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Ai\Sensor;

use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiMemoryStore;
use Bedriox\Server\Entity\Ai\AiSensor;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\TargetAwareAiWorldView;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use InvalidArgumentException;

final readonly class NearestPlayerSensor implements AiSensor
{
    public function __construct(
        private string $identifier,
        private int $intervalTicks,
        private float $radius,
        private int $memoryTicks,
    ) {
        if (!is_finite($radius) || $radius <= 0.0 || $radius > 128.0
            || $memoryTicks < $intervalTicks || $memoryTicks > 1_200) {
            throw new InvalidArgumentException('Nearest-player sensor bounds are invalid.');
        }
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function intervalTicks(): int
    {
        return $this->intervalTicks;
    }

    public function sense(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        if (!$context->world instanceof TargetAwareAiWorldView) {
            return;
        }
        $target = $context->world->nearestPlayer($entity, $this->radius);
        if ($target !== null) {
            $memory->put(VanillaAiMemories::nearestPlayer(), $target, $context->tick + $this->memoryTicks);
        }
    }
}
