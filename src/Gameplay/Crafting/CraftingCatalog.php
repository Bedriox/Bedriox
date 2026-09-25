<?php

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Crafting;

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\RecipeIngredient as DataIngredient;
use Bedriox\Data\RecipeIngredientType;
use Bedriox\Data\RecipeOutput as DataOutput;
use Bedriox\Data\RecipeType as DataRecipeType;
use Bedriox\Data\ShapedRecipe as DataShapedRecipe;
use Bedriox\Data\ShapelessRecipe as DataShapelessRecipe;
use Bedriox\Protocol\Packet\CraftingDataPacket;
use Bedriox\Protocol\Packet\CraftingRecipe as ProtocolRecipe;
use Bedriox\Protocol\Packet\CraftingRecipeIngredient as ProtocolIngredient;
use Bedriox\Protocol\Packet\CraftingRecipeType as ProtocolRecipeType;
use Bedriox\Protocol\Packet\CraftingRecipeUnlockRequirement;
use Bedriox\Protocol\Packet\InventoryItemStack as ProtocolItemStack;
use Bedriox\Protocol\Packet\MultiCraftingRecipe;
use Bedriox\Protocol\Packet\ShapedCraftingRecipe;
use Bedriox\Protocol\Packet\ShapelessCraftingRecipe;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Runtime\BedrockInventoryPacketProjector;
use Bedriox\Server\World\Block\BlockStateRegistry;
use InvalidArgumentException;

/** Revisioned authoritative registry and complete current-protocol projection. */
final class CraftingCatalog
{
    /** @var list<ProtocolRecipe> */
    private array $staticProtocolRecipes;

    /** @var list<ProtocolRecipe> */
    private array $cachedProtocolRecipes = [];

    private int $cachedRevision = -1;

    /** @var array<int, string> */
    private array $complexByNetworkId;

    /**
     * @param list<ProtocolRecipe> $protocolRecipes
     * @param array<int, string> $complexByNetworkId
     */
    private function __construct(
        private readonly CraftingRecipeRegistry $recipes,
        array $protocolRecipes,
        array $complexByNetworkId,
        private readonly BedrockInventoryPacketProjector $projector,
    ) {
        $this->staticProtocolRecipes = $protocolRecipes;
        $this->complexByNetworkId = $complexByNetworkId;
    }

    public static function fromData(
        BedrockDataSet $data,
        ItemCatalog $items,
        BlockStateRegistry $blocks,
        BedrockInventoryPacketProjector $projector,
    ): self {
        $tags = RecipeItemTagRegistry::vanilla($items);
        $builtIns = [];
        /** @var array<string, DataShapedRecipe|DataShapelessRecipe> $sourceByInternalId */
        $sourceByInternalId = [];
        foreach ($data->recipeRegistry()->craftingTableRecipes() as $source) {
            if (!$source instanceof DataShapedRecipe && !$source instanceof DataShapelessRecipe) {
                throw new InvalidArgumentException('Crafting-table recipe has an unsupported admitted type.');
            }
            $identifier = 'minecraft:recipe/' . $source->sourceIndex();
            $outputs = array_map(
                static fn(DataOutput $output): RecipeOutput => self::output($output, $items, $blocks),
                $source->outputs(),
            );
            if ($source instanceof DataShapedRecipe) {
                $ingredients = [];
                foreach ($source->shape() as $row) {
                    foreach (str_split($row) as $symbol) {
                        $ingredients[] = $symbol === ' '
                            ? null
                            : self::ingredient($source->ingredientsBySymbol()[$symbol], $tags);
                    }
                }
                $recipe = new ShapedRecipe(
                    $identifier,
                    $source->width(),
                    $source->height(),
                    $ingredients,
                    $outputs,
                    $source->priority(),
                    $source->assumeSymmetry(),
                );
            } else {
                $recipe = new ShapelessRecipe(
                    $identifier,
                    array_map(static fn(DataIngredient $ingredient): RecipeIngredient => self::ingredient($ingredient, $tags), $source->ingredients()),
                    $outputs,
                    $source->priority(),
                    preserveInputUserData: $source->preservesUserData(),
                );
            }
            $builtIns[] = $recipe;
            $sourceByInternalId[$identifier] = $source;
        }

        $registry = new CraftingRecipeRegistry($builtIns);
        $protocol = [];
        foreach ($registry->all() as $recipe) {
            $networkId = $registry->networkId($recipe->identifier());
            $source = $sourceByInternalId[$recipe->identifier()] ?? null;
            if ($networkId === null || $source === null) {
                throw new InvalidArgumentException('Crafting recipe snapshot could not assign a network identity.');
            }
            $protocol[] = self::protocolRecipe($source, $networkId, $projector, $items, $blocks);
        }

        $complexByNetworkId = [];
        $complexRecipes = $data->recipeRegistry()->complexRecipes();
        $registry->reserveNetworkIds(count($complexRecipes));
        $nextNetworkId = count($protocol) + 1;
        foreach ($complexRecipes as $source) {
            $uuid = $source->uuid();
            $protocol[] = new MultiCraftingRecipe($uuid, $nextNetworkId);
            $complexByNetworkId[$nextNetworkId] = $uuid;
            ++$nextNetworkId;
        }

        return new self($registry, $protocol, $complexByNetworkId, $projector);
    }

