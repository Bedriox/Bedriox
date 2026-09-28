<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Api\Player\GameMode;
use Bedriox\Protocol\Packet\SetCommandsEnabledPacket;
use Bedriox\Protocol\Packet\UpdateAbilitiesPacket;
use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\Server\Login\LoginChannelReady;
use Bedriox\Server\Permission\PermissionStore;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Plugin\Command\CommandRegistry;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Worker\Chunk\PreparedChunkCache;
use Bedriox\Server\Worker\Network\CompressionWorkerDispatcher;
use Bedriox\Server\World\World;

final readonly class BedrockPlayChannelFactory implements PlayChannelFactory
{
    public function __construct(
        private PlayInitializationFactory $initialization,
        private SimulationCommandFactory $commands = new SimulationCommandFactory(),
        private RuntimeLimits $limits = new RuntimeLimits(),
        private ?RuntimeDiagnostics $diagnostics = null,
        private ?World $world = null,
        private ?BedrockChunkPacketSerializer $chunkSerializer = null,
        private int $viewDistance = 1,
        private int $spawnRadius = 1,
        private int $chunksGeneratePerTick = 1,
        private int $chunksSendPerTick = 1,
        private int $chunkPrefetchRadius = 0,
        private int $chunkGenerationQueueSize = 1_024,
        private ?BedrockInventoryPacketProjector $inventoryProjector = null,
        private ?CommandRegistry $commandRegistry = null,
        private ?PermissionStore $permissionStore = null,
        private ?CompressionWorkerDispatcher $compressionWorkers = null,
        private int $compressionTaskTypeId = 0,
        private ?PreparedChunkCache $preparedChunks = null,
        private ?PreparedPlayBatchCache $preparedPlayBatches = null,
    ) {}

    public function create(LoginChannelReady $ready, string $sessionId, UnsignedLong $runtimeEntityId, ?PlayerBootstrap $bootstrap = null): BedrockPlayChannel
    {
        $spawnX = 0.0;
        $spawnY = 64.0;
        $spawnZ = 0.0;
        if ($this->world !== null) {
            $spawn = $this->world->spawn();
            $spawnX = (float) $spawn->x;
            $spawnY = (float) $spawn->y;
            $spawnZ = (float) $spawn->z;
        }
        if ($bootstrap !== null) {
            $spawnX = $bootstrap->position->x;
            $spawnY = $bootstrap->position->y;
            $spawnZ = $bootstrap->position->z;
        }

        $initializationPackets = $this->initialization->create($ready->login, $runtimeEntityId, $bootstrap);
        if ($this->commandRegistry !== null && $this->permissionStore !== null) {
            $projector = new BedrockCommandPacketProjector($this->commandRegistry, $this->permissionStore);
            $initializationPackets = array_values(array_filter(array_map(
                fn($packet) => $packet instanceof UpdateAbilitiesPacket
                    ? $projector->abilities(
                        $ready->login->identity,
                        $runtimeEntityId->toSignedBits(),
                        GameMode::from($bootstrap === null ? 'survival' : $bootstrap->gamemode),
                    )
                    : $packet,
                $initializationPackets,
            ), static fn($packet): bool => !$packet instanceof SetCommandsEnabledPacket));
            $initializationPackets[] = new SetCommandsEnabledPacket(true);
            $initializationPackets[] = $projector->availableCommands(
                $ready->login->identity,
                [],
            );
        }

        return new BedrockPlayChannel(
            $ready,
            $sessionId,
            $runtimeEntityId,
            $initializationPackets,
            $this->initialization->fixedFlatRuntimeIds(),
            $this->commands,
            $this->limits,
            $this->diagnostics,
            $this->world,
            $this->chunkSerializer,
            $this->viewDistance,
            $this->spawnRadius,
            $this->chunksGeneratePerTick,
            $this->chunksSendPerTick,
            $this->chunkPrefetchRadius,
            $this->chunkGenerationQueueSize,
            $spawnX,
            $spawnY,
            $spawnZ,
            $this->inventoryProjector,
            $this->compressionWorkers,
            $this->compressionTaskTypeId,
            $this->preparedChunks,
            $this->preparedPlayBatches,
        );
    }
}
