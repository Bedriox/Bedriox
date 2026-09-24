<?php

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Item;

use InvalidArgumentException;

/** Mutable bounded behavior registry; canonical inventory identity remains owned by ItemCatalog. */
final class ItemBehaviorRegistry
{
    public const int MAXIMUM_DEFINITIONS = 5_000;

    /** @var array<string, ItemUseBehavior> */
    private array $behaviors = [];

    /** @var array<string, ItemUseBehavior> */
    private array $builtIns = [];

    /** @param list<ItemUseBehavior> $behaviors */
    public function __construct(array $behaviors = [])
    {
        foreach ($behaviors as $behavior) {
            $this->register($behavior);
        }
    }

    public static function vanilla(): self
    {
        /** @var list<array{string, float, float, 3?: string}> $ordinary */
        $ordinary = [
            ['minecraft:apple', 4.0, 2.4],
            ['minecraft:baked_potato', 5.0, 7.2],
            ['minecraft:beetroot', 1.0, 1.2],
            ['minecraft:beetroot_soup', 6.0, 7.2, 'minecraft:bowl'],
            ['minecraft:bread', 5.0, 6.0],
            ['minecraft:carrot', 3.0, 4.8],
            ['minecraft:cooked_beef', 8.0, 12.8],
            ['minecraft:cooked_chicken', 6.0, 7.2],
            ['minecraft:cooked_cod', 5.0, 6.0],
            ['minecraft:cooked_mutton', 6.0, 9.6],
            ['minecraft:cooked_porkchop', 8.0, 12.8],
            ['minecraft:cooked_rabbit', 5.0, 6.0],
            ['minecraft:cooked_salmon', 6.0, 9.6],
            ['minecraft:cookie', 2.0, 0.4],
            ['minecraft:dried_kelp', 1.0, 0.6],
            ['minecraft:glow_berries', 2.0, 0.4],
            ['minecraft:golden_carrot', 6.0, 14.4],
            ['minecraft:honey_bottle', 6.0, 1.2, 'minecraft:glass_bottle'],
            ['minecraft:melon_slice', 2.0, 1.2],
            ['minecraft:mushroom_stew', 6.0, 7.2, 'minecraft:bowl'],
            ['minecraft:potato', 1.0, 0.6],
            ['minecraft:pumpkin_pie', 8.0, 4.8],
            ['minecraft:rabbit_stew', 10.0, 12.0, 'minecraft:bowl'],
            ['minecraft:beef', 3.0, 1.8],
            ['minecraft:chicken', 2.0, 1.2],
            ['minecraft:cod', 2.0, 0.4],
            ['minecraft:mutton', 2.0, 1.2],
            ['minecraft:porkchop', 3.0, 1.8],
            ['minecraft:rabbit', 3.0, 1.8],
            ['minecraft:salmon', 2.0, 0.4],
            ['minecraft:sweet_berries', 2.0, 0.4],
            ['minecraft:tropical_fish', 1.0, 0.2],
        ];
        $behaviors = [];
        foreach ($ordinary as $entry) {
            [$identifier, $food, $saturation] = $entry;
            $residue = $entry[3] ?? null;
            $behaviors[] = new ItemUseBehavior(
                $identifier,
                $identifier === 'minecraft:dried_kelp' ? 16 : 32,
                new ConsumableDefinition($food, $saturation, residueIdentifier: $residue ?? null),
            );
        }
        foreach (['minecraft:golden_apple', 'minecraft:enchanted_golden_apple'] as $identifier) {
            $behaviors[] = new ItemUseBehavior(
                $identifier,
                32,
                new ConsumableDefinition(4.0, 9.6, false),
            );
        }

        return new self($behaviors);
    }

    public function behavior(string $identifier): ?ItemUseBehavior
    {
        return $this->behaviors[$identifier] ?? null;
    }

    public function register(ItemUseBehavior $behavior, bool $replace = false): void
    {
        if ($behavior->owner !== null) {
            $this->registerOwned($behavior, $replace);

            return;
        }
        if (isset($this->behaviors[$behavior->identifier]) && !$replace) {
            throw new InvalidArgumentException('Item behavior is already registered.');
        }
        if (($this->behaviors[$behavior->identifier]->owner ?? null) !== null) {
            throw new InvalidArgumentException('A built-in behavior cannot replace an active plugin-owned behavior.');
        }
        if (!isset($this->behaviors[$behavior->identifier])
            && count($this->behaviors) >= self::MAXIMUM_DEFINITIONS) {
            throw new InvalidArgumentException('Item behavior registry capacity is exhausted.');
        }
        $this->behaviors[$behavior->identifier] = $behavior;
        $this->builtIns[$behavior->identifier] = $behavior;
    }

    public function registerOwned(ItemUseBehavior $behavior, bool $replace = false): void
    {
        if ($behavior->owner === null) {
            throw new InvalidArgumentException('Plugin-owned item behavior requires an owner.');
        }
        $existing = $this->behaviors[$behavior->identifier] ?? null;
        if ($existing !== null && !$replace) {
            throw new InvalidArgumentException('Item behavior is already registered.');
        }
        if ($existing?->owner !== null && strcasecmp($existing->owner, $behavior->owner) !== 0) {
            throw new InvalidArgumentException('Item behavior is owned by another plugin.');
        }
        if ($existing === null && count($this->behaviors) >= self::MAXIMUM_DEFINITIONS) {
            throw new InvalidArgumentException('Item behavior registry capacity is exhausted.');
        }
        $this->behaviors[$behavior->identifier] = $behavior;
    }

    public function unregisterOwnedBy(string $owner): int
    {
        $removed = 0;
        foreach ($this->behaviors as $identifier => $behavior) {
            if ($behavior->owner === null || strcasecmp($behavior->owner, $owner) !== 0) {
                continue;
            }
            if (isset($this->builtIns[$identifier])) {
                $this->behaviors[$identifier] = $this->builtIns[$identifier];
            } else {
                unset($this->behaviors[$identifier]);
            }
            ++$removed;
        }

        return $removed;
    }

    public function unregisterOwned(string $identifier, string $owner): bool
    {
        $behavior = $this->behaviors[$identifier] ?? null;
        if ($behavior?->owner === null || strcasecmp($behavior->owner, $owner) !== 0) {
            return false;
        }
        if (isset($this->builtIns[$identifier])) {
            $this->behaviors[$identifier] = $this->builtIns[$identifier];
        } else {
            unset($this->behaviors[$identifier]);
        }

        return true;
    }

    /** @return list<ItemUseBehavior> */
    public function all(): array
    {
        return array_values($this->behaviors);
    }
}
