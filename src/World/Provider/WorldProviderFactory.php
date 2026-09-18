<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Provider;

use Bedriox\Data\PersistentBlockStateRegistry;
use Bedriox\Server\World\Block\BlockStateRegistry;

/** Opens or creates one storage provider without exposing its native database handle. */
interface WorldProviderFactory
{
    public function open(
        string $worldPath,
        BlockStateRegistry $blockStates,
        PersistentBlockStateRegistry $persistentBlockStates,
    ): WritableWorldProvider;

    public function create(
        string $worldPath,
        WorldData $worldData,
        BlockStateRegistry $blockStates,
        PersistentBlockStateRegistry $persistentBlockStates,
    ): WritableWorldProvider;
}
