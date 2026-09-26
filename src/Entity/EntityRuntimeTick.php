<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity;

use Bedriox\Server\Entity\Ai\AiSchedulerMetrics;

final readonly class EntityRuntimeTick
{
    /** @var array<int, bool> */
    private array $motionChanged;

    /**
     * @param list<AbstractEntity> $moved
     * @param list<AbstractLivingEntity> $died
     * @param array<int, bool> $motionChanged Runtime ID => changed motion for moved entities
     */
    public function __construct(
        public array $moved,
        public array $died,
        public AiSchedulerMetrics $ai,
        public EntityRuntimeMetrics $runtime,
        array $motionChanged = [],
    ) {
        $this->motionChanged = $motionChanged;
    }

    public function motionChangedFor(int $runtimeId): bool
    {
        return $this->motionChanged[$runtimeId] ?? false;
    }
}
