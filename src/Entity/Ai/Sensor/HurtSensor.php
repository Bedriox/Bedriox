<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Ai\Sensor;

use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiMemoryStore;
use Bedriox\Server\Entity\Ai\AiSensor;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use InvalidArgumentException;

final readonly class HurtSensor implements AiSensor
{
    public function __construct(
        private string $identifier,
        private int $memoryTicks,
    ) {
        if ($memoryTicks < 1 || $memoryTicks > 1_200) {
            throw new InvalidArgumentException('Hurt sensor memory duration is outside its supported bounds.');
        }
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function intervalTicks(): int
    {
        return 1;
    }

    public function sense(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $previous = $memory->get(VanillaAiMemories::lastObservedHealth(), $context->tick);
        $health = $entity->getHealth();
        if (is_float($previous) && $health < $previous) {
            $memory->put(VanillaAiMemories::hurt(), true, $context->tick + $this->memoryTicks);
        }
        $memory->put(VanillaAiMemories::lastObservedHealth(), $health);
    }
}