    public function recipes(): CraftingRecipeRegistry
    {
        return $this->recipes;
    }

    /** @return list<ProtocolRecipe> */
    public function protocolRecipes(): array
    {
        $revision = $this->recipes->revision();
        if ($this->cachedRevision === $revision) {
            return $this->cachedProtocolRecipes;
        }

        $projected = $this->staticProtocolRecipes;
        $pluginRecipes = array_values(array_filter(
            $this->recipes->all(),
            static fn(CraftingRecipe $recipe): bool => $recipe->owner() !== null,
        ));
        usort($pluginRecipes, fn(CraftingRecipe $left, CraftingRecipe $right): int =>
            ($this->recipes->networkId($left->identifier()) ?? PHP_INT_MAX)
                <=> ($this->recipes->networkId($right->identifier()) ?? PHP_INT_MAX));
        foreach ($pluginRecipes as $recipe) {
            $networkIds = $this->recipes->networkIdsFor($recipe->identifier());
            if ($networkIds === []) {
                throw new \LogicException('A registered plugin recipe has no network identity.');
            }
            array_push($projected, ...$this->projectPluginRecipe($recipe, $networkIds));
        }
        if (count($projected) > CraftingDataPacket::MAXIMUM_RECIPES) {
            throw new \LogicException('The active crafting catalog exceeds the protocol recipe limit.');
        }
        // Constructing the packet validates uniqueness and every projected recipe before publication.
        $validated = new CraftingDataPacket($projected, true);
        $this->cachedProtocolRecipes = $validated->recipes;
        $this->cachedRevision = $revision;

        return $this->cachedProtocolRecipes;
    }

    public function protocolPacket(): CraftingDataPacket
    {
        return new CraftingDataPacket($this->protocolRecipes(), true);
    }

    public function revision(): int
    {
        return $this->recipes->revision();
    }

    /** Publishes a recipe only after its complete bounded wire expansion is known to be valid. */
    public function register(CraftingRecipe $recipe, bool $replace = false): void
    {
        $variantCount = $this->projectionVariantCount($recipe);
        $existing = $this->recipes->recipe($recipe->identifier());
        $existingProjectionCount = $existing?->owner() === null
            ? 0
            : count($this->recipes->networkIdsFor($recipe->identifier()));
        $prospectiveCount = count($this->protocolRecipes()) - $existingProjectionCount + $variantCount;
        if ($prospectiveCount > CraftingDataPacket::MAXIMUM_RECIPES) {
            throw new InvalidArgumentException('Plugin recipe alternatives exceed the active wire catalog capacity.');
        }

        // A placeholder ID is sufficient to validate every descriptor and result before registry mutation.
        $this->projectPluginRecipe($recipe, range(1, $variantCount));
        $this->recipes->register($recipe, $replace, $variantCount - 1);
        $this->cachedRevision = -1;
        $this->protocolRecipes();
    }

    public function unregisterOwnedBy(string $owner): int
    {
        $removed = $this->recipes->unregisterOwnedBy($owner);
        if ($removed > 0) {
            $this->cachedRevision = -1;
            $this->protocolRecipes();
        }

        return $removed;
    }

