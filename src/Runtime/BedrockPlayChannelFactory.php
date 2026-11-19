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
use Bedriox\Protocol\Identity\VerifiedClientData;
use Bedriox\Protocol\Packet\SetCommandsEnabledPacket;
use Bedriox\Protocol\Packet\UpdateAbilitiesPacket;
use Bedriox\Protocol\ProtocolVersion;
use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\Server\Login\AuthenticatedLogin;
use Bedriox\Server\Login\LoginChannelReady;
use Bedriox\Server\Permission\PermissionStore;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Plugin\Command\CommandRegistry;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Worker\Chunk\PreparedChunkCache;
use Bedriox\Server\Worker\Network\CompressionWorkerDispatcher;
use Bedriox\Server\World\World;
use Closure;

final class BedrockPlayChannelFactory implements PlayChannelFactory
{
    private const string WARM_UP_PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MHYwEAYHKoZIzj0CAQYFK4EEACIDYgAEghVsrULO1V7ZoUStBvm7bNVE8ac/IfLe
pU28Paqfw98DyyjLAyC/VYO0cw66ubsPjd0hOpd6+DVNTQhXbyCN+ZS5ZFtqnwwA
Lt6DxU/qqVn/K3qn1Lnd1t9C6pxVmSFw
-----END PUBLIC KEY-----
PEM;

    /**
     * @param null|Closure(PlayerBootstrap): (?array{initialization: PlayInitializationFactory, world: World, preparedChunks: ?PreparedChunkCache}) $worldBinding
     */
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
        private ?Closure $worldBinding = null,
        private bool $traceUnsupportedPackets = true,
        private int $unsupportedPacketLogLimit = 5,
    ) {}

    /** Pays one-time play projection and channel construction costs before the transport accepts players. */
    public function warmUp(): void
    {
        $identityKey = openssl_pkey_get_public(self::WARM_UP_PUBLIC_KEY);
        if ($identityKey === false) {
            throw new \RuntimeException('Unable to create the play-channel warm-up identity.');
        }
        $clientData = new VerifiedClientData(
            64,
            64,
            str_repeat("\0", 64 * 64 * 4),
            0,
            0,
            '',
            '{}',
            [],
            skinId: 'bedriox:warmup',
            skinResourcePatchJson: '{"geometry":{"default":"geometry.humanoid.custom"}}',
        );
        $login = new AuthenticatedLogin(
            'Bedriox Warmup',
            '00000000-0000-4000-8000-000000000000',
            '',
            $identityKey,
            $clientData,
        );
        $channel = $this->create(
            new LoginChannelReady($login, ProtocolVersion::CURRENT),
            'startup-warmup',
            UnsignedLong::fromInt(1),
        );
        $channel->close('startup_warmup');
    }

    public function create(LoginChannelReady $ready, string $sessionId, UnsignedLong $runtimeEntityId, ?PlayerBootstrap $bootstrap = null): BedrockPlayChannel
    {
        $startedNanoseconds = hrtime(true);
        $initialization = $this->initialization;
        $world = $this->world;
        $preparedChunks = $this->preparedChunks;
        if ($bootstrap !== null && $this->worldBinding !== null) {
            $binding = ($this->worldBinding)($bootstrap);
            if ($binding !== null) {
                $initialization = $binding['initialization'];
                $world = $binding['world'];
                $preparedChunks = $binding['preparedChunks'];
            }
        }
        $bindingCompletedNanoseconds = hrtime(true);
        $spawnX = 0.0;
        $spawnY = 64.0;
        $spawnZ = 0.0;
        if ($world !== null) {
            $spawn = $world->spawn();
            $spawnX = (float) $spawn->x;
            $spawnY = (float) $spawn->y;
            $spawnZ = (float) $spawn->z;
        }
        if ($bootstrap !== null) {
            $spawnX = $bootstrap->position->x;
            $spawnY = $bootstrap->position->y;
            $spawnZ = $bootstrap->position->z;
        }

        $initializationPackets = $initialization->create($ready->login, $runtimeEntityId, $bootstrap);
        $initializationCompletedNanoseconds = hrtime(true);
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
        $commandsCompletedNanoseconds = hrtime(true);

        $channel = new BedrockPlayChannel(
            $ready,
            $sessionId,
            $runtimeEntityId,
            $initializationPackets,
            $initialization->fixedFlatRuntimeIds(),
            $this->commands,
            $this->limits,
            $this->diagnostics,
            $world,
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
            $preparedChunks,
            $this->preparedPlayBatches,
            $this->traceUnsupportedPackets,
            $this->unsupportedPacketLogLimit,
        );
        $completedNanoseconds = hrtime(true);
        $this->diagnostics?->record('runtime.play_channel_factory.protocol_trace', [
            'binding_us' => intdiv($bindingCompletedNanoseconds - $startedNanoseconds, 1_000),
            'initialization_us' => intdiv($initializationCompletedNanoseconds - $bindingCompletedNanoseconds, 1_000),
            'commands_us' => intdiv($commandsCompletedNanoseconds - $initializationCompletedNanoseconds, 1_000),
            'channel_us' => intdiv($completedNanoseconds - $commandsCompletedNanoseconds, 1_000),
            'total_us' => intdiv($completedNanoseconds - $startedNanoseconds, 1_000),
        ]);

        return $channel;
    }

    public function setDefaultGameMode(GameMode $gameMode): void
    {
        if ($this->initialization instanceof BedrockPlayInitializationFactory) {
            $this->initialization->setDefaultGameMode($gameMode);
        }
    }
}
