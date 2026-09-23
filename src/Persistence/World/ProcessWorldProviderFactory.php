<?php

declare(strict_types=1);

namespace Bedriox\Server\Persistence\World;

use Bedriox\Data\PersistentBlockStateRegistry;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\Provider\WorldProviderFactory;
use Bedriox\Server\World\Provider\WritableWorldProvider;
use Closure;

/** Starts a dedicated provider owner; the parent never opens the LevelDB handle. */
final readonly class ProcessWorldProviderFactory implements WorldProviderFactory
{
    /** @var Closure(): int */
    private Closure $clock;

    /** @param null|Closure(): int $clock */
    public function __construct(
        private string $applicationVersion,
        private int $requestTimeoutMilliseconds = 30_000,
        private ?string $entryPoint = null,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function open(
        string $worldPath,
        BlockStateRegistry $blockStates,
        PersistentBlockStateRegistry $persistentBlockStates,
    ): WritableWorldProvider {
        unset($persistentBlockStates);

        return ProcessWorldProvider::start(
            $this->applicationVersion,
            new WorldStorageStartup('open', $worldPath),
            $blockStates,
            $this->requestTimeoutMilliseconds,
            $this->entryPoint,
        );
    }

    public function create(
        string $worldPath,
        WorldData $worldData,
        BlockStateRegistry $blockStates,
        PersistentBlockStateRegistry $persistentBlockStates,
    ): WritableWorldProvider {
        unset($persistentBlockStates);

        return ProcessWorldProvider::start(
            $this->applicationVersion,
            new WorldStorageStartup('create', $worldPath, $worldData, ($this->clock)()),
            $blockStates,
            $this->requestTimeoutMilliseconds,
            $this->entryPoint,
        );
    }
}
