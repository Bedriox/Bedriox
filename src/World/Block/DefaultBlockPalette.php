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
        );
    }
}