    public function complexUuid(int $networkId): ?string
    {
        return $this->complexByNetworkId[$networkId] ?? null;
    }

    private function projectionVariantCount(CraftingRecipe $recipe): int
    {
        $count = 1;
        $ingredients = $recipe instanceof ShapedRecipe ? $recipe->ingredientSlots() : $recipe->ingredients();
        foreach ($ingredients as $ingredient) {
            if ($ingredient === null) {
                continue;
            }
            if ($ingredient->damage !== null || $ingredient->nbt !== null) {
                throw new InvalidArgumentException(
                    'Plugin recipe ingredient damage and NBT constraints cannot be advertised by this protocol.',
                );
            }
            $alternatives = count($ingredient->identifiers);
            if ($count > CraftingDataPacket::MAXIMUM_RECIPES / $alternatives) {
                throw new InvalidArgumentException('Plugin recipe ingredient expansion exceeds its bounded limit.');
            }
            $count *= $alternatives;
        }

        return $count;
    }

    /**
     * @param list<int> $networkIds
     * @return list<ProtocolRecipe>
     */
    private function projectPluginRecipe(CraftingRecipe $recipe, array $networkIds): array
    {
        $variantCount = $this->projectionVariantCount($recipe);
        if (count($networkIds) !== $variantCount) {
            throw new InvalidArgumentException('Plugin recipe projection does not have one network ID per alternative.');
        }
        $results = array_map(function (RecipeOutput $output): ProtocolItemStack {
            $stack = $this->projector->toProtocol($output->toInventoryStack(1));

            return new ProtocolItemStack(
                $stack->runtimeId,
                $stack->count,
                $stack->aux,
                null,
                $stack->blockRuntimeId,
                $stack->userData,
            );
        }, $recipe->outputs());
        $variants = $this->expandedIngredientVariants($recipe);
        $projected = [];
        foreach ($variants as $index => $ingredients) {
            $protocolIngredients = array_map(
                static fn(?RecipeIngredient $ingredient): ProtocolIngredient => $ingredient === null
                    ? ProtocolIngredient::empty()
                    : ProtocolIngredient::item(
                        $ingredient->identifiers[0],
                        $ingredient->count,
                        $ingredient->auxValue ?? 0x7fff,
                    ),
                $ingredients,
            );
            $wireIdentifier = $recipe->identifier()
                . ($index === 0 ? '' : '/alternative/' . $index);
            $uuid = self::recipeUuid('plugin:' . $recipe->identifier() . ':' . $index);
            $networkId = $networkIds[$index];
            if ($recipe instanceof ShapedRecipe) {
                $projected[] = new ShapedCraftingRecipe(
                    ProtocolRecipeType::Shaped,
                    $wireIdentifier,
                    $recipe->width,
                    $recipe->height,
                    $protocolIngredients,
                    $results,
                    $uuid,
                    'crafting_table',
                    $recipe->priority(),
                    $recipe->allowMirror,
                    CraftingRecipeUnlockRequirement::none(),
                    $networkId,
                );
                continue;
            }
            if (!$recipe instanceof ShapelessRecipe) {
                throw new InvalidArgumentException('Plugin recipe type cannot be projected.');
            }
            $projected[] = new ShapelessCraftingRecipe(
                ProtocolRecipeType::Shapeless,
                $wireIdentifier,
                $protocolIngredients,
                $results,
                $uuid,
                'crafting_table',
                $recipe->priority(),
                CraftingRecipeUnlockRequirement::none(),
                $networkId,
            );
        }

        return $projected;
    }

    /** @return list<list<RecipeIngredient|null>> */
    private function expandedIngredientVariants(CraftingRecipe $recipe): array
    {
        $slots = $recipe instanceof ShapedRecipe ? $recipe->ingredientSlots() : $recipe->ingredients();
        $variants = [[]];
        foreach ($slots as $ingredient) {
            if ($ingredient === null) {
                foreach ($variants as &$variant) {
                    $variant[] = null;
                }
                unset($variant);
                continue;
            }
            $identifiers = $ingredient->identifiers;
            sort($identifiers, SORT_STRING);
            $expanded = [];
            foreach ($variants as $variant) {
                foreach ($identifiers as $identifier) {
                    $expanded[] = [
                        ...$variant,
                        RecipeIngredient::exact(
                            $identifier,
                            $ingredient->count,
                            $ingredient->auxValue,
                        ),
                    ];
                }
            }
            $variants = $expanded;
        }

        return $variants;
    }

