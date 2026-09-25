<?php

declare(strict_types=1);

namespace Bedriox\Api\Crafting;

use Bedriox\Api\Inventory\ItemStack;
use InvalidArgumentException;

final readonly class ShapedRecipe implements CraftingRecipe
{
    /** @var list<RecipeIngredient|null> */
    public array $ingredients;

    /** @var list<ItemStack> */
    private array $results;

    /**
     * @param array<array-key, mixed> $ingredients RecipeIngredient|null values in a row-major pattern.
     * @param array<array-key, mixed> $outputs ItemStack values.
     */
    public function __construct(
        private string $id,
        public int $width,
        public int $height,
        array $ingredients,
        array $outputs,
        private int $recipePriority = 0,
        public bool $allowMirror = true,
    ) {
        self::validateIdentifier($id);
        if (!array_is_list($ingredients) || $width < 1 || $width > 3
            || $height < 1 || $height > 3 || count($ingredients) !== $width * $height) {
            throw new InvalidArgumentException('Shaped recipe dimensions or ingredient count are invalid.');
        }
        $occupied = 0;
        $validatedIngredients = [];
        foreach ($ingredients as $ingredient) {
            if ($ingredient !== null && !$ingredient instanceof RecipeIngredient) {
                throw new InvalidArgumentException('Shaped recipe contains an invalid ingredient.');
            }
            $occupied += $ingredient === null ? 0 : 1;
            $validatedIngredients[] = $ingredient;
        }
        if ($occupied < 1) {
            throw new InvalidArgumentException('Shaped recipe must contain between one and nine occupied ingredients.');
        }
        $validatedOutputs = self::validateOutputs($outputs);
        self::validatePriority($recipePriority);
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

    public function outputs(): array
    {
        return $this->results;
    }

    private static function validateIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Recipe identifier must be canonical and namespaced.');
        }
    }

    /**
     * @param array<array-key, mixed> $outputs
     * @return list<ItemStack>
     */
    private static function validateOutputs(array $outputs): array
    {
        if (!array_is_list($outputs) || $outputs === [] || count($outputs) > 4) {
            throw new InvalidArgumentException('Recipe output count must be between one and four.');
        }
        $validated = [];
        foreach ($outputs as $output) {
            if (!$output instanceof ItemStack) {
                throw new InvalidArgumentException('Recipe contains an invalid output.');
            }
            $validated[] = $output;
        }

        return $validated;
    }

    private static function validatePriority(int $priority): void
    {
        if ($priority < -32_768 || $priority > 32_767) {
            throw new InvalidArgumentException('Recipe priority is outside its supported range.');
        }
    }
}
