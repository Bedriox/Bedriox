<?php

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Block;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Gameplay\Item\ToolTier;
use Bedriox\Server\Gameplay\Item\ToolType;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\Block\VanillaBlockStates;
use InvalidArgumentException;

/** Bounded gameplay definitions for the states emitted by built-in generators. */
final readonly class BlockCatalog
{
    /** @var array<string, BlockType> */
    private array $typesByStateKey;

    /** @var array<string, BlockType> */
    private array $typesByIdentifier;

    /** @param list<BlockType> $types */
    public function __construct(array $types)
    {
        if ($types === [] || count($types) > 1_024) {
            throw new InvalidArgumentException('Block catalog must be non-empty and bounded.');
        }
        $byState = [];
        $byIdentifier = [];
        foreach ($types as $type) {
            $key = $type->state->canonicalKey();
            $identifier = $type->identifier();
            if (isset($byState[$key]) || isset($byIdentifier[$identifier])) {
                throw new InvalidArgumentException('Block catalog contains a duplicate state or identifier.');
            }
            $byState[$key] = $type;
            $byIdentifier[$identifier] = $type;
        }
        $this->typesByStateKey = $byState;
        $this->typesByIdentifier = $byIdentifier;
    }

    public static function vanilla(): self
    {
        $pickaxe = ToolType::Pickaxe;
        $shovel = ToolType::Shovel;
        $axe = ToolType::Axe;

        return new self([
            new BlockType(VanillaBlockStates::air(), -1.0, null, null, BlockDropKind::None, false),
            new BlockType(VanillaBlockStates::bedrock(), -1.0, null, null, BlockDropKind::None),
            new BlockType(VanillaBlockStates::stone(), 1.5, $pickaxe, ToolTier::Wood, BlockDropKind::Cobblestone),
            new BlockType(VanillaBlockStates::cobblestone(), 2.0, $pickaxe, ToolTier::Wood, BlockDropKind::Self),
            new BlockType(VanillaBlockStates::cobbledDeepslate(), 3.5, $pickaxe, ToolTier::Wood, BlockDropKind::Self),
            new BlockType(VanillaBlockStates::dirt(), 0.5, $shovel, null, BlockDropKind::Self),
            new BlockType(VanillaBlockStates::grassBlock(), 0.6, $shovel, null, BlockDropKind::Dirt),
            new BlockType(VanillaBlockStates::sand(), 0.5, $shovel, null, BlockDropKind::Self),
            new BlockType(VanillaBlockStates::sandstone(), 0.8, $pickaxe, ToolTier::Wood, BlockDropKind::Self),
            new BlockType(VanillaBlockStates::gravel(), 0.6, $shovel, null, BlockDropKind::Gravel),
            new BlockType(VanillaBlockStates::water(), -1.0, null, null, BlockDropKind::None, false),
            new BlockType(VanillaBlockStates::coalOre(), 3.0, $pickaxe, ToolTier::Wood, BlockDropKind::Coal),
            new BlockType(VanillaBlockStates::ironOre(), 3.0, $pickaxe, ToolTier::Stone, BlockDropKind::RawIron),
            new BlockType(VanillaBlockStates::oakLog(), 2.0, $axe, null, BlockDropKind::Self),
            new BlockType(VanillaBlockStates::oakLeaves(), 0.2, ToolType::Hoe, null, BlockDropKind::OakLeaves),
            new BlockType(VanillaBlockStates::clay(), 0.6, $shovel, null, BlockDropKind::ClayBalls),
            new BlockType(VanillaBlockStates::ice(), 0.5, $pickaxe, null, BlockDropKind::Ice),
            new BlockType(VanillaBlockStates::snow(), 0.2, $shovel, ToolTier::Wood, BlockDropKind::Snowballs),
            new BlockType(VanillaBlockStates::coarseDirt(), 0.5, $shovel, null, BlockDropKind::Self),
            new BlockType(VanillaBlockStates::podzol(), 0.5, $shovel, null, BlockDropKind::Dirt),
            new BlockType(VanillaBlockStates::deepslate(), 3.0, $pickaxe, ToolTier::Wood, BlockDropKind::CobbledDeepslate),
            new BlockType(VanillaBlockStates::lava(), -1.0, null, null, BlockDropKind::None, false),
            new BlockType(VanillaBlockStates::copperOre(), 3.0, $pickaxe, ToolTier::Stone, BlockDropKind::RawCopper),
            new BlockType(VanillaBlockStates::goldOre(), 3.0, $pickaxe, ToolTier::Iron, BlockDropKind::RawGold),
            new BlockType(VanillaBlockStates::redstoneOre(), 3.0, $pickaxe, ToolTier::Iron, BlockDropKind::Redstone),
            new BlockType(VanillaBlockStates::diamondOre(), 3.0, $pickaxe, ToolTier::Iron, BlockDropKind::Diamond),
            new BlockType(VanillaBlockStates::birchLog(), 2.0, $axe, null, BlockDropKind::Self),
            new BlockType(VanillaBlockStates::birchLeaves(), 0.2, ToolType::Hoe, null, BlockDropKind::BirchLeaves),
            new BlockType(VanillaBlockStates::spruceLog(), 2.0, $axe, null, BlockDropKind::Self),
            new BlockType(VanillaBlockStates::spruceLeaves(), 0.2, ToolType::Hoe, null, BlockDropKind::SpruceLeaves),
        ]);
    }

    public function typeForState(CanonicalBlockState $state): BlockType
    {
        return $this->typesByStateKey[$state->canonicalKey()]
            ?? throw new InvalidArgumentException('Block state is not present in the gameplay catalog.');
    }

    public function typeForInternalId(InternalBlockStateId $id, BlockStateRegistry $registry): BlockType
    {
        return $this->typeForState($registry->state($id));
    }

    public function type(string $identifier): BlockType
    {
        return $this->typesByIdentifier[$identifier]
            ?? throw new InvalidArgumentException('Block is not present in the gameplay catalog.');
    }

    public function has(string $identifier): bool
    {
        return isset($this->typesByIdentifier[$identifier]);
    }

    /** @return list<BlockType> */
    public function all(): array
    {
        return array_values($this->typesByStateKey);
    }
}
