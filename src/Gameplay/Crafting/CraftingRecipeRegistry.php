<?php

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Crafting;

use InvalidArgumentException;
use OverflowException;

/** Mutable bounded registry with deterministic initial and append-stable network IDs. */
final class CraftingRecipeRegistry
{
    public const int MAXIMUM_RECIPES = 16_384;

    private const int MAXIMUM_NETWORK_ID = 2_147_483_647;

    /** @var array<string, CraftingRecipe> */
    private array $recipes = [];

    /** @var array<string, CraftingRecipe> */
    private array $builtIns = [];

    /** @var array<int, CraftingRecipe> */
    private array $networkIndex = [];

    /** @var array<string, int> */
    private array $networkIds = [];

    /** @var array<string, list<int>> Additional wire IDs for expanded ingredient alternatives. */
    private array $networkAliases = [];

    private int $revision = 0;

    private bool $deferRebuild = false;

    private int $nextNetworkId = 1;

    /** @param list<CraftingRecipe> $recipes */
    public function __construct(array $recipes = [])
    {
        $this->deferRebuild = true;
        foreach ($recipes as $recipe) {
            $this->register($recipe);
        }
        $this->deferRebuild = false;
        if ($recipes !== []) {
            $this->buildInitialIndex();
        }
    }

    public function register(CraftingRecipe $recipe, bool $replace = false, int $networkAliasCount = 0): void
    {
        if ($networkAliasCount < 0 || $networkAliasCount >= self::MAXIMUM_NETWORK_ID) {
            throw new InvalidArgumentException('Crafting recipe network alias count is outside its supported range.');
        }
        $identifier = $recipe->identifier();
        $existing = $this->recipes[$identifier] ?? null;
        if ($existing !== null && !$replace) {
            throw new InvalidArgumentException('Crafting recipe is already registered.');
        }
        if ($existing?->owner() !== null && $recipe->owner() === null) {
            throw new InvalidArgumentException('A built-in recipe cannot replace an active plugin recipe.');
        }
        if ($existing !== null && $existing->owner() !== null && $recipe->owner() !== null
            && strcasecmp($existing->owner(), $recipe->owner()) !== 0) {
            throw new InvalidArgumentException('Crafting recipe is owned by another plugin.');
        }
        if ($existing === null && count($this->recipes) >= self::MAXIMUM_RECIPES) {
            throw new InvalidArgumentException('Crafting recipe registry capacity is exhausted.');
        }
        if ($this->deferRebuild) {
            $this->recipes[$identifier] = $recipe;
            if ($recipe->owner() === null) {
                $this->builtIns[$identifier] = $recipe;
            }
            return;
        }

        $networkIds = $this->networkIdsFor($identifier);
        $requiredNetworkIds = $networkAliasCount + 1;
        $additionalNetworkIds = max(0, $requiredNetworkIds - count($networkIds));
        if ($additionalNetworkIds > self::MAXIMUM_NETWORK_ID - $this->nextNetworkId + 1) {
            throw new OverflowException('Crafting recipe network ID space is exhausted.');
        }
        $this->recipes[$identifier] = $recipe;
        if ($recipe->owner() === null) {
            $this->builtIns[$identifier] = $recipe;
        }
        if ($networkIds === []) {
            $networkIds[] = $this->allocateNetworkId();
            $this->networkIds[$identifier] = $networkIds[0];
            --$additionalNetworkIds;
        }
        while ($additionalNetworkIds-- > 0) {
            $networkIds[] = $this->allocateNetworkId();
        }
        while (count($networkIds) > $requiredNetworkIds) {
            $removed = array_pop($networkIds);
            unset($this->networkIndex[$removed]);
        }
        $aliases = array_slice($networkIds, 1);
        if ($aliases === []) {
            unset($this->networkAliases[$identifier]);
        } else {
            $this->networkAliases[$identifier] = $aliases;
        }
        foreach ($networkIds as $networkId) {
            $this->networkIndex[$networkId] = $recipe;
        }
        $this->advanceRevision();
    }

    public function unregisterOwnedBy(string $owner): int
    {
        $removed = 0;
        foreach ($this->recipes as $identifier => $recipe) {
            if ($recipe->owner() === null || strcasecmp($recipe->owner(), $owner) !== 0) {
                continue;
            }
            if (isset($this->builtIns[$identifier])) {
                $this->recipes[$identifier] = $this->builtIns[$identifier];
                foreach ($this->networkIdsFor($identifier) as $networkId) {
                    $this->networkIndex[$networkId] = $this->builtIns[$identifier];
                }
            } else {
                unset($this->recipes[$identifier]);
                $networkIds = $this->networkIdsFor($identifier);
                if ($networkIds === []) {
                    throw new \LogicException('A registered recipe has no network identity.');
                }
                unset($this->networkIds[$identifier], $this->networkAliases[$identifier]);
                foreach ($networkIds as $networkId) {
                    unset($this->networkIndex[$networkId]);
                }
            }
            ++$removed;
        }
        if ($removed > 0) {
            $this->advanceRevision();
        }

        return $removed;
    }

    public function recipe(string $identifier): ?CraftingRecipe
    {
        return $this->recipes[$identifier] ?? null;
    }

    public function recipeByNetworkId(int $networkId): ?CraftingRecipe
    {
        return $this->networkIndex[$networkId] ?? null;
    }

    public function networkId(string $identifier): ?int
    {
        return $this->networkIds[$identifier] ?? null;
    }

    /** @return list<int> */
    public function networkIdsFor(string $identifier): array
    {
        $primary = $this->networkIds[$identifier] ?? null;
        if ($primary === null) {
            return [];
        }

        return [$primary, ...($this->networkAliases[$identifier] ?? [])];
    }

    /** @return list<CraftingRecipe> */
    public function all(): array
    {
        return array_values($this->recipes);
    }

    public function revision(): int
    {
        return $this->revision;
    }

    /** Reserves IDs owned by non-registry recipe families in the same wire catalog. */
    public function reserveNetworkIds(int $count): void
    {
        if ($count < 0 || $this->nextNetworkId > self::MAXIMUM_NETWORK_ID - $count) {
            throw new OverflowException('Crafting recipe network ID space is exhausted.');
        }
        $this->nextNetworkId += $count;
    }

    private function buildInitialIndex(): void
    {
        $recipes = array_values($this->recipes);
        usort($recipes, static fn(CraftingRecipe $left, CraftingRecipe $right): int =>
            $right->priority() <=> $left->priority() ?: strcmp($left->identifier(), $right->identifier()));
        $this->networkIndex = [];
        $this->networkIds = [];
        $this->networkAliases = [];
        foreach ($recipes as $recipe) {
            $networkId = $this->allocateNetworkId();
            $this->networkIndex[$networkId] = $recipe;
            $this->networkIds[$recipe->identifier()] = $networkId;
        }
        $this->advanceRevision();
    }

    private function allocateNetworkId(): int
    {
        if ($this->nextNetworkId > self::MAXIMUM_NETWORK_ID) {
            throw new OverflowException('Crafting recipe network ID space is exhausted.');
        }

        return $this->nextNetworkId++;
    }

    private function advanceRevision(): void
    {
        if ($this->revision === PHP_INT_MAX) {
            throw new OverflowException('Crafting recipe registry revision space is exhausted.');
        }
        ++$this->revision;
    }
}
