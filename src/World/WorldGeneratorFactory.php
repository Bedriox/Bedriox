<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\DefaultBlockPalette;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;

final class WorldGeneratorFactory
{
    private function __construct() {}

    public static function create(string $name, int $seed, BlockStateRegistry $states): WorldGenerator
    {
        return match (WorldGeneratorType::tryFrom($name)) {
            WorldGeneratorType::Default => new DefaultWorldGenerator($seed, DefaultBlockPalette::fromRegistry($states)),
            WorldGeneratorType::Flat => new FlatWorldGenerator(FixedFlatBlockPalette::fromRegistry($states)),
            null => throw new \RuntimeException("World generator \"$name\" is not supported."),
        };
    }
}
