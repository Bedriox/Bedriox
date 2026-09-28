<?php

declare(strict_types=1);

namespace Bedriox\Server\Persistence\World;

use Bedriox\Data\PersistentBlockStateRegistry;
use Bedriox\Server\Entity\Persistence\EntityPersistenceCodec;
use Bedriox\Server\Worker\WorkerDispatcher;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\Provider\WorldProviderFactory;
use Bedriox\Server\World\Provider\WritableWorldProvider;
use Closure;

/** Starts a dedicated provider owner; the parent never opens the LevelDB handle. */
final class ProcessWorldProviderFactory implements WorldProviderFactory
{
    /** @var Closure(): int */
    private Closure $clock;
    private ?WorkerDispatcher $processLauncher = null;
    private int $processLauncherTaskType = 0;
    private readonly EntityPersistenceCodec $entityPersistenceCodec;

    /** @param null|Closure(): int $clock */
    public function __construct(
        private string $applicationVersion,
        private int $requestTimeoutMilliseconds = 30_000,
        private ?string $entryPoint = null,
        ?Closure $clock = null,
        ?EntityPersistenceCodec $entityPersistenceCodec = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
        $this->entityPersistenceCodec = $entityPersistenceCodec ?? EntityPersistenceCodec::vanilla();
    }

    public function useProcessLauncher(WorkerDispatcher $launcher, int $taskTypeId): void
    {
        if ($taskTypeId < 1 || $taskTypeId > 65_535) {
            throw new \InvalidArgumentException('World storage process launcher task type is invalid.');
        }
        $this->processLauncher = $launcher;
        $this->processLauncherTaskType = $taskTypeId;
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
            entityPersistenceCodec: $this->entityPersistenceCodec,
        );
    }

    /** Begins opening a provider owner without waiting for its storage handshake. */
    public function beginOpen(
        string $worldPath,
        BlockStateRegistry $blockStates,
    ): ProcessWorldProvider {
        return ProcessWorldProvider::beginStart(
            $this->applicationVersion,
            new WorldStorageStartup('open', $worldPath),
            $blockStates,
            $this->requestTimeoutMilliseconds,
            $this->entryPoint,
            $this->processLauncher,
            $this->processLauncherTaskType,
            $this->entityPersistenceCodec,
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
            entityPersistenceCodec: $this->entityPersistenceCodec,
        );
    }

    /** Begins creating a provider owner without waiting for its storage handshake. */
    public function beginCreate(
        string $worldPath,
        WorldData $worldData,
        BlockStateRegistry $blockStates,
    ): ProcessWorldProvider {
        return ProcessWorldProvider::beginStart(
            $this->applicationVersion,
            new WorldStorageStartup('create', $worldPath, $worldData, ($this->clock)()),
            $blockStates,
            $this->requestTimeoutMilliseconds,
            $this->entryPoint,
            $this->processLauncher,
            $this->processLauncherTaskType,
            $this->entityPersistenceCodec,
        );
    }
}
