<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Spawn\Natural;

use Bedriox\Server\Entity\AbstractLivingEntity;
use InvalidArgumentException;

final readonly class WorldNaturalEntityTick
{
    /** @var list<AbstractLivingEntity> */
    private array $spawned;

    /** @var list<AbstractLivingEntity> */
    private array $removed;

    /**
     * @param array<int, mixed> $spawned
     * @param array<int, mixed> $removed
     */
    public function __construct(array $spawned = [], array $removed = [])
    {
        if (!array_is_list($spawned) || !array_is_list($removed)) {
            throw new InvalidArgumentException('Natural-entity lifecycle results must be ordered lists.');
        }
        $validatedSpawned = [];
        foreach ($spawned as $entity) {
            if (!$entity instanceof AbstractLivingEntity) {
                throw new InvalidArgumentException('Natural-entity lifecycle results contain an invalid entity.');
            }
            $validatedSpawned[] = $entity;
        }
        $validatedRemoved = [];
        foreach ($removed as $entity) {
            if (!$entity instanceof AbstractLivingEntity) {
                throw new InvalidArgumentException('Natural-entity lifecycle results contain an invalid entity.');
            }
            $validatedRemoved[] = $entity;
        }
        $this->spawned = $validatedSpawned;
        $this->removed = $validatedRemoved;
    }

    /** @return list<AbstractLivingEntity> */
    public function spawned(): array
    {
        return $this->spawned;
    }

    /** @return list<AbstractLivingEntity> */
    public function removed(): array
    {
        return $this->removed;
    }
}
