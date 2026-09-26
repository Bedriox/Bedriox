<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Spawn;

use Bedriox\Server\Entity\AbstractEntity;

final readonly class EntitySpawnOutcome
{
    private function __construct(
        public ?AbstractEntity $entity,
        public ?string $failure,
    ) {}

    public static function success(AbstractEntity $entity): self
    {
        return new self($entity, null);
    }

    public static function failed(string $failure): self
    {
        return new self(null, $failure);
    }

    public function succeeded(): bool
    {
        return $this->entity !== null;
    }
}
