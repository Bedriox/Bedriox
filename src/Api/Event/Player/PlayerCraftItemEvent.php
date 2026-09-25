<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Crafting\CraftingGrid;
use Bedriox\Api\Crafting\CraftingRecipe;
use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;
use InvalidArgumentException;

/** Cancellable craft intent emitted after authoritative validation and before inventory mutation. */
final class PlayerCraftItemEvent extends CancellableEvent
{
    /** @var list<ItemStack> */
    public readonly array $consumedInputs;

    /** @var list<ItemStack> */
    public readonly array $originalOutputs;

    /** @var list<ItemStack> */
    public readonly array $remainders;

    /** @var list<ItemStack> */
    private array $currentOutputs;

    /**
     * @param list<ItemStack> $consumedInputs
     * @param list<ItemStack> $outputs one-repetition authoritative outputs
     * @param list<ItemStack> $remainders one-repetition authoritative remainders
     */
    public function __construct(
        public readonly Player $player,
        public readonly CraftingRecipe $recipe,
        public readonly CraftingGrid $grid,
        public readonly int $craftCount,
        array $consumedInputs,
        array $outputs,
        array $remainders = [],
    ) {
        if ($craftCount < 1 || $craftCount > 64) {
            throw new InvalidArgumentException('Craft count must be between one and 64.');
        }
        $this->consumedInputs = self::stacks($consumedInputs, 9, false, 'consumed input');
        $this->originalOutputs = self::stacks($outputs, 4, false, 'craft output');
        $this->currentOutputs = $this->originalOutputs;
        $this->remainders = self::stacks($remainders, 4, true, 'craft remainder');
    }

    /** @return list<ItemStack> */
    public function outputs(): array
    {
        return $this->currentOutputs;
    }

    /**
     * Replaces one-repetition outputs without increasing the validated stack or item-count budget.
     * The authoritative simulation revalidates item admission and inventory capacity after dispatch.
     *
     * @param list<ItemStack> $outputs
     */
    public function setOutputs(array $outputs): void
    {
        $this->assertMutable();
        $validated = self::stacks($outputs, count($this->originalOutputs), false, 'craft output');
        if (self::totalCount($validated) > self::totalCount($this->originalOutputs)) {
            throw new InvalidArgumentException('Craft output replacement exceeds the authoritative item-count budget.');
        }
        $this->currentOutputs = $validated;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->currentOutputs];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !is_array($state[1])) {
            throw new InvalidArgumentException('Invalid player craft event state.');
        }
        $outputs = self::stacks($state[1], count($this->originalOutputs), false, 'craft output');
        if (self::totalCount($outputs) > self::totalCount($this->originalOutputs)) {
            throw new InvalidArgumentException('Invalid player craft event output budget.');
        }
        parent::replaceState($state[0]);
        $this->currentOutputs = $outputs;
    }

    /**
     * @param array<array-key, mixed> $stacks
     * @return list<ItemStack>
     */
    private static function stacks(array $stacks, int $maximum, bool $emptyAllowed, string $label): array
    {
        if (!array_is_list($stacks) || (!$emptyAllowed && $stacks === []) || count($stacks) > $maximum) {
            throw new InvalidArgumentException(ucfirst($label) . ' stack count is outside its supported bounds.');
        }
        $validated = [];
        foreach ($stacks as $stack) {
            if (!$stack instanceof ItemStack) {
                throw new InvalidArgumentException(ucfirst($label) . ' contains an invalid stack.');
            }
            $validated[] = $stack;
        }

        return $validated;
    }

    /** @param list<ItemStack> $stacks */
    private static function totalCount(array $stacks): int
    {
        return array_sum(array_map(static fn(ItemStack $stack): int => $stack->count, $stacks));
    }
}