    private static function ingredient(DataIngredient $source, RecipeItemTagRegistry $tags): RecipeIngredient
    {
        if ($source->type() === RecipeIngredientType::TAG) {
            $tag = $source->itemTag();
            if ($tag === null) {
                throw new InvalidArgumentException('Tag recipe ingredient has no tag name.');
            }

            return new RecipeIngredient($tags->members($tag), $source->count());
        }
        $identifier = $source->itemIdentifier();
        if ($identifier === null) {
            throw new InvalidArgumentException('Exact recipe ingredient has no item identifier.');
        }
        $aux = $source->auxValue();

        return RecipeIngredient::exact($identifier, $source->count(), $aux === 0x7fff ? null : $aux);
    }

    private static function output(DataOutput $source, ItemCatalog $items, BlockStateRegistry $blocks): RecipeOutput
    {
        $identifier = $source->itemIdentifier();
        $state = $items->type($identifier)->placedBlockState;

        return new RecipeOutput(
            $identifier,
            $source->count(),
            0,
            $source->nbt() === null ? null : ItemNbt::fromBinary($source->nbt()),
            auxValue: $source->damage(),
            placedBlockState: $state === null ? null : $blocks->internalId($state),
        );
    }

    private static function protocolRecipe(
        DataShapedRecipe|DataShapelessRecipe $source,
        int $networkId,
        BedrockInventoryPacketProjector $projector,
        ItemCatalog $items,
        BlockStateRegistry $blocks,
    ): ProtocolRecipe {
        $ingredients = [];
        if ($source instanceof DataShapedRecipe) {
            foreach ($source->shape() as $row) {
                foreach (str_split($row) as $symbol) {
                    $ingredients[] = $symbol === ' '
                        ? ProtocolIngredient::empty()
                        : self::protocolIngredient($source->ingredientsBySymbol()[$symbol]);
                }
            }
        } else {
            $ingredients = array_map(self::protocolIngredient(...), $source->ingredients());
        }
        $results = array_map(static function (DataOutput $output) use ($projector, $items, $blocks): ProtocolItemStack {
            $stack = $projector->toProtocol(self::output($output, $items, $blocks)->toInventoryStack(1));

            return new ProtocolItemStack(
                $stack->runtimeId,
                $stack->count,
                $stack->aux,
                null,
                $stack->blockRuntimeId,
                $stack->userData,
            );
        }, $source->outputs());
        $id = $source->identifier();
        $uuid = self::recipeUuid('bedriox:' . $source->sourceIndex() . ':' . $id);
        $requirement = CraftingRecipeUnlockRequirement::none();
        if ($source instanceof DataShapedRecipe) {
            return new ShapedCraftingRecipe(
                ProtocolRecipeType::Shaped,
                $id,
                $source->width(),
                $source->height(),
                $ingredients,
                $results,
                $uuid,
                'crafting_table',
                $source->priority(),
                $source->assumeSymmetry(),
                $requirement,
                $networkId,
            );
        }

        return new ShapelessCraftingRecipe(
            $source->type() === DataRecipeType::SHAPELESS_USER_DATA
                ? ProtocolRecipeType::UserDataShapeless
                : ProtocolRecipeType::Shapeless,
            $id,
            $ingredients,
            $results,
            $uuid,
            'crafting_table',
            $source->priority(),
            $requirement,
            $networkId,
        );
    }

    private static function protocolIngredient(DataIngredient $source): ProtocolIngredient
    {
        if ($source->type() === RecipeIngredientType::TAG) {
            return ProtocolIngredient::itemTag(
                $source->itemTag() ?? throw new InvalidArgumentException('Tag recipe ingredient has no name.'),
                $source->count(),
            );
        }

        return ProtocolIngredient::item(
            $source->itemIdentifier() ?? throw new InvalidArgumentException('Exact recipe ingredient has no item.'),
            $source->count(),
            $source->auxValue() ?? 0x7fff,
        );
    }

    private static function recipeUuid(string $value): string
    {
        $bytes = substr(hash('sha256', $value, true), 0, 16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x50);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}
