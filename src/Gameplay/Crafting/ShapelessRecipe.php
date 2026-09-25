<?php

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Crafting;

use InvalidArgumentException;

final readonly class ShapelessRecipe implements CraftingRecipe
{
    /** @var list<RecipeIngredient> */
    private array $ingredients;

    /** @var list<RecipeOutput> */
    private array $results;

    /**
     * @param array<array-key, mixed> $ingredients
     * @param array<array-key, mixed> $outputs
     */
    public function __construct(
        private string $id,
        array $ingredients,
        array $outputs,
        private int $recipePriority = 0,
        private ?string $recipeOwner = null,
        private bool $preserveInputUserData = false,
    ) {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $id) !== 1) {
            throw new InvalidArgumentException('Recipe identifier must be canonical and namespaced.');
        }
        if (!array_is_list($ingredients) || $ingredients === [] || count($ingredients) > 9) {
            throw new InvalidArgumentException('Shapeless recipe ingredient count is outside its supported bounds.');
        }
        if (!array_is_list($outputs) || $outputs === [] || count($outputs) > 16) {
            throw new InvalidArgumentException('Shapeless recipe output count is outside its supported bounds.');
        }
        $validatedIngredients = [];
        foreach ($ingredients as $ingredient) {
            if (!$ingredient instanceof RecipeIngredient) {
                throw new InvalidArgumentException('Shapeless recipe contains an invalid ingredient.');
            }
            $validatedIngredients[] = $ingredient;
        }
        $validatedOutputs = [];
        foreach ($outputs as $output) {
            if (!$output instanceof RecipeOutput) {
                throw new InvalidArgumentException('Shapeless recipe contains an invalid output.');
            }
            $validatedOutputs[] = $output;
        }
        $this->ingredients = $validatedIngredients;
        $this->results = $validatedOutputs;
    }

    public function identifier(): string
    {
        return $this->id;
    }

    public function priority(): int
    {
        return $this->recipePriority;
    }

    public function owner(): ?string
    {
        return $this->recipeOwner;
    }

    public function outputs(): array
    {
        return $this->results;
    }

    public function ingredients(): array
    {
        return $this->ingredients;
    }

    public function outputsFor(CraftingGrid $grid): array
    {
        return $this->outputsForInputs(array_values($grid->occupiedSlots()));
    }

    public function outputsForInputs(array $inputs): array
    {
        if (!$this->preserveInputUserData) {
            return $this->results;
        }
        $source = null;
        foreach ($inputs as $stack) {
            if ($this->ingredients[0]->matches($stack)) {
                $source = $stack;
                break;
            }
        }
        if ($source === null) {
            return $this->results;
        }

        return $this->outputsWithUserData($source);
    }

    /** @return list<RecipeOutput> */
    private function outputsWithUserData(\Bedriox\Server\Player\InventoryStack $source): array
    {
        return array_map(
            static fn(RecipeOutput $output): RecipeOutput => new RecipeOutput(
                $output->identifier,
                $output->count,
                $output->damage,
                $source->nbt,
                $output->auxValue,
                $output->placedBlockState,
            ),
            $this->results,
        );
    }

    public function match(CraftingGrid $grid, int $repetitions = 1): ?CraftingRecipeMatch
    {
        if ($repetitions < 1 || $repetitions > 255) {
            return null;
        }
        $occupied = $grid->occupiedSlots();
        if (count($occupied) !== count($this->ingredients)) {
            return null;
        }

        /** @var array<int, list<int>> $candidates */
        $candidates = [];
        foreach ($this->ingredients as $ingredientIndex => $ingredient) {
            $candidates[$ingredientIndex] = [];
            foreach ($occupied as $slot => $stack) {
                if ($ingredient->accepts($stack, $repetitions)) {
                    $candidates[$ingredientIndex][] = $slot;
                }
            }
            if ($candidates[$ingredientIndex] === []) {
                return null;
            }
        }
        uksort($candidates, fn(int $left, int $right): int => count($candidates[$left]) <=> count($candidates[$right]));
        $assignment = self::assign($candidates, array_keys($candidates));
        if ($assignment === null) {
            return null;
        }
        $consumption = [];
        foreach ($assignment as $ingredientIndex => $slot) {
            $consumption[$slot] = $this->ingredients[$ingredientIndex]->count * $repetitions;
        }
        ksort($consumption);

        $outputs = $this->preserveInputUserData
            ? $this->outputsWithUserData($occupied[$assignment[0]])
            : $this->results;

        return new CraftingRecipeMatch($this->id, $repetitions, $consumption, $outputs);
    }

    /**
     * @param array<int, list<int>> $candidates
     * @param list<int> $remainingIngredients
     * @param array<int, int> $assigned
     * @param array<int, true> $usedSlots
     * @return null|array<int, int>
     */
    private static function assign(
        array $candidates,
        array $remainingIngredients,
        array $assigned = [],
        array $usedSlots = [],
    ): ?array {
        if ($remainingIngredients === []) {
            return $assigned;
        }
        $ingredient = array_shift($remainingIngredients);
        foreach ($candidates[$ingredient] as $slot) {
            if (isset($usedSlots[$slot])) {
                continue;
            }
            $nextAssigned = $assigned;
            $nextAssigned[$ingredient] = $slot;
            $nextUsed = $usedSlots;
            $nextUsed[$slot] = true;
            $result = self::assign($candidates, $remainingIngredients, $nextAssigned, $nextUsed);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }
}
