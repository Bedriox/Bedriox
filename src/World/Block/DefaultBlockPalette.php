<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Block;

/** Internal block-state palette required by the built-in default overworld generator. */
final readonly class DefaultBlockPalette
{
    public function __construct(
        public InternalBlockStateId $air,
        public InternalBlockStateId $bedrock,
        public InternalBlockStateId $stone,
        public InternalBlockStateId $dirt,
        public InternalBlockStateId $grassBlock,
        public InternalBlockStateId $sand,
        public InternalBlockStateId $sandstone,
        public InternalBlockStateId $gravel,
        public InternalBlockStateId $water,
        public InternalBlockStateId $coalOre,
        public InternalBlockStateId $ironOre,
        public InternalBlockStateId $oakLog,
        public InternalBlockStateId $oakLeaves,
        public InternalBlockStateId $clay,
        public InternalBlockStateId $ice,
        public InternalBlockStateId $snow,
        public InternalBlockStateId $coarseDirt,
        public InternalBlockStateId $podzol,
        public InternalBlockStateId $deepslate,
        public InternalBlockStateId $lava,
        public InternalBlockStateId $copperOre,
        public InternalBlockStateId $goldOre,
        public InternalBlockStateId $redstoneOre,
        public InternalBlockStateId $diamondOre,
        public InternalBlockStateId $birchLog,
        public InternalBlockStateId $birchLeaves,
        public InternalBlockStateId $spruceLog,
        public InternalBlockStateId $spruceLeaves,
    ) {}

    public static function fromRegistry(BlockStateRegistry $registry): self
    {
        return new self(
            $registry->internalId(VanillaBlockStates::air()),
            $registry->internalId(VanillaBlockStates::bedrock()),
            $registry->internalId(VanillaBlockStates::stone()),
            $registry->internalId(VanillaBlockStates::dirt()),
            $registry->internalId(VanillaBlockStates::grassBlock()),
            $registry->internalId(VanillaBlockStates::sand()),
            $registry->internalId(VanillaBlockStates::sandstone()),
            $registry->internalId(VanillaBlockStates::gravel()),
            $registry->internalId(VanillaBlockStates::water()),
            $registry->internalId(VanillaBlockStates::coalOre()),
            $registry->internalId(VanillaBlockStates::ironOre()),
            $registry->internalId(VanillaBlockStates::oakLog()),
            $registry->internalId(VanillaBlockStates::oakLeaves()),
            $registry->internalId(VanillaBlockStates::clay()),
            $registry->internalId(VanillaBlockStates::ice()),
            $registry->internalId(VanillaBlockStates::snow()),
            $registry->internalId(VanillaBlockStates::coarseDirt()),
            $registry->internalId(VanillaBlockStates::podzol()),
            $registry->internalId(VanillaBlockStates::deepslate()),
            $registry->internalId(VanillaBlockStates::lava()),
            $registry->internalId(VanillaBlockStates::copperOre()),
            $registry->internalId(VanillaBlockStates::goldOre()),
            $registry->internalId(VanillaBlockStates::redstoneOre()),
            $registry->internalId(VanillaBlockStates::diamondOre()),
            $registry->internalId(VanillaBlockStates::birchLog()),
            $registry->internalId(VanillaBlockStates::birchLeaves()),
            $registry->internalId(VanillaBlockStates::spruceLog()),
            $registry->internalId(VanillaBlockStates::spruceLeaves()),
        );
    }
}
