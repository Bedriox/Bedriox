<?php

declare(strict_types=1);

namespace Bedriox\Api\Entity;

use InvalidArgumentException;

final readonly class CustomEntityType implements EntityType
{
    public function __construct(private string $id)
    {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $id) !== 1) {
            throw new InvalidArgumentException('Custom entity type must be canonical and namespaced.');
        }
        if (str_starts_with($id, 'minecraft:')) {
            throw new InvalidArgumentException('The minecraft namespace is reserved for vanilla entity types.');
        }
        if (strlen($id) > 128) {
            throw new InvalidArgumentException('Custom entity type exceeds its supported length.');
        }
    }

    public function identifier(): string
    {
        return $this->id;
    }
}
