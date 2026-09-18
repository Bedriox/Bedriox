<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Block;

/** Bedriox-owned internal palette for bedrock, two dirt layers, grass, and surrounding air. */
final readonly class FixedFlatBlockPalette
{
    public function __construct(
        public InternalBlockStateId $air,
        public InternalBlockStateId $bedrock,
        public InternalBlockStateId $dirt,
        public InternalBlockStateId $grassBlock,
    ) {}

    public static function fromRegistry(BlockStateRegistry $registry): self
    {
        return new self(
            $registry->internalId(VanillaBlockStates::air()),
            $registry->internalId(VanillaBlockStates::bedrock()),
            $registry->internalId(VanillaBlockStates::dirt()),
            $registry->internalId(VanillaBlockStates::grassBlock()),
        );
    }

    /** @return array{air: int, bedrock: int, dirt: int, grass_block: int} */
    public function toNetworkRuntimeIds(BlockNetworkTranslator $translator): array
    {
        return [
            'air' => $translator->toNetwork($this->air),
            'bedrock' => $translator->toNetwork($this->bedrock),
            'dirt' => $translator->toNetwork($this->dirt),
            'grass_block' => $translator->toNetwork($this->grassBlock),
        ];
    }
}
