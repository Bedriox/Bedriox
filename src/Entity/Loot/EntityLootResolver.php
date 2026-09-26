<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Loot;

use Bedriox\Api\Entity\VanillaEntityType;
use InvalidArgumentException;

final class EntityLootResolver
{
    public const int MAX_TABLES = 512;

    /** @var array<string, LootTable> */
    private array $tables = [];

    /**
     * @param array<string, LootTable> $tables
     */
    public function __construct(
        private readonly LootRandomSource $random,
        private readonly LootOutputNormalizer $normalizer,
        array $tables = [],
    ) {
        foreach ($tables as $identifier => $table) {
            $this->register($identifier, $table);
        }
    }

    public static function vanilla(LootItemRegistry $items, ?LootRandomSource $random = null): self
    {
        return new self(
            $random ?? new SystemLootRandomSource(),
            new LootOutputNormalizer($items),
            [
                VanillaEntityType::ZOMBIE->value => new ZombieLootTable(),
                VanillaEntityType::COW->value => new CowLootTable(),
            ],
        );
    }

    public function register(string $entityIdentifier, LootTable $table, bool $replace = false): void
    {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $entityIdentifier) !== 1
            || strlen($entityIdentifier) > 128) {
            throw new InvalidArgumentException('Loot table entity identifier must be canonical and bounded.');
        }
        if (isset($this->tables[$entityIdentifier]) && !$replace) {
            throw new InvalidArgumentException('A loot table is already registered for this entity type.');
        }
        if (!isset($this->tables[$entityIdentifier]) && count($this->tables) >= self::MAX_TABLES) {
            throw new InvalidArgumentException('Loot table registry capacity is exhausted.');
        }
        $this->tables[$entityIdentifier] = $table;
    }

    public function prepare(LootContext $context): PreparedEntityLoot
    {
        return new PreparedEntityLoot(
            $this->tables[$context->entityType->identifier()] ?? new EmptyLootTable(),
            $context,
            $this->random,
            $this->normalizer,
        );
    }
}
