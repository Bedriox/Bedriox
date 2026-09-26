<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity;

use Bedriox\Api\Entity\EntityType;
use Bedriox\Data\EntityTypeRegistry;
use InvalidArgumentException;
use OverflowException;

/** Bounded registry of immutable definitions and factories. */
final class EntityDefinitionRegistry
{
    public const int MAXIMUM_DEFINITIONS = 512;

    /** @var array<string, RegisteredEntityDefinition> */
    private array $definitions = [];

    /** @param list<RegisteredEntityDefinition> $definitions */
    public function __construct(array $definitions = [])
    {
        foreach ($definitions as $definition) {
            $this->register($definition);
        }
    }

    public static function baseline(): self
    {
        return new self(VanillaEntityDefinitions::registrations());
    }

    public static function fromData(EntityTypeRegistry $catalog): self
    {
        return new self(VanillaEntityDefinitions::catalogRegistrations($catalog));
    }

    public function register(RegisteredEntityDefinition $registration, bool $replace = false): void
    {
        $identifier = $registration->definition->type->identifier();
        $existing = $this->definitions[$identifier] ?? null;
        if ($existing !== null && !$replace) {
            throw new InvalidArgumentException('Entity definition is already registered.');
        }
        if ($existing !== null && ($existing->owner === null
            || strcasecmp($existing->owner, $registration->owner ?? '') !== 0)) {
            throw new InvalidArgumentException('Entity definition is owned by another plugin.');
        }
        if ($existing === null && count($this->definitions) >= self::MAXIMUM_DEFINITIONS) {
            throw new OverflowException('Entity-definition registry capacity is exhausted.');
        }
        $this->definitions[$identifier] = $registration;
    }

    public function get(EntityType|string $type): ?RegisteredEntityDefinition
    {
        $identifier = $type instanceof EntityType ? $type->identifier() : $type;

        return $this->definitions[$identifier] ?? null;
    }

    public function require(EntityType|string $type): RegisteredEntityDefinition
    {
        return $this->get($type) ?? throw new InvalidArgumentException('Entity type is not implemented by this server.');
    }

    /** @return list<RegisteredEntityDefinition> */
    public function all(): array
    {
        $definitions = $this->definitions;
        ksort($definitions, SORT_STRING);

        return array_values($definitions);
    }

    public function unregisterOwned(string $identifier, string $owner): bool
    {
        $registration = $this->definitions[$identifier] ?? null;
        if ($registration?->owner === null || strcasecmp($registration->owner, $owner) !== 0) {
            return false;
        }
        unset($this->definitions[$identifier]);

        return true;
    }

    public function unregisterOwnedBy(string $owner): int
    {
        $removed = 0;
        foreach ($this->definitions as $identifier => $registration) {
            if ($registration->owner !== null && strcasecmp($registration->owner, $owner) === 0) {
                unset($this->definitions[$identifier]);
                ++$removed;
            }
        }

        return $removed;
    }
}
