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

use Bedriox\Api\Entity\EntityInteractionType;
use Bedriox\Protocol\Batch\BatchLimits;
use Bedriox\Protocol\Batch\BedrockBatch;
use Bedriox\Protocol\Batch\BedrockBatchCodec;
use Bedriox\Protocol\Batch\CompressionMode;
use Bedriox\Protocol\Batch\PacketBatchCodec;
use Bedriox\Protocol\Encryption\BedrockDecryptor;
use Bedriox\Protocol\Encryption\BedrockEncryptor;
use Bedriox\Protocol\Exception\ItemStackRequestDecodeException;
use Bedriox\Protocol\Packet\Ability;
use Bedriox\Protocol\Packet\AbilityLayer;
use Bedriox\Protocol\Packet\ActorEventPacket;
use Bedriox\Protocol\Packet\AnimatePacket;
use Bedriox\Protocol\Packet\AutoCraftRecipeItemStackRequestAction;
use Bedriox\Protocol\Packet\BasicInventoryTransaction;
use Bedriox\Protocol\Packet\BedrockPacketCodec;
use Bedriox\Protocol\Packet\BlockPosition;
use Bedriox\Protocol\Packet\BossEventAction;
use Bedriox\Protocol\Packet\BossEventPacket;
use Bedriox\Protocol\Packet\ChangeDimensionPacket;
use Bedriox\Protocol\Packet\ChatPacket;
use Bedriox\Protocol\Packet\ChunkRadiusUpdatedPacket;
use Bedriox\Protocol\Packet\ClientCacheStatusPacket;
use Bedriox\Protocol\Packet\CommandOrigin;
use Bedriox\Protocol\Packet\CommandOriginType;
use Bedriox\Protocol\Packet\CommandRequestPacket;
use Bedriox\Protocol\Packet\ConsumeItemStackRequestAction;
use Bedriox\Protocol\Packet\ContainerClosePacket;
use Bedriox\Protocol\Packet\ContainerOpenPacket;
use Bedriox\Protocol\Packet\ContainerSlotType;
use Bedriox\Protocol\Packet\ContainerType;
use Bedriox\Protocol\Packet\CraftCreativeItemStackRequestAction;
use Bedriox\Protocol\Packet\CraftLoomItemStackRequestAction;
use Bedriox\Protocol\Packet\CraftNonImplementedItemStackRequestAction;
use Bedriox\Protocol\Packet\CraftRecipeItemStackRequestAction;
use Bedriox\Protocol\Packet\CraftRecipeOptionalItemStackRequestAction;
use Bedriox\Protocol\Packet\CraftRepairAndDisenchantItemStackRequestAction;
use Bedriox\Protocol\Packet\CraftResultsItemStackRequestAction;
use Bedriox\Protocol\Packet\CreateItemStackRequestAction;
use Bedriox\Protocol\Packet\DimensionId;
use Bedriox\Protocol\Packet\DropItemStackRequestAction;
use Bedriox\Protocol\Packet\EmoteListPacket;
use Bedriox\Protocol\Packet\EmotePacket;
use Bedriox\Protocol\Packet\FullContainerName;
use Bedriox\Protocol\Packet\InteractPacket;
use Bedriox\Protocol\Packet\InventoryContainerId;
use Bedriox\Protocol\Packet\InventoryItemStack;
use Bedriox\Protocol\Packet\InventorySourceFlag;
use Bedriox\Protocol\Packet\InventorySourceType;
use Bedriox\Protocol\Packet\InventoryTransactionPacket;
use Bedriox\Protocol\Packet\InventoryTransactionType;
use Bedriox\Protocol\Packet\ItemReleaseActionType;
use Bedriox\Protocol\Packet\ItemReleaseInventoryTransaction;
use Bedriox\Protocol\Packet\ItemStackRequest;
use Bedriox\Protocol\Packet\ItemStackRequestPacket;
use Bedriox\Protocol\Packet\ItemStackRequestSlot;
use Bedriox\Protocol\Packet\ItemUseActionType;
use Bedriox\Protocol\Packet\ItemUseInventoryTransaction;
use Bedriox\Protocol\Packet\ItemUseOnEntityActionType;
use Bedriox\Protocol\Packet\ItemUseOnEntityInventoryTransaction;
use Bedriox\Protocol\Packet\MapInfoRequestPacket;
use Bedriox\Protocol\Packet\MineBlockItemStackRequestAction;
use Bedriox\Protocol\Packet\MobArmorEquipmentPacket;
use Bedriox\Protocol\Packet\MobEquipmentPacket;
use Bedriox\Protocol\Packet\MovementPredictionSyncPacket;
use Bedriox\Protocol\Packet\MovePlayerMode;
use Bedriox\Protocol\Packet\MovePlayerPacket;
use Bedriox\Protocol\Packet\NetworkChunkPublisherUpdatePacket;
use Bedriox\Protocol\Packet\NetworkStackLatencyPacket;
use Bedriox\Protocol\Packet\Packet;
use Bedriox\Protocol\Packet\PacketFrame;
use Bedriox\Protocol\Packet\PacketHeader;
use Bedriox\Protocol\Packet\PacketIds;
use Bedriox\Protocol\Packet\PlaceItemStackRequestAction;
use Bedriox\Protocol\Packet\PlayerAbilities;
use Bedriox\Protocol\Packet\PlayerActionPacket;
use Bedriox\Protocol\Packet\PlayerActionType;
use Bedriox\Protocol\Packet\PlayerAuthInputFlag;
use Bedriox\Protocol\Packet\PlayerAuthInputPacket;
use Bedriox\Protocol\Packet\PlayerBlockAction;
use Bedriox\Protocol\Packet\PlayerItemUseTransaction;
use Bedriox\Protocol\Packet\PlayerPositionProjection;
use Bedriox\Protocol\Packet\PlayerSkinPacket;
use Bedriox\Protocol\Packet\PlayStatus;
use Bedriox\Protocol\Packet\PlayStatusPacket;
use Bedriox\Protocol\Packet\RequestAbilityPacket;
use Bedriox\Protocol\Packet\RequestChunkRadiusPacket;
use Bedriox\Protocol\Packet\RespawnPacket;
use Bedriox\Protocol\Packet\RespawnState;
use Bedriox\Protocol\Packet\ServerboundLoadingScreenPacket;
use Bedriox\Protocol\Packet\ServerSettingsRequestPacket;
use Bedriox\Protocol\Packet\SetDifficultyPacket;
use Bedriox\Protocol\Packet\SetLocalPlayerAsInitializedPacket;
use Bedriox\Protocol\Packet\SetPlayerFurnaceOptionsPacket;
use Bedriox\Protocol\Packet\SetPlayerInventoryOptionsPacket;
use Bedriox\Protocol\Packet\SetTimePacket;
use Bedriox\Protocol\Packet\SubChunkPacket;
use Bedriox\Protocol\Packet\SubChunkRequestPacket;
use Bedriox\Protocol\Packet\SwapItemStackRequestAction;
use Bedriox\Protocol\Packet\TakeItemStackRequestAction;
use Bedriox\Protocol\Packet\UpdateAbilitiesPacket;
use Bedriox\Protocol\ProtocolVersion;
use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\RakNet\Connected\ConnectedPayloadEvent;
use Bedriox\RakNet\Protocol\Reliability;
use Bedriox\Server\Login\AuthenticatedLogin;
use Bedriox\Server\Login\LoginChannelReady;
use Bedriox\Server\Player\InventoryContainer;
use Bedriox\Server\Player\InventoryResponseMode;
use Bedriox\Server\Player\InventorySlotReference;
use Bedriox\Server\Player\InventoryStackRequestAction;
use Bedriox\Server\Player\InventoryStackRequestActionType;
use Bedriox\Server\Player\PlayerInventory;
use Bedriox\Server\Simulation\ArmSwingSource;
use Bedriox\Server\Simulation\BlockBreakAction;
use Bedriox\Server\Simulation\ClientInputTick;
use Bedriox\Server\Simulation\Command\CraftingRequest;
use Bedriox\Server\Simulation\Command\MovePlayer;
use Bedriox\Server\Simulation\Command\WorkstationRequest;
use Bedriox\Server\Simulation\Command\WorkstationRequestType;
use Bedriox\Server\Simulation\Command\WorldCommand;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Transport\NetworkCompressionPolicy;
use Bedriox\Server\Worker\Chunk\PreparedChunk;
use Bedriox\Server\Worker\Chunk\PreparedChunkAvailability;
use Bedriox\Server\Worker\Chunk\PreparedChunkCache;
use Bedriox\Server\Worker\Network\CompressionWorkerDispatcher;
use Bedriox\Server\Worker\Network\OrderedOutboundCompressionQueue;
use Bedriox\Server\World\BlockPosition as WorldBlockPosition;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\World;
use SplQueue;
use Throwable;

/** Owns post-login protocol framing, live ciphers, and validated play input. */
final class BedrockPlayChannel
{
    private const int PROTOCOL_TRACE_SUMMARY_INTERVAL_NANOSECONDS = 30_000_000_000;
    private const int CHUNK_PREPARATION_NANOSECONDS_PER_TICK = 5_000_000;
    private const int CHUNK_PREPARATION_BYTES_PER_TICK = 4_194_304;
    private const int CHUNK_DELIVERY_NANOSECONDS_PER_TICK = 5_000_000;
    private const int CHUNK_DELIVERY_BYTES_PER_TICK = 524_288;
    private const int MAXIMUM_LEGACY_SLOT_SYNC_GROUPS = 10;

    /** @var SplQueue<OutgoingPlayPayload> */
    private SplQueue $outgoing;

    /** @var SplQueue<WorldCommand> */
    private SplQueue $commands;

    /** @var SplQueue<CommandRequestPacket> */
    private SplQueue $playerCommands;

    /** @var SplQueue<Packet|ReusablePlayPacket> */
    private SplQueue $pendingBootstrapPackets;

    /** @var SplQueue<Packet> */
    private SplQueue $pendingStreamingResponses;

    /** @var SplQueue<ChunkPosition> */
    private SplQueue $generatedChunks;

    private int $outgoingBytes = 0;
    private int $chatSequence = 0;
    private int $movementSequence = 0;
    private int $blockSequence = 0;
    private int $placementSequence = 0;
    private ?UnsignedLong $lastMovementTick = null;
    private ?bool $lastRequestedFlyingState = null;
    private ?UpdateAbilitiesPacket $authoritativeAbilities = null;
    private bool $sneaking = false;
    private bool $sprinting = false;
    /** @var list<Packet|ReusablePlayPacket> */
    private array $deferredInitializationPackets = [];
    private bool $bootstrapSent = false;
    private bool $spawnAcknowledged = false;
    private bool $admissionReleased = false;
    private bool $preferStreamingResponse = true;
    private bool $initialized = false;
    private bool $mainInventoryOpen = false;
    private int $mainInventoryId = 0;
    private int $nextMainInventoryId = 1;
    private ?int $storageContainerId = null;
    private ?ContainerType $storageContainerType = null;
    private ?int $pendingStorageCloseId = null;
    private ?ContainerType $pendingStorageCloseType = null;
    private ?int $pendingHotbarSlot = null;
    private bool $closed = false;
    private bool $spawnStatusQueued = false;
    private ?NetworkChunkPublisherUpdatePacket $pendingChunkPublisherUpdate = null;
    private ?PendingWorldSwitch $pendingWorldSwitch = null;
    private bool $worldSwitchInputGated = false;
    private bool $worldSwitchTeleportAcknowledged = false;
    private bool $worldSwitchCenterQueued = false;
    private bool $worldSwitchCenterDrained = false;
    private int $worldSwitchGateTicks = 0;
    private ?string $worldSwitchCenterKey = null;
    /** @var array<string, true> Chunk keys whose sent visibility changed since the last projection pass. */
    private array $chunkVisibilityChanges = [];
    private ?ChunkViewManager $chunkView = null;
    /** @var array<string, ChunkPosition> */
    private array $retainedChunks = [];
    /** @var array<string, true> */
    private array $queuedChunkKeys = [];
    /** @var array<string, ChunkPosition> */
    private array $pendingChunkRequests = [];
    /** @var array<string, true> */
    private array $sentSpawnChunks = [];
    private readonly AuthenticatedLogin $login;
    private readonly BedrockEncryptor $encryptor;
    private readonly BedrockDecryptor $decryptor;
    private readonly BatchLimits $batchLimits;
    private readonly RuntimeDiagnostics $diagnostics;
    private readonly int $protocolVersion;
    private readonly ?OrderedOutboundCompressionQueue $outboundCompression;

    /** @var array<int, array{packets: int, bytes: int}> */
    private array $traceInboundPackets = [];
    /** @var array<string, array{batches: int, packets: int, bytes: int}> */
    private array $traceOutboundBatches = [];
    private int $tracePreparedChunks = 0;
    private int $tracePreparedChunkBytes = 0;
    private int $traceInboundEnvelopes = 0;
    private int $traceInboundDecryptNanoseconds = 0;
    private int $traceInboundBatchNanoseconds = 0;
    private int $traceInboundDecodeNanoseconds = 0;
    private int $traceInboundHandleNanoseconds = 0;
    private int $traceWindowStartedNanoseconds;

    /** @var SplQueue<string> */
    private SplQueue $deferredCompressionBatches;
    private int $deferredCompressionBytes = 0;

    /** @var array<string, true> */
    private array $trackedSubChunkRequests = [];

    /** @var array<string, true> */
    private array $servedSubChunkSections = [];

    /**
     * @param list<Packet|ReusablePlayPacket> $initializationPackets
     * @param array{air: int, bedrock: int, dirt: int, grass_block: int} $fixedFlatRuntimeIds
     */
    public function __construct(
        LoginChannelReady $ready,
        private readonly string $sessionId,
        private readonly UnsignedLong $runtimeEntityId,
        array $initializationPackets,
        private readonly array $fixedFlatRuntimeIds,
        private readonly SimulationCommandFactory $commandFactory = new SimulationCommandFactory(),
        private readonly RuntimeLimits $limits = new RuntimeLimits(),
        ?RuntimeDiagnostics $diagnostics = null,
        private ?World $flatWorld = null,
        private readonly ?BedrockChunkPacketSerializer $chunkSerializer = null,
        private readonly int $viewDistance = 1,
        private readonly int $spawnRadius = 1,
        private readonly int $chunksGeneratePerTick = 1,
        private readonly int $chunksSendPerTick = 1,
        private readonly int $chunkPrefetchRadius = 0,
        private readonly int $chunkGenerationQueueSize = 1_024,
        private readonly float $spawnX = 0.0,
        private readonly float $spawnY = 64.0,
        private readonly float $spawnZ = 0.0,
        private readonly ?BedrockInventoryPacketProjector $inventoryProjector = null,
        ?CompressionWorkerDispatcher $compressionWorkers = null,
        int $compressionTaskTypeId = 0,
        private ?PreparedChunkCache $preparedChunks = null,
        private readonly ?PreparedPlayBatchCache $preparedPlayBatches = null,
    ) {
        $this->login = $ready->login;
        $this->encryptor = $ready->encryptor;
        $this->decryptor = $ready->decryptor;
        $this->protocolVersion = $ready->protocolVersion;
        $this->outgoing = new SplQueue();
        $this->commands = new SplQueue();
        $this->playerCommands = new SplQueue();
        $this->pendingBootstrapPackets = new SplQueue();
        $this->pendingStreamingResponses = new SplQueue();
        $this->generatedChunks = new SplQueue();
        $this->deferredCompressionBatches = new SplQueue();
        $this->traceWindowStartedNanoseconds = hrtime(true);
        $this->batchLimits = new BatchLimits(maximumPackets: $this->limits->maximumPacketsPerPayload);
        $this->outboundCompression = $compressionWorkers === null ? null : new OrderedOutboundCompressionQueue(
            $compressionWorkers,
            $compressionTaskTypeId,
            1,
            CompressionMode::NegotiatedZlib,
            NetworkCompressionPolicy::THRESHOLD_BYTES,
            $this->batchLimits,
            maximumOutstandingTasks: $this->limits->maximumOutgoingPayloadsPerSession,
            maximumOutstandingBytes: $this->limits->maximumOutgoingBytesPerSession,
            maximumResultBytes: $this->batchLimits->maximumInputBytes + 1,
            minimumOffloadBytes: NetworkCompressionPolicy::MINIMUM_WORKER_OFFLOAD_BYTES,
        );
        $this->diagnostics = $diagnostics ?? RuntimeDiagnostics::disabled();
        try {
            if ($this->protocolVersion !== ProtocolVersion::CURRENT) {
                throw new \InvalidArgumentException('Play channel protocol is not the current runtime target.');
            }
            if (($this->flatWorld === null) !== ($this->chunkSerializer === null)
                || $this->viewDistance < 1 || $this->viewDistance > 32
                || $this->spawnRadius < 1 || $this->spawnRadius > $this->viewDistance
                || $this->chunksGeneratePerTick < 1 || $this->chunksGeneratePerTick > 64
                || $this->chunksSendPerTick < 1 || $this->chunksSendPerTick > 64
                || $this->chunkPrefetchRadius < 0 || $this->chunkPrefetchRadius > 8
                || $this->viewDistance + $this->chunkPrefetchRadius > 32
                || $this->chunkGenerationQueueSize < 1 || $this->chunkGenerationQueueSize > 65_536) {
                throw new \InvalidArgumentException('Chunk streaming configuration is invalid.');
            }
            if (count($initializationPackets) > $this->limits->maximumOutgoingPayloadsPerSession) {
                throw new \OverflowException('Initial play packet count exceeded its configured limit.');
            }
            $radiusPhase = false;
            foreach ($initializationPackets as $initializationPacket) {
                $packet = $initializationPacket instanceof ReusablePlayPacket
                    ? $initializationPacket->packet
                    : $initializationPacket;
                if ($packet instanceof UpdateAbilitiesPacket) {
                    $this->authoritativeAbilities = $packet;
                }
                if ($packet instanceof ChunkRadiusUpdatedPacket) {
                    $radiusPhase = true;
                    continue;
                }
                if ($radiusPhase) {
                    $this->deferredInitializationPackets[] = $initializationPacket;
                    continue;
                }
                if (!$this->queueInitializationPacket($initializationPacket)) {
                    throw new \OverflowException('Initial play output exceeded its configured limit.');
                }
            }
            $this->bootstrapSent = !$radiusPhase;
        } catch (Throwable $exception) {
            $this->close('initialization_failed', exception: $exception);
            throw $exception;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function accept(ConnectedPayloadEvent $event): bool
    {
        if ($this->closed
            || $event->reliability !== Reliability::ReliableOrdered
            || $event->orderingChannel !== 0) {
            return $this->fail('invalid_transport');
        }
        $packetId = null;
        try {
            ++$this->traceInboundEnvelopes;
            $stageStartedNanoseconds = hrtime(true);
            $envelope = $this->decryptor->decryptEnvelope($event->payload);
            $stageCompletedNanoseconds = hrtime(true);
            $this->traceInboundDecryptNanoseconds += $stageCompletedNanoseconds - $stageStartedNanoseconds;
            $batch = BedrockBatchCodec::decode(
                $envelope,
                CompressionMode::NegotiatedZlib,
                $this->batchLimits,
                NetworkCompressionPolicy::THRESHOLD_BYTES,
            );
            $nextStageNanoseconds = hrtime(true);
            $this->traceInboundBatchNanoseconds += $nextStageNanoseconds - $stageCompletedNanoseconds;
            if (count($batch->packets) > $this->limits->maximumCommandsPerPayload) {
                return $this->fail('command_limit');
            }
            foreach ($batch->packets as $frame) {
                $packetId = $frame->header->packetId;
                $this->recordInboundTrace($packetId, strlen($frame->payload));
                if ($frame->header->senderSubclientId !== 0 || $frame->header->targetSubclientId !== 0) {
                    $this->diagnose("rejected packet {$packetId}: non-zero subclient routing");
                    return $this->fail('subclient_routing', $packetId);
                }
                if (!$this->initialized && $frame->header->packetId === PacketIds::PLAYER_AUTH_INPUT) {
                    continue;
                }
                $decodeStartedNanoseconds = hrtime(true);
                $packet = BedrockPacketCodec::decode($packetId, $frame->payload, $this->protocolVersion);
                $handleStartedNanoseconds = hrtime(true);
                $this->traceInboundDecodeNanoseconds += $handleStartedNanoseconds - $decodeStartedNanoseconds;
                $handled = $this->handle($packet);
                $this->traceInboundHandleNanoseconds += hrtime(true) - $handleStartedNanoseconds;
                if (!$handled) {
                    $this->diagnose("rejected packet {$packetId} (" . $packet::class . '): invalid play state');
                    return $this->fail('invalid_play_state', $packetId);
                }
            }

            return true;
        } catch (Throwable $error) {
            $context = $packetId === null ? 'play envelope' : "packet {$packetId}";
            $this->diagnose("failed decoding {$context}: " . $error::class);
            if ($error instanceof ItemStackRequestDecodeException
                && ($packetId === PacketIds::ITEM_STACK_REQUEST || $packetId === PacketIds::PLAYER_AUTH_INPUT)) {
                $this->diagnostics->record('play.inventory_decode.protocol_trace', [
                    'packet_id' => $packetId,
                    'stage' => $error->stage,
                    'detail' => $error->detailCode,
                    'byte_offset' => $error->byteOffset,
                    'action_index' => $error->actionIndex,
                    'action_type' => $error->actionType,
                ]);
            } elseif ($packetId === PacketIds::ITEM_STACK_REQUEST) {
                $this->diagnostics->record('play.inventory_decode.protocol_trace', [
                    'packet_id' => $packetId,
                    'stage' => 'packet',
                    'detail' => self::itemStackPacketDecodeDetail($error),
                ]);
            }
            return $this->fail('decode_failed', $packetId, $error);
        }
    }

    public function queuePacket(Packet $packet): bool
    {
        return $this->queuePackets([$packet]);
    }

    /**
     * Encodes related packets into one ordered Bedrock batch.
     *
     * @param list<Packet> $packets
     */
    public function queuePackets(array $packets): bool
    {
        $packetCount = count($packets);
        if ($packetCount < 1 || $packetCount > $this->limits->maximumPacketsPerPayload) {
            return false;
        }
        if ($this->closed || $this->totalQueuedPackets() >= $this->limits->maximumOutgoingPayloadsPerSession) {
            return false;
        }
        $packetId = null;
        try {
            $frames = [];
            foreach ($packets as $packet) {
                $packetId = BedrockPacketCodec::packetId($packet);
                $frames[] = new PacketFrame(
                    new PacketHeader($packetId),
                    BedrockPacketCodec::encode($packet, $this->protocolVersion),
                );
            }
        } catch (Throwable $error) {
            $this->diagnose('failed encoding ordered packet batch: ' . $error::class);
            return $this->fail('encode_failed', $packetId, $error);
        }

        return $this->queuePacketFrames($frames, $packets);
    }

    /**
     * Queues immutable packet frames which were projected once for multiple recipients.
     *
     * @param list<PacketFrame> $frames
     * @param list<Packet> $sourcePackets
     */
    public function queuePacketFrames(array $frames, array $sourcePackets = []): bool
    {
        $packetCount = count($frames);
        if ($packetCount < 1 || $packetCount > $this->limits->maximumPacketsPerPayload
            || ($sourcePackets !== [] && count($sourcePackets) !== $packetCount)) {
            return false;
        }
        if ($this->closed || $this->totalQueuedPackets() >= $this->limits->maximumOutgoingPayloadsPerSession) {
            return false;
        }
        try {
            if ($this->outboundCompression !== null) {
                $clearBatch = PacketBatchCodec::encode($frames, $this->batchLimits);
                return $this->queueClearBatch($clearBatch, $sourcePackets, $packetCount);
            }
            $batch = new BedrockBatch($frames, CompressionMode::NegotiatedZlib, NetworkCompressionPolicy::THRESHOLD_BYTES);
            $envelope = $this->encryptor->encryptEnvelope(BedrockBatchCodec::encode($batch, $this->batchLimits));
            $this->recordOutboundTrace('direct', $packetCount, strlen($envelope));
        } catch (Throwable $error) {
            $this->diagnose('failed encoding ordered packet frame batch: ' . $error::class);
            return $this->fail('encode_failed', exception: $error);
        }
        if (strlen($envelope) > $this->limits->maximumOutgoingBytesPerSession - $this->outgoingBytes) {
            return $this->fail('output_limit');
        }
        $this->outgoing->enqueue(new OutgoingPlayPayload($envelope));
        $this->outgoingBytes += strlen($envelope);
        foreach ($sourcePackets as $packet) {
            $this->recordQueuedPacket($packet);
        }

        return true;
    }

    /**
     * Queues a clear Bedrock envelope projected once for a group of recipients.
     *
     * @param list<PacketFrame> $frames Used as a bounded fallback if the ordered queue is saturated.
     */
    public function queuePreparedPacketFrames(array $frames, string $clearEnvelope): bool
    {
        $packetCount = count($frames);
        if ($packetCount < 1 || $packetCount > $this->limits->maximumPacketsPerPayload
            || $clearEnvelope === '' || ord($clearEnvelope[0]) !== BedrockBatchCodec::GAME_PACKET_MARKER) {
            return false;
        }
        if (!$this->prepareTransientProjection()) {
            return false;
        }

        return $this->queuePreparedClearEnvelope($clearEnvelope, $packetCount, 'shared');
    }

    /**
     * Queues a shared, pre-encoded authoritative batch while preserving ordered delivery.
     *
     * @param list<PacketFrame> $frames
     * @param list<Packet> $sourcePackets
     */
    public function queuePreparedAuthoritativePacketFrames(
        array $frames,
        string $clearEnvelope,
        array $sourcePackets = [],
    ): bool {
        $packetCount = count($frames);
        if ($packetCount < 1 || $packetCount > $this->limits->maximumPacketsPerPayload
            || ($sourcePackets !== [] && count($sourcePackets) !== $packetCount)
            || $clearEnvelope === '' || ord($clearEnvelope[0]) !== BedrockBatchCodec::GAME_PACKET_MARKER) {
            return false;
        }
        if ($this->closed || $this->totalQueuedPackets() >= $this->limits->maximumOutgoingPayloadsPerSession) {
            return false;
        }
        if ($this->outboundCompression !== null
            && (!$this->releaseCompressedBatches() || $this->hasOutboundOrderingBarrier())) {
            $submission = $this->outboundCompression->enqueuePrepared($clearEnvelope);
            if (!$submission->isAccepted()) {
                return $this->queuePacketFrames($frames, $sourcePackets);
            }
            if (!$this->releaseCompressedBatches()) {
                return false;
            }
            $this->recordOutboundTrace('shared-authoritative', $packetCount, strlen($clearEnvelope));
            foreach ($sourcePackets as $packet) {
                $this->recordQueuedPacket($packet);
            }

            return true;
        }

        return $this->queuePreparedClearEnvelope(
            $clearEnvelope,
            $packetCount,
            'shared-authoritative',
            $sourcePackets,
        );
    }

    /**
     * Encrypts and queues an already-compressed envelope when no older compression work blocks it.
     *
     * @param list<Packet> $sourcePackets
     */
    private function queuePreparedClearEnvelope(
        string $clearEnvelope,
        int $packetCount,
        string $traceMode,
        array $sourcePackets = [],
    ): bool {
        try {
            $envelope = $this->encryptor->encryptEnvelope($clearEnvelope);
        } catch (Throwable $error) {
            return $this->fail('encode_failed', exception: $error);
        }
        if (strlen($envelope) > $this->limits->maximumOutgoingBytesPerSession - $this->outgoingBytes) {
            return $this->fail('output_limit');
        }
        $this->outgoing->enqueue(new OutgoingPlayPayload($envelope));
        $this->outgoingBytes += strlen($envelope);
        $this->recordOutboundTrace($traceMode, $packetCount, strlen($envelope));
        foreach ($sourcePackets as $packet) {
            $this->recordQueuedPacket($packet);
        }

        return true;
    }

    /**
     * Releases completed ordered work and reports whether transient state may be projected now.
     *
     * Callers may suppress replaceable state such as peer movement while this returns false. This
     * avoids encoding an update which cannot be placed ahead of an older ordered batch.
     */
    public function prepareTransientProjection(): bool
    {
        if ($this->closed || !$this->releaseCompressedBatches() || $this->hasOutboundOrderingBarrier()) {
            return false;
        }

        return $this->totalQueuedPackets() < $this->limits->maximumOutgoingPayloadsPerSession
            && $this->outgoingBytes < $this->limits->maximumOutgoingBytesPerSession;
    }

    private function queueInitializationPacket(Packet|ReusablePlayPacket $initializationPacket): bool
    {
        $packet = $initializationPacket instanceof ReusablePlayPacket
            ? $initializationPacket->packet
            : $initializationPacket;
        if (!$initializationPacket instanceof ReusablePlayPacket
            || $this->preparedPlayBatches === null
            || $this->outboundCompression === null) {
            return $this->queuePacket($packet);
        }
        if ($this->closed || $this->totalQueuedPackets() >= $this->limits->maximumOutgoingPayloadsPerSession) {
            return false;
        }
        try {
            $clearBatch = $this->preparedPlayBatches->getOrEncode(
                $initializationPacket->key,
                fn(): string => PacketBatchCodec::encode([
                    new PacketFrame(
                        new PacketHeader(BedrockPacketCodec::packetId($packet)),
                        BedrockPacketCodec::encode($packet, $this->protocolVersion),
                    ),
                ], $this->batchLimits),
            );
        } catch (Throwable $error) {
            return $this->fail('encode_failed', BedrockPacketCodec::packetId($packet), $error);
        }

        return $this->queueClearBatch($clearBatch, [$packet]);
    }

    /** @param list<Packet> $packets */
    private function queueClearBatch(string $clearBatch, array $packets, ?int $packetCount = null): bool
    {
        $packetCount ??= count($packets);
        if (!$this->deferredCompressionBatches->isEmpty()) {
            if (!$this->canRetainDeferredCompression($clearBatch)) {
                return false;
            }
            $this->deferredCompressionBatches->enqueue($clearBatch);
            $this->deferredCompressionBytes += strlen($clearBatch);
            foreach ($packets as $packet) {
                $this->recordQueuedPacket($packet);
            }

            return true;
        }
        $submission = $this->outboundCompression?->enqueue($clearBatch);
        if ($submission === null) {
            return false;
        }
        if (!$submission->isAccepted()) {
            $this->diagnose("deferred ordered batch of {$packetCount} packets due to compression backpressure");
            if (!$this->canRetainDeferredCompression($clearBatch)) {
                return false;
            }
            $this->deferredCompressionBatches->enqueue($clearBatch);
            $this->deferredCompressionBytes += strlen($clearBatch);
            foreach ($packets as $packet) {
                $this->recordQueuedPacket($packet);
            }

            return true;
        }
        if (!$this->releaseCompressedBatches()) {
            return false;
        }
        $this->recordOutboundTrace(
            $submission->synchronousFallback ? 'synchronous' : 'worker',
            $packetCount,
            strlen($clearBatch),
        );
        foreach ($packets as $packet) {
            $this->recordQueuedPacket($packet);
        }

        return true;
    }

    private function recordQueuedPacket(Packet $packet): void
    {
        if ($packet instanceof UpdateAbilitiesPacket) {
            $this->authoritativeAbilities = $packet;
        }
        if ($packet instanceof ContainerOpenPacket
            && $packet->actorUniqueId === -1
            && $packet->containerType !== ContainerType::Workbench) {
            $this->storageContainerId = $packet->containerId;
            $this->storageContainerType = $packet->containerType;
            $this->pendingStorageCloseId = null;
            $this->pendingStorageCloseType = null;

            return;
        }
        if ($packet instanceof ContainerClosePacket
            && $packet->serverInitiated
            && $packet->containerId === $this->storageContainerId) {
            $this->pendingStorageCloseId = $this->storageContainerId;
            $this->pendingStorageCloseType = $this->storageContainerType;
            $this->storageContainerId = null;
            $this->storageContainerType = null;
        }
    }

    /** Encodes a bounded amount of deferred world bootstrap and request-driven terrain work. */
    public function tick(): bool
    {
        if ($this->closed) {
            return false;
        }
        $this->flushProtocolTraceSummary();
        if (!$this->releaseCompressedBatches()) {
            return false;
        }
        if (!$this->flushPendingChunkPublisherUpdate()) {
            return false;
        }
        if ($this->pendingChunkPublisherUpdate !== null) {
            return true;
        }
        $compressionBudget = $this->limits->maximumStreamingPacketsPerPoll;
        while ($compressionBudget-- > 0 && !$this->deferredCompressionBatches->isEmpty()) {
            $batch = $this->deferredCompressionBatches->bottom();
            $submission = $this->outboundCompression?->enqueue($batch);
            if ($submission === null || !$submission->isAccepted()) {
                break;
            }
            $this->deferredCompressionBatches->dequeue();
            $this->deferredCompressionBytes -= strlen($batch);
        }
        if (!$this->releaseCompressedBatches()) {
            return false;
        }
        $remaining = $this->limits->maximumStreamingPacketsPerPoll;
        while ($remaining-- > 0) {
            if (!$this->pendingStreamingResponses->isEmpty() && !$this->pendingBootstrapPackets->isEmpty()) {
                $queue = $this->preferStreamingResponse
                    ? $this->pendingStreamingResponses
                    : $this->pendingBootstrapPackets;
                $this->preferStreamingResponse = !$this->preferStreamingResponse;
            } else {
                $queue = !$this->pendingStreamingResponses->isEmpty()
                    ? $this->pendingStreamingResponses
                    : $this->pendingBootstrapPackets;
            }
            if ($queue->isEmpty()) {
                break;
            }
            $packet = $queue->bottom();
            if (!$this->queueInitializationPacket($packet)) {
                if ($this->closed) {
                    return false;
                }

                break;
            }
            $queue->dequeue();
        }
        $this->updateSpawnAcknowledged();

        return true;
    }

    /** Performs exactly one configured world-tick budget of chunk generation and delivery. */
    public function worldTick(?ChunkStreamingBudget $streamingBudget = null): bool
    {
        if ($this->closed) {
            return false;
        }
        if ($this->pendingWorldSwitch !== null) {
            return true;
        }
        if ($this->worldSwitchInputGated) {
            ++$this->worldSwitchGateTicks;
            $this->releaseWorldSwitchGateIfReady();
        }
        if ($this->pendingChunkPublisherUpdate !== null) {
            return true;
        }
        if (!$this->pendingBootstrapPackets->isEmpty() || !$this->pendingStreamingResponses->isEmpty()) {
            return true;
        }

        $streamingBudget ??= new ChunkStreamingBudget(
            $this->chunksGeneratePerTick,
            $this->chunksSendPerTick,
            $this->chunksSendPerTick,
        );

        return $this->generateChunks($streamingBudget)
            && $this->prepareGeneratedChunks($streamingBudget)
            && $this->sendGeneratedChunks($streamingBudget);
    }

    /**
     * @return list<OutgoingPlayPayload>
     * @phpstan-impure
     */
    public function drainOutgoing(): array
    {
        $values = [];
        while (!$this->outgoing->isEmpty()) {
            $value = $this->outgoing->dequeue();
            $this->outgoingBytes -= strlen($value->payload);
            $values[] = $value;
        }
        if ($values !== [] && $this->worldSwitchCenterQueued) {
            $this->worldSwitchCenterDrained = true;
            $this->releaseWorldSwitchGateIfReady();
        }

        return $values;
    }

    /** @return list<WorldCommand> */
    public function drainCommands(): array
    {
        $values = [];
        while (!$this->commands->isEmpty()) {
            $values[] = $this->commands->dequeue();
        }

        return $values;
    }

    /** @return list<CommandRequestPacket> */
    public function drainPlayerCommands(): array
    {
        $values = [];
        while (!$this->playerCommands->isEmpty()) {
            $values[] = $this->playerCommands->dequeue();
        }
        return $values;
    }

    public function takeSpawnAcknowledged(): bool
    {
        $acknowledged = $this->spawnAcknowledged;
        $this->spawnAcknowledged = false;

        return $acknowledged;
    }

    /** Returns whether this session still needs terrain before the client may acknowledge spawn. */
    public function requiresSpawnTerrain(): bool
    {
        return !$this->spawnStatusQueued;
    }

    public function login(): AuthenticatedLogin
    {
        return $this->login;
    }

    public function runtimeEntityId(): UnsignedLong
    {
        return $this->runtimeEntityId;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function queuedGeneratedChunkCount(): int
    {
        return $this->generatedChunks->count();
    }

    public function chunkStreamingSnapshot(): ChunkStreamingSnapshot
    {
        return new ChunkStreamingSnapshot(
            $this->chunkView === null ? 0 : 1,
            $this->chunkView?->pendingCount() ?? 0,
            $this->chunkView?->prefetchPendingCount() ?? 0,
            count($this->pendingChunkRequests),
            $this->generatedChunks->count(),
            count($this->retainedChunks),
            $this->totalQueuedPackets(),
        );
    }

    /** Whether this client has already been sent the chunk containing the block position. */
    public function hasSentChunkAt(float $x, float $z): bool
    {
        return $this->chunkView?->hasSent((int) floor($x / 16.0), (int) floor($z / 16.0)) ?? false;
    }

    /** Consumes the bounded chunk-delivery change latch used by peer actor visibility. */
    public function takeChunkVisibilityChanged(): bool
    {
        $changed = $this->chunkVisibilityChanges !== [];
        $this->chunkVisibilityChanges = [];

        return $changed;
    }

    /** @return list<string> canonical chunk keys whose visibility changed */
    public function takeChunkVisibilityChanges(): array
    {
        $changes = array_keys($this->chunkVisibilityChanges);
        $this->chunkVisibilityChanges = [];

        return $changes;
    }

    /** Applies an authoritative simulation position to the per-player chunk view. */
    public function updateChunkView(float $x, float $y, float $z): bool
    {
        if ($this->closed) {
            return false;
        }
        if ($this->chunkView === null) {
            return true;
        }
        $view = $this->chunkView;
        $previousCenter = $view->center();
        $released = $view->centerOnBlock($x, $z);
        $center = $view->center();
        if ($center === $previousCenter) {
            return true;
        }
        foreach ($released as $coordinate) {
            $this->chunkVisibilityChanges[$coordinate['x'] . ':' . $coordinate['z']] = true;
            $this->releaseChunk($coordinate['x'], $coordinate['z']);
        }
        $this->resetGeneratedChunkDeliveryQueue();
        foreach ($this->pendingChunkRequests as $key => $position) {
            if (!$view->containsRetained($position->x, $position->z)) {
                unset($this->pendingChunkRequests[$key]);
            }
        }
        if ($center === null) {
            return false;
        }

        $this->pendingChunkPublisherUpdate = new NetworkChunkPublisherUpdatePacket(
            (int) floor($x),
            (int) floor($y),
            (int) floor($z),
            $view->radius() * 16,
        );

        return $this->flushPendingChunkPublisherUpdate();
    }

    /** Begins a loading-screen-free same-dimension transition without changing authoritative ownership. */
    public function beginWorldSwitch(
        World $world,
        ?PreparedChunkCache $preparedChunks,
        float $x,
        float $y,
        float $z,
        ?int $worldTime = null,
        ?int $difficulty = null,
        ?DimensionId $dimension = null,
    ): bool {
        if ($this->closed || $this->pendingWorldSwitch !== null || $this->worldSwitchInputGated
            || !is_finite($x) || !is_finite($y) || !is_finite($z)) {
            return false;
        }

        $radius = $this->chunkView?->radius() ?? min($this->viewDistance, $this->limits->maximumChunkRadius);
        $prefetchRadius = min($this->chunkPrefetchRadius, 32 - $radius);
        $this->pendingWorldSwitch = new PendingWorldSwitch(
            $world,
            $preparedChunks,
            $x,
            $y,
            $z,
            $worldTime ?? $world->time(),
            $difficulty ?? $world->difficulty(),
            $dimension,
            $radius,
            $prefetchRadius,
            min($this->spawnRadius, $radius),
        );
        $this->worldSwitchInputGated = true;
        $this->worldSwitchTeleportAcknowledged = false;
        $this->worldSwitchCenterQueued = false;
        $this->worldSwitchCenterDrained = false;
        $this->worldSwitchGateTicks = 0;
        $this->worldSwitchCenterKey = null;

        // These are unencoded source-world responses and can safely be replaced.
        $this->pendingStreamingResponses = new SplQueue();
        $this->trackedSubChunkRequests = [];
        $this->servedSubChunkSections = [];

        return true;
    }

    /** Polls destination terrain preparation and the source ordered-output fence. */
    public function worldSwitchReady(): bool
    {
        $transition = $this->pendingWorldSwitch;
        if ($this->closed || !$transition instanceof PendingWorldSwitch) {
            return false;
        }
        $attempted = 0;
        foreach ($transition->required() as $position) {
            if ($transition->isRetained($position)) {
                continue;
            }
            if ($attempted++ >= $this->chunksGeneratePerTick) {
                break;
            }
            if (!$transition->world->requestRetainChunk($position, false)) {
                continue;
            }
            $transition->retain($position);
        }
        foreach ($transition->required() as $position) {
            if (!$transition->isRetained($position)) {
                continue;
            }
            if ($transition->preparedChunks === null) {
                $transition->markProjectionReady($position);
                continue;
            }
            $chunk = $transition->world->chunk($position);
            $lookup = $transition->preparedChunks->lookupOrRequest($chunk, $this->protocolVersion);
            if ($lookup->availability === PreparedChunkAvailability::READY
                || $lookup->availability === PreparedChunkAvailability::SYNCHRONOUS_FALLBACK) {
                $transition->markProjectionReady($position);
            }
        }
        if (!$transition->isReady() || !$this->releaseCompressedBatches()) {
            return false;
        }

        return $this->worldSwitchOutputIsQuiescent();
    }

    private function worldSwitchOutputIsQuiescent(): bool
    {
        return $this->pendingChunkPublisherUpdate === null
            && $this->pendingStreamingResponses->isEmpty()
            && $this->pendingBootstrapPackets->isEmpty()
            && $this->deferredInitializationPackets === []
            && $this->deferredCompressionBatches->isEmpty()
            && ($this->outboundCompression?->outstandingCount() ?? 0) === 0
            && $this->outgoing->isEmpty();
    }

    /** Commits a prepared world binding and queues the complete PMMP-style owner transition boundary. */
    public function commitWorldSwitch(float $yaw, float $pitch): bool
    {
        $transition = $this->pendingWorldSwitch;
        if (!$transition instanceof PendingWorldSwitch || !$transition->isReady()
            || !$this->worldSwitchOutputIsQuiescent()
            || !is_finite($yaw) || !is_finite($pitch)) {
            return false;
        }
        $radius = $transition->view->radius();
        $packets = [
            new SetTimePacket($transition->worldTime),
            new SetDifficultyPacket($transition->difficulty),
            new MovePlayerPacket(
                $this->runtimeEntityId,
                $transition->x,
                PlayerPositionProjection::feetToWireY($transition->y),
                $transition->z,
                $pitch,
                $yaw,
                $yaw,
                MovePlayerMode::TELEPORT,
                false,
                UnsignedLong::fromInt(0),
                UnsignedLong::fromInt(0),
            ),
            new NetworkChunkPublisherUpdatePacket(
                (int) floor($transition->x),
                (int) floor($transition->y),
                (int) floor($transition->z),
                $radius * 16,
            ),
        ];
        if ($transition->dimension !== null) {
            array_unshift($packets, new ChangeDimensionPacket(
                $transition->dimension,
                $transition->x,
                PlayerPositionProjection::feetToWireY($transition->y),
                $transition->z,
                false,
            ));
            array_splice($packets, -1, 0, [new PlayerActionPacket(
                $this->runtimeEntityId,
                PlayerActionType::DimensionChangeSuccess,
                new BlockPosition(0, 0, 0),
                new BlockPosition(0, 0, 0),
                0,
            )]);
        }
        if (!$this->queuePackets($packets)) {
            return false;
        }

        if ($this->flatWorld !== null) {
            foreach ($this->retainedChunks as $position) {
                $this->flatWorld->releaseChunk($position, immediateUnload: true);
            }
        }
        $this->flatWorld = $transition->world;
        $this->preparedChunks = $transition->preparedChunks;
        $this->chunkView = $transition->view;
        $this->retainedChunks = $transition->adoptRetained();
        $this->queuedChunkKeys = [];
        $this->pendingChunkRequests = [];
        $this->sentSpawnChunks = [];
        $this->chunkVisibilityChanges = [];
        $this->generatedChunks = new SplQueue();
        foreach ($transition->required() as $position) {
            $this->queuedChunkKeys[$position->key()] = true;
            $this->generatedChunks->enqueue($position);
        }
        $center = new ChunkPosition(
            (int) floor($transition->x / 16.0),
            (int) floor($transition->z / 16.0),
        );
        $this->worldSwitchCenterKey = $center->key();
        $this->pendingWorldSwitch = null;
        $this->spawnStatusQueued = true;

        return true;
    }

    public function abortWorldSwitch(): void
    {
        $this->pendingWorldSwitch?->release();
        $this->pendingWorldSwitch = null;
        $this->worldSwitchInputGated = false;
        $this->worldSwitchTeleportAcknowledged = false;
        $this->worldSwitchCenterQueued = false;
        $this->worldSwitchCenterDrained = false;
        $this->worldSwitchGateTicks = 0;
        $this->worldSwitchCenterKey = null;
    }

    /** Immediate compatibility path used only when destination preparation is already complete. */
    public function switchWorld(
        World $world,
        ?PreparedChunkCache $preparedChunks,
        float $x,
        float $y,
        float $z,
    ): bool {
        if (!$this->beginWorldSwitch($world, $preparedChunks, $x, $y, $z)) {
            return false;
        }
        for ($attempt = 0; $attempt < 64; ++$attempt) {
            if ($this->worldSwitchReady()) {
                return $this->commitWorldSwitch(0.0, 0.0);
            }
        }
        $this->abortWorldSwitch();

        return false;
    }

    /** Keeps only the newest chunk center while bounded output catches up. */
    private function flushPendingChunkPublisherUpdate(): bool
    {
        $packet = $this->pendingChunkPublisherUpdate;
        if ($packet === null) {
            return true;
        }
        if ($this->queuePacket($packet)) {
            $this->pendingChunkPublisherUpdate = null;

            return true;
        }

        return !$this->closed;
    }

    public function close(string $reason = 'server_close', ?int $packetId = null, ?Throwable $exception = null): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->pendingWorldSwitch?->release();
        $this->pendingWorldSwitch = null;
        $this->outboundCompression?->close();
        $this->outgoing = new SplQueue();
        $this->deferredCompressionBatches = new SplQueue();
        $this->deferredCompressionBytes = 0;
        $this->commands = new SplQueue();
        $this->playerCommands = new SplQueue();
        $this->pendingBootstrapPackets = new SplQueue();
        $this->pendingStreamingResponses = new SplQueue();
        $this->generatedChunks = new SplQueue();
        $this->outgoingBytes = 0;
        $this->deferredInitializationPackets = [];
        $this->trackedSubChunkRequests = [];
        $this->servedSubChunkSections = [];
        if ($this->flatWorld !== null) {
            foreach ($this->retainedChunks as $position) {
                $this->flatWorld->releaseChunk($position, immediateUnload: true);
            }
        }
        $this->retainedChunks = [];
        $this->queuedChunkKeys = [];
        $this->pendingChunkRequests = [];
        $this->sentSpawnChunks = [];
        $this->chunkView = null;
        $this->mainInventoryOpen = false;
        $this->mainInventoryId = 0;
        $this->storageContainerId = null;
        $this->storageContainerType = null;
        $this->pendingStorageCloseId = null;
        $this->pendingStorageCloseType = null;
        $this->pendingHotbarSlot = null;
        $this->pendingChunkPublisherUpdate = null;
        $this->worldSwitchInputGated = false;
        $this->worldSwitchTeleportAcknowledged = false;
        $this->worldSwitchCenterQueued = false;
        $this->worldSwitchCenterDrained = false;
        $this->worldSwitchGateTicks = 0;
        $this->worldSwitchCenterKey = null;
        $this->bootstrapSent = false;
        $this->spawnAcknowledged = false;
        $this->admissionReleased = false;
        $this->preferStreamingResponse = true;
        $this->encryptor->close();
        $this->decryptor->close();
        $fields = ['reason' => $reason];
        if ($packetId !== null) {
            $fields['packet_id'] = $packetId;
        }
        if ($exception !== null) {
            $fields['exception'] = $exception::class;
            if ($packetId === PacketIds::PLAYER_AUTH_INPUT) {
                $detail = self::playerAuthDecodeDetail($exception);
                if ($detail !== null) {
                    $fields['detail'] = $detail;
                }
            }
        }
        $this->diagnostics->record('play.session_closed', $fields);
    }

    private function handle(Packet $packet): bool
    {
        if ($packet instanceof BossEventPacket) {
            // Retail clients acknowledge membership in an actor-backed boss encounter
            // and may query its presentation state. These messages are advisory: the
            // authoritative server remains the sole owner of the bar and encounter.
            return in_array($packet->action, [
                BossEventAction::REGISTER_PLAYER,
                BossEventAction::UNREGISTER_PLAYER,
                BossEventAction::QUERY,
            ], true);
        }
        if ($this->worldSwitchInputGated) {
            if ($packet instanceof PlayerAuthInputPacket) {
                if ($packet->hasInput(PlayerAuthInputFlag::HandledTeleport)) {
                    $this->worldSwitchTeleportAcknowledged = true;
                    $this->releaseWorldSwitchGateIfReady();
                }

                return true;
            }
            if ($packet instanceof InventoryTransactionPacket
                || $packet instanceof ItemStackRequestPacket
                || $packet instanceof PlayerActionPacket
                || $packet instanceof InteractPacket
                || $packet instanceof MobEquipmentPacket
                || $packet instanceof MobArmorEquipmentPacket
                || $packet instanceof AnimatePacket
                || $packet instanceof EmotePacket) {
                return true;
            }
        }
        if ($packet instanceof ServerboundLoadingScreenPacket) {
            // Current clients may include a screen ID, omit START, repeat a boundary,
            // or deliver END after the authoritative world-switch gate has opened.
            // This packet is advisory and never authorizes the dimension transfer.
            return true;
        }
        if ($packet instanceof CommandRequestPacket) {
            if (!$this->initialized || $packet->internal || $packet->origin->type !== CommandOriginType::Player
                || $this->playerCommands->count() >= $this->limits->maximumCommandsPerPayload) {
                return false;
            }
            $this->playerCommands->enqueue(new CommandRequestPacket(
                $packet->command,
                new CommandOrigin(
                    CommandOriginType::Player,
                    $this->login->identity,
                    $packet->origin->requestId,
                    $packet->origin->playerId,
                ),
                false,
                $packet->version,
            ));
            return true;
        }
        if ($packet instanceof MovementPredictionSyncPacket) {
            $this->diagnostics->record('play.movement_prediction_sync.protocol_trace', [
                'advisory_consumed' => true,
                'reported_flying' => $packet->flying,
                'actor_flag_count' => count($packet->actorFlags),
            ]);

            return true;
        }
        if ($packet instanceof EmotePacket) {
            if (!$this->initialized
                || !$packet->runtimeEntityId->equals($this->runtimeEntityId)
                || $this->commands->count() >= $this->limits->maximumCommandsPerPayload) {
                return false;
            }
            $this->commands->enqueue($this->commandFactory->emote($this->sessionId, $packet->emoteId));

            return true;
        }
        if ($packet instanceof EmoteListPacket) {
            return $packet->runtimeEntityId->equals($this->runtimeEntityId);
        }
        if ($packet instanceof PlayerSkinPacket) {
            return $this->initialized
                && strtolower($packet->uuid) === strtolower($this->login->identity);
        }
        if ($packet instanceof InteractPacket) {
            if ($packet->action === InteractPacket::VEHICLE_EXIT && $this->initialized) {
                if ($this->commands->count() >= $this->limits->maximumCommandsPerPayload) {
                    return false;
                }
                $this->commands->enqueue($this->commandFactory->dismountPlayer($this->sessionId));

                return true;
            }
            if ($packet->action !== InteractPacket::OPEN_INVENTORY || !$this->initialized) {
                return true;
            }
            if ($this->mainInventoryOpen) {
                return true;
            }
            if ($this->commands->count() >= $this->limits->maximumCommandsPerPayload) {
                return false;
            }
            $containerId = $this->nextMainInventoryId;
            $this->nextMainInventoryId = $containerId >= 99 ? 1 : $containerId + 1;
            if (!$this->queuePacket(ContainerOpenPacket::mainPlayerInventory(
                $containerId,
                $this->runtimeEntityId->toSignedBits(),
            ))) {
                return false;
            }
            $this->commands->enqueue($this->commandFactory->syncInventory($this->sessionId));
            $this->mainInventoryOpen = true;
            $this->mainInventoryId = $containerId;

            return true;
        }
        if ($packet instanceof ClientCacheStatusPacket) {
            return true;
        }
        if ($packet instanceof AnimatePacket) {
            return $packet->runtimeEntityId->equals($this->runtimeEntityId);
        }
        if ($packet instanceof ActorEventPacket) {
            $this->diagnostics->record('play.actor_event_advisory.protocol_trace', [
                'initialized' => $this->initialized,
                'self_actor' => $packet->runtimeEntityId->equals($this->runtimeEntityId),
                'actor_event' => $packet->event->name,
            ]);

            // Retail sends eating presentation here. Authoritative use, nutrition, and animation remain server-owned.
            return true;
        }
        if ($packet instanceof MobEquipmentPacket) {
            if (!$packet->runtimeEntityId->equals($this->runtimeEntityId)) {
                return false;
            }
            if ($packet->windowId === InventoryContainerId::OFFHAND) {
                // Offhand ownership is changed only through a stack request; this presentation echo is harmless.
                return true;
            }
            if ($packet->inventorySlot > 8
                || $packet->hotbarSlot > 8
                || $packet->inventorySlot !== $packet->hotbarSlot
                || $packet->windowId !== InventoryContainerId::INVENTORY) {
                return false;
            }
            if (!$this->initialized) {
                $this->pendingHotbarSlot = $packet->hotbarSlot;

                return true;
            }
            if ($this->commands->count() >= $this->limits->maximumCommandsPerPayload) {
                return false;
            }
            $this->commands->enqueue($this->commandFactory->selectHotbarSlot(
                $this->sessionId,
                $packet->hotbarSlot,
            ));

            return true;
        }
        if ($packet instanceof MobArmorEquipmentPacket) {
            // Armor mutations arrive through authoritative stack requests. This client presentation echo is advisory.
            return $packet->runtimeEntityId->equals($this->runtimeEntityId);
        }
        if ($packet instanceof PlayerActionPacket) {
            if (!$this->initialized || !$packet->runtimeEntityId->equals($this->runtimeEntityId)) {
                return false;
            }
            if ($packet->action === PlayerActionType::StartFlying
                || $packet->action === PlayerActionType::StopFlying) {
                return $this->queueAuthoritativeAbilities($packet->action === PlayerActionType::StartFlying);
            }
            if ($packet->action === PlayerActionType::Respawn) {
                if ($this->commands->count() >= $this->limits->maximumCommandsPerPayload) {
                    return false;
                }
                $this->commands->enqueue($this->commandFactory->respawn($this->sessionId));

                return true;
            }
            if (in_array($packet->action, [
                PlayerActionType::StartDestroyBlock,
                PlayerActionType::AbortDestroyBlock,
                PlayerActionType::StopDestroyBlock,
                PlayerActionType::CrackBlock,
                PlayerActionType::PredictDestroyBlock,
                PlayerActionType::ContinueDestroyBlock,
            ], true)) {
                return $this->handleBlockActions([new PlayerBlockAction(
                    $packet->action,
                    $packet->action === PlayerActionType::StopDestroyBlock ? null : $packet->blockPosition,
                    $packet->action === PlayerActionType::StopDestroyBlock ? null : $packet->face,
                )]);
            }

            return true;
        }
        if ($packet instanceof RespawnPacket) {
            if (!$this->initialized) {
                return false;
            }
            if ($packet->state !== RespawnState::ClientReady) {
                return true;
            }
            if ($this->commands->count() >= $this->limits->maximumCommandsPerPayload) {
                return false;
            }
            $this->commands->enqueue($this->commandFactory->acknowledgeRespawn($this->sessionId));

            return true;
        }
        if ($packet instanceof InventoryTransactionPacket) {
            if (!$this->initialized) {
                return false;
            }
            $transaction = $packet->transaction;
            if ($transaction instanceof BasicInventoryTransaction) {
                return $this->handleLegacyInventoryTransaction($packet);
            }
            if ($transaction instanceof ItemUseOnEntityInventoryTransaction) {
                if ($transaction->runtimeEntityId->high !== 0 || $transaction->runtimeEntityId->low < 1) {
                    return true;
                }
                if ($transaction->hotbarSlot < 0 || $transaction->hotbarSlot > 8) {
                    return true;
                }
                if ($this->commands->count() + 2 > $this->limits->maximumCommandsPerPayload) {
                    return false;
                }
                try {
                    $this->commands->enqueue($this->commandFactory->selectHotbarSlot(
                        $this->sessionId,
                        $transaction->hotbarSlot,
                    ));
                    $this->commands->enqueue($transaction->action === ItemUseOnEntityActionType::Attack
                        ? $this->commandFactory->attack(
                            $this->sessionId,
                            $transaction->runtimeEntityId->low,
                            $transaction->hotbarSlot,
                        )
                        : $this->commandFactory->interactEntity(
                            $this->sessionId,
                            $transaction->runtimeEntityId->low,
                            $transaction->hotbarSlot,
                            $transaction->action === ItemUseOnEntityActionType::ItemInteract
                                ? EntityInteractionType::ITEM_INTERACT
                                : EntityInteractionType::INTERACT,
                        ));
                } catch (\Bedriox\Server\Simulation\CommandValidationException) {
                    return true;
                }

                return true;
            }
            if ($transaction instanceof ItemReleaseInventoryTransaction) {
                if ($this->commands->count() >= $this->limits->maximumCommandsPerPayload) {
                    return false;
                }
                try {
                    $this->commands->enqueue($transaction->action === ItemReleaseActionType::Consume
                        ? $this->commandFactory->useItem($this->sessionId, $transaction->hotbarSlot)
                        : $this->commandFactory->releaseItem($this->sessionId, $transaction->hotbarSlot));
                } catch (\Bedriox\Server\Simulation\CommandValidationException) {
                    return true;
                }

                return true;
            }
            if (!$transaction instanceof ItemUseInventoryTransaction) {
                return true;
            }
            if ($transaction->action === ItemUseActionType::Use) {
                if ($this->commands->count() >= $this->limits->maximumCommandsPerPayload) {
                    return false;
                }
                try {
                    $this->commands->enqueue($this->commandFactory->useItem(
                        $this->sessionId,
                        $transaction->hotbarSlot,
                    ));
                } catch (\Bedriox\Server\Simulation\CommandValidationException) {
                    return true;
                }

                return true;
            }
            if ($transaction->action !== ItemUseActionType::Place) {
                return true;
            }

            return $this->handlePlacement(
                $transaction->blockPosition,
                $transaction->blockFace,
                $transaction->hotbarSlot,
                $transaction->hand->value,
                $transaction->clickPosition->x,
                $transaction->clickPosition->y,
                $transaction->clickPosition->z,
            );
        }
        if ($packet instanceof ContainerClosePacket) {
            if (!$this->initialized) {
                return false;
            }
            if (!$this->mainInventoryOpen && $packet->containerId === 1) {
                if ($this->commands->count() >= $this->limits->maximumCommandsPerPayload) {
                    return false;
                }
                $this->commands->enqueue($this->commandFactory->closeCraftingGrid($this->sessionId));

                return $this->queuePacket(new ContainerClosePacket(
                    1,
                    ContainerType::Workbench,
                    false,
                ));
            }
            if (!$this->mainInventoryOpen) {
                if ($this->commands->count() >= $this->limits->maximumCommandsPerPayload) {
                    return false;
                }
                $containerId = $packet->containerId === 0xff
                    ? ($this->pendingStorageCloseId ?? $this->storageContainerId ?? 0xff)
                    : $packet->containerId;
                $containerType = $packet->containerId === 0xff
                    ? ($this->pendingStorageCloseType ?? $this->storageContainerType ?? $packet->containerType)
                    : $packet->containerType;
                $this->commands->enqueue($this->commandFactory->closeContainer(
                    $this->sessionId,
                    $containerId,
                ));

                if (!$this->queuePacket(new ContainerClosePacket(
                    $containerId,
                    $containerType,
                    false,
                ))) {
                    return false;
                }
                if ($containerId === $this->storageContainerId) {
                    $this->storageContainerId = null;
                    $this->storageContainerType = null;
                }
                if ($containerId === $this->pendingStorageCloseId) {
                    $this->pendingStorageCloseId = null;
                    $this->pendingStorageCloseType = null;
                }

                return true;
            }
            if (!in_array($packet->containerId, [0, $this->mainInventoryId, 0xff], true)) {
                return true;
            }
            $this->mainInventoryOpen = false;
            $containerId = $this->mainInventoryId;
            $this->mainInventoryId = 0;
            if ($this->commands->count() >= $this->limits->maximumCommandsPerPayload) {
                return false;
            }
            $this->commands->enqueue($this->commandFactory->closeCraftingGrid($this->sessionId));

            return $this->queuePacket(new ContainerClosePacket(
                $containerId,
                ContainerType::Inventory,
                false,
            ));
        }
        if ($packet instanceof RequestAbilityPacket) {
            return $this->initialized && $this->queueAuthoritativeAbilities(
                $packet->ability === Ability::Flying->value ? $packet->boolValue : null,
            );
        }
        if ($packet instanceof ServerSettingsRequestPacket) {
            return true;
        }
        if ($packet instanceof NetworkStackLatencyPacket) {
            return $this->initialized;
        }
        if ($packet instanceof SetLocalPlayerAsInitializedPacket) {
            if (!$packet->runtimeEntityId->equals($this->runtimeEntityId)) {
                return false;
            }
            if ($this->initialized) {
                return true;
            }
            if (!$this->bootstrapSent) {
                return false;
            }
            if ($this->pendingHotbarSlot !== null
                && $this->commands->count() >= $this->limits->maximumCommandsPerPayload) {
                return false;
            }
            $this->initialized = true;
            if ($this->pendingHotbarSlot !== null) {
                $this->commands->enqueue($this->commandFactory->selectHotbarSlot(
                    $this->sessionId,
                    $this->pendingHotbarSlot,
                ));
                $this->pendingHotbarSlot = null;
            }
            $this->updateSpawnAcknowledged();

            return true;
        }
        if ($packet instanceof RequestChunkRadiusPacket) {
            if ($this->chunkView !== null) {
                return $this->queuePacket(new ChunkRadiusUpdatedPacket($this->chunkView->radius()));
            }
            $radius = min($packet->radius, $packet->maximumRadius, $this->viewDistance, $this->limits->maximumChunkRadius);
            if (!$this->queuePacket(new ChunkRadiusUpdatedPacket($radius))) {
                return false;
            }
            if ($this->flatWorld !== null && $this->chunkSerializer !== null) {
                $prefetchRadius = min($this->chunkPrefetchRadius, 32 - $radius);
                $this->chunkView = new ChunkViewManager($radius, $prefetchRadius);
                $this->chunkView->centerOnBlock($this->spawnX, $this->spawnZ);
                if (!$this->queuePacket(new NetworkChunkPublisherUpdatePacket(
                    (int) floor($this->spawnX),
                    (int) floor($this->spawnY),
                    (int) floor($this->spawnZ),
                    $radius * 16,
                ))) {
                    return false;
                }
                $deferredPackets = $this->deferredInitializationPackets;
                $this->deferredInitializationPackets = [];
                foreach ($deferredPackets as $deferred) {
                    if (!$this->queuePending($this->pendingBootstrapPackets, $deferred)) {
                        return false;
                    }
                }

                return true;
            }
            if ($this->bootstrapSent) {
                return true;
            }
            $deferredPackets = $this->deferredInitializationPackets;
            $this->deferredInitializationPackets = [];
            foreach ($deferredPackets as $deferred) {
                if (!$this->queuePending($this->pendingBootstrapPackets, $deferred)) {
                    return false;
                }
            }
            $this->bootstrapSent = true;
            if ($this->initialized) {
                $this->updateSpawnAcknowledged();
            }

            return true;
        }
        if ($packet instanceof SubChunkRequestPacket) {
            if ($this->flatWorld !== null) {
                return false;
            }
            if (!$this->bootstrapSent || $packet->dimension !== 0
                || count($packet->offsets) > $this->limits->maximumSubChunkOffsetsPerRequest) {
                return false;
            }
            $response = SubChunkPacket::fixedFlat(
                $packet,
                $this->fixedFlatRuntimeIds,
                $this->limits->maximumChunkRadius,
            );
            if (!$this->queuePending($this->pendingStreamingResponses, $response)) {
                return false;
            }
            $fingerprint = hash('sha256', $packet->encode());
            $repeated = isset($this->trackedSubChunkRequests[$fingerprint]);
            if (!$repeated && count($this->trackedSubChunkRequests) < $this->limits->maximumTrackedSubChunkRequests) {
                $this->trackedSubChunkRequests[$fingerprint] = true;
            }
            $newSections = 0;
            foreach ($packet->offsets as $offset) {
                $chunkX = $packet->centerX + $offset['x'];
                $sectionY = $packet->centerY + $offset['y'];
                $chunkZ = $packet->centerZ + $offset['z'];
                if (abs($chunkX) > $this->limits->maximumChunkRadius
                    || abs($chunkZ) > $this->limits->maximumChunkRadius || $sectionY < -4 || $sectionY > 3) {
                    continue;
                }
                $key = $chunkX . ':' . $sectionY . ':' . $chunkZ;
                if (!isset($this->servedSubChunkSections[$key])
                    && count($this->servedSubChunkSections) < $this->limits->maximumTrackedSubChunkSections) {
                    $this->servedSubChunkSections[$key] = true;
                    ++$newSections;
                }
            }
            $this->diagnostics->record('play.subchunk_request', [
                'offsets' => count($packet->offsets),
                'repeated' => $repeated,
                'new_sections' => $newSections,
                'tracked_sections' => count($this->servedSubChunkSections),
            ]);
            $this->updateSpawnAcknowledged();

            return true;
        }
        if ($packet instanceof PlayerAuthInputPacket) {
            if (!$this->initialized
                || $this->commands->count() >= $this->limits->maximumCommandsPerPayload) {
                return false;
            }
            if ($packet->hasInput(PlayerAuthInputFlag::StartFlying)
                || $packet->hasInput(PlayerAuthInputFlag::StopFlying)) {
                if (!$this->handleFlightFlags($packet)) {
                    return false;
                }
            }
            $freshMovement = $this->lastMovementTick === null
                || $packet->tick->compareTo($this->lastMovementTick) > 0;
            if ($freshMovement) {
                if ($this->movementSequence === PHP_INT_MAX) {
                    return false;
                }
                $this->lastMovementTick = $packet->tick;
                ++$this->movementSequence;
                if ($packet->hasInput(PlayerAuthInputFlag::StopSneaking)) {
                    $this->sneaking = false;
                } elseif ($packet->hasInput(PlayerAuthInputFlag::StartSneaking)
                    || $packet->hasInput(PlayerAuthInputFlag::Sneaking)
                    || $packet->hasInput(PlayerAuthInputFlag::SneakCurrentRaw)) {
                    $this->sneaking = true;
                }
                if ($packet->hasInput(PlayerAuthInputFlag::StopSprinting)) {
                    $this->sprinting = false;
                } elseif ($packet->hasInput(PlayerAuthInputFlag::StartSprinting)
                    || $packet->hasInput(PlayerAuthInputFlag::Sprinting)) {
                    $this->sprinting = true;
                }
                $magnitude = hypot($packet->moveX, $packet->moveZ);
                $mode = match (true) {
                    $this->sneaking => MovementMode::CROUCHING,
                    $this->sprinting => MovementMode::SPRINTING,
                    $packet->jumpHeld(), $packet->jumpPressed() => MovementMode::JUMPING,
                    $magnitude < 0.000_001 => MovementMode::STOPPED,
                    default => MovementMode::WALKING,
                };
                $yaw = self::normalizeYaw($packet->yaw);
                $pitch = fmod($packet->pitch, 360.0);
                $vehiclePitch = $packet->vehicleRotationPitch === null
                    ? null
                    : fmod($packet->vehicleRotationPitch, 360.0);
                $vehicleYaw = $packet->vehicleRotationYaw === null
                    ? null
                    : self::normalizeYaw($packet->vehicleRotationYaw);
                $vehicleControlYaw = $vehicleYaw === null
                    ? null
                    : $yaw;
                $this->enqueueMovementCommand($this->commandFactory->move(
                    $this->sessionId,
                    $this->movementSequence,
                    $packet->wireX,
                    $packet->feetY(),
                    $packet->wireZ,
                    $yaw,
                    $pitch,
                    $mode,
                    $packet->deltaX,
                    $packet->deltaY,
                    $packet->deltaZ,
                    $packet->jumpHeld() || $packet->jumpPressed(),
                    $yaw,
                    $this->sneaking,
                    $this->sprinting,
                    new ClientInputTick($packet->tick->high, $packet->tick->low),
                    $this->lastRequestedFlyingState === true,
                    $packet->hasInput(PlayerAuthInputFlag::VerticalCollision),
                    $packet->moveX,
                    $packet->moveZ,
                    $vehiclePitch,
                    $vehicleYaw,
                    $vehicleControlYaw,
                    $packet->predictedVehicleActorId,
                    $packet->hasInput(PlayerAuthInputFlag::PaddlingLeft),
                    $packet->hasInput(PlayerAuthInputFlag::PaddlingRight),
                ));
                if ($packet->hasInput(PlayerAuthInputFlag::MissedSwing)) {
                    $this->commands->enqueue($this->commandFactory->swingArm(
                        $this->sessionId,
                        ArmSwingSource::Missed,
                    ));
                }
            }

            if ($packet->itemUseTransaction !== null && !$this->handleEmbeddedPlacement($packet->itemUseTransaction)) {
                return false;
            }
            if ($packet->itemStackRequest !== null && self::isMineBlockRequest($packet->itemStackRequest)) {
                if ($packet->blockActions !== null && !$this->handleBlockActions($packet->blockActions)) {
                    return false;
                }
                if (!$this->handleItemStackRequests([$packet->itemStackRequest])) {
                    return false;
                }
            } else {
                if ($packet->itemStackRequest !== null
                    && !$this->handleItemStackRequests([$packet->itemStackRequest])) {
                    return false;
                }
                if ($packet->blockActions !== null && !$this->handleBlockActions($packet->blockActions)) {
                    return false;
                }
            }

            return true;
        }
        if ($packet instanceof ItemStackRequestPacket) {
            return $this->initialized && $this->handleItemStackRequests($packet->requests);
        }
        if ($packet instanceof SetPlayerInventoryOptionsPacket) {
            return $this->initialized;
        }
        if ($packet instanceof SetPlayerFurnaceOptionsPacket) {
            // Recipe-book presentation preferences are client-owned and do not mutate furnace state.
            return $this->initialized;
        }
        if ($packet instanceof MapInfoRequestPacket) {
            // Map rendering is supplied only for server-owned map IDs; unknown requests are harmless.
            return $this->initialized;
        }
        if ($packet instanceof ChatPacket) {
            if (!$this->initialized || $this->commands->count() >= $this->limits->maximumCommandsPerPayload) {
                return false;
            }
            $this->commands->enqueue($this->commandFactory->chat($this->sessionId, ++$this->chatSequence, $packet->message));

            return true;
        }

        return false;
    }

    private static function normalizeYaw(float $yaw): float
    {
        $normalized = fmod($yaw, 360.0);

        return $normalized < 0.0 ? $normalized + 360.0 : $normalized;
    }

    private function enqueueMovementCommand(MovePlayer $command): void
    {
        $index = $this->commands->count() - 1;
        $previous = $index >= 0 ? $this->commands->offsetGet($index) : null;
        if (!$previous instanceof MovePlayer) {
            $this->commands->enqueue($command);
            return;
        }
        if ($previous->jumpRequested && !$command->jumpRequested) {
            $command = new MovePlayer(
                $command->session,
                $command->sequence,
                $command->position,
                $command->yaw,
                $command->pitch,
                $command->mode,
                $command->deltaX,
                $command->deltaY,
                $command->deltaZ,
                true,
                $command->headYaw,
                $command->sneaking,
                $command->sprinting,
                $command->clientTick,
                $command->flying,
                $command->verticalCollision,
                $command->moveX,
                $command->moveZ,
                $command->vehiclePitch,
                $command->vehicleYaw,
                $command->vehicleControlYaw,
                $command->predictedVehicleActorId,
                $command->paddlingLeft,
                $command->paddlingRight,
            );
        }
        $this->commands->offsetSet($index, $command);
    }

    private function handleLegacyInventoryTransaction(InventoryTransactionPacket $packet): bool
    {
        if (count($packet->legacySlots) > self::MAXIMUM_LEGACY_SLOT_SYNC_GROUPS) {
            return false;
        }
        $requestedCorrections = self::legacyRequestedSlotCorrections($packet);
        $requiredCommandCapacity = $requestedCorrections === [] ? 1 : 2;
        if ($this->commands->count() > $this->limits->maximumCommandsPerPayload - $requiredCommandCapacity) {
            return false;
        }
        $drop = $this->legacyDropIntent($packet);
        if ($drop !== null) {
            if (is_string($drop)) {
                $this->commands->enqueue($this->commandFactory->inventoryStackRequest(
                    $this->sessionId,
                    $packet->legacyRequestId,
                    [],
                    $drop,
                    InventoryResponseMode::LegacySlotSync,
                ));
            } else {
                $this->commands->enqueue($this->commandFactory->dropItem(
                    $this->sessionId,
                    $packet->legacyRequestId,
                    $drop['source'],
                    $drop['count'],
                    InventoryResponseMode::LegacySlotSync,
                    $drop['expected'],
                ));
            }
            if ($requestedCorrections !== []) {
                $this->commands->enqueue($this->commandFactory->syncInventorySlots(
                    $this->sessionId,
                    $requestedCorrections,
                ));
            }

            return true;
        }
        [$actions, $rejectionReason] = $this->legacyInventoryActions($packet);
        $this->commands->enqueue($this->commandFactory->inventoryStackRequest(
            $this->sessionId,
            $packet->legacyRequestId,
            $actions,
            $rejectionReason,
            InventoryResponseMode::LegacySlotSync,
        ));
        if ($requestedCorrections !== []) {
            $this->commands->enqueue($this->commandFactory->syncInventorySlots(
                $this->sessionId,
                $requestedCorrections,
            ));
        }

        return true;
    }

    /** @return list<InventorySlotReference> */
    private static function legacyRequestedSlotCorrections(InventoryTransactionPacket $packet): array
    {
        $corrections = [];
        foreach ($packet->legacySlots as $slotSet) {
            $slotCount = strlen($slotSet->slots);
            for ($offset = 0; $offset < $slotCount; ++$offset) {
                $networkSlot = ord($slotSet->slots[$offset]);
                $container = match ($slotSet->containerId) {
                    FullContainerName::COMBINED_HOTBAR_AND_INVENTORY,
                    FullContainerName::HOTBAR,
                    FullContainerName::INVENTORY => InventoryContainer::Main,
                    FullContainerName::ARMOR => InventoryContainer::Armor,
                    FullContainerName::OFFHAND => InventoryContainer::Offhand,
                    FullContainerName::CURSOR => InventoryContainer::Cursor,
                    FullContainerName::LEVEL_ENTITY,
                    FullContainerName::SHULKER_BOX,
                    FullContainerName::BARREL,
                    FullContainerName::DYNAMIC => InventoryContainer::OpenedContainer,
                    default => null,
                };
                $internalSlot = $container === InventoryContainer::Offhand ? 0 : $networkSlot;
                if ($container === null
                    || ($container === InventoryContainer::Main && $internalSlot >= PlayerInventory::SLOT_COUNT)
                    || ($container === InventoryContainer::Armor && $internalSlot >= PlayerInventory::ARMOR_SLOT_COUNT)
                    || ($container === InventoryContainer::Cursor && $internalSlot !== 0)) {
                    continue;
                }
                $reference = new InventorySlotReference(
                    $container,
                    $internalSlot,
                    0,
                    $slotSet->containerId,
                    responseSlot: $networkSlot,
                );
                $corrections[$reference->key()] = $reference;
            }
        }

        return array_values($corrections);
    }

    /** @return null|string|array{source: InventorySlotReference, count: int, expected: \Bedriox\Server\Player\InventoryStack} */
    private function legacyDropIntent(InventoryTransactionPacket $packet): null|string|array
    {
        $containsDrop = false;
        foreach ($packet->actions as $action) {
            $containsDrop = $containsDrop || ($action->source->type === InventorySourceType::WorldInteraction
                && $action->source->flag === InventorySourceFlag::DropItem);
        }
        if (!$containsDrop) {
            return null;
        }
        if ($packet->transaction->type() !== InventoryTransactionType::Normal
            || count($packet->actions) !== 2 || $this->inventoryProjector === null) {
            return 'invalid_drop';
        }
        $world = null;
        $container = null;
        foreach ($packet->actions as $action) {
            if ($action->source->type === InventorySourceType::WorldInteraction
                && $action->source->flag === InventorySourceFlag::DropItem) {
                $world = $action;
            } elseif ($action->source->type === InventorySourceType::Container
                && $action->source->containerId === 0
                && $action->slot < 36) {
                $container = $action;
            }
        }
        if ($world === null || $container === null || $world->fromItem->runtimeId !== 0
            || $world->toItem->count < 1
            || $container->fromItem->count - $container->toItem->count !== $world->toItem->count) {
            return 'invalid_drop';
        }
        try {
            $expected = $this->inventoryProjector->fromProtocol($container->fromItem);
            $dropped = $this->inventoryProjector->fromProtocol($world->toItem);
            $remaining = $container->toItem->runtimeId === 0
                ? null
                : $this->inventoryProjector->fromProtocol($container->toItem);
        } catch (\InvalidArgumentException) {
            return 'unsupported_item';
        }
        if (!self::sameInventoryContent($expected, $dropped)
            || ($remaining !== null && !self::sameInventoryContent($expected, $remaining))) {
            return 'source_item';
        }

        return [
            'source' => new InventorySlotReference(
                InventoryContainer::Main,
                $container->slot,
                $container->fromItem->stackNetworkId ?? 0,
                FullContainerName::INVENTORY,
                $container->fromItem->count,
            ),
            'count' => $world->toItem->count,
            'expected' => $expected,
        ];
    }

    private static function sameInventoryContent(
        \Bedriox\Server\Player\InventoryStack $left,
        \Bedriox\Server\Player\InventoryStack $right,
    ): bool {
        return $left->identifier === $right->identifier
            && $left->damage === $right->damage
            && $left->auxValue === $right->auxValue
            && ($left->nbt?->toBinary() ?? '') === ($right->nbt?->toBinary() ?? '')
            && $left->placedBlockState?->value === $right->placedBlockState?->value;
    }

    /** @return array{list<InventoryStackRequestAction>, ?string} */
    private function legacyInventoryActions(InventoryTransactionPacket $packet): array
    {
        if ($packet->transaction->type() === InventoryTransactionType::InventoryMismatch) {
            return [[], 'inventory_mismatch'];
        }
        if ($packet->actions === []) {
            return [[], 'empty_actions'];
        }

        /** @var array<string, array{reference: InventorySlotReference, count: int}> $decreases */
        $decreases = [];
        /** @var array<string, array{reference: InventorySlotReference, count: int}> $increases */
        $increases = [];
        $seen = [];
        foreach ($packet->actions as $action) {
            $reference = self::legacyInventoryReference(
                $action->source->type,
                $action->source->containerId,
                $action->slot,
                $action->fromItem,
            );
            if ($reference === null || isset($seen[$reference->key()])) {
                return [[], $reference === null ? 'unsupported_container' : 'duplicate_slot'];
            }
            $seen[$reference->key()] = true;
            $fromKind = self::legacyItemKind($action->fromItem);
            $toKind = self::legacyItemKind($action->toItem);
            if ($fromKind === null || $toKind === null) {
                return [[], 'unsupported_item'];
            }
            if ($fromKind !== '' && $toKind !== '' && $fromKind !== $toKind) {
                return [[], 'item_conversion'];
            }
            if ($action->fromItem->count === $action->toItem->count && $fromKind === $toKind) {
                continue;
            }
            if ($action->fromItem->count > $action->toItem->count) {
                if ($fromKind === '') {
                    return [[], 'source_item'];
                }
                $key = $fromKind;
                if (isset($decreases[$key])) {
                    return [[], 'complex_transfer'];
                }
                $decreases[$key] = [
                    'reference' => $reference,
                    'count' => $action->fromItem->count - $action->toItem->count,
                ];
            } else {
                if ($toKind === '') {
                    return [[], 'destination_item'];
                }
                $key = $toKind;
                if (isset($increases[$key])) {
                    return [[], 'complex_transfer'];
                }
                $increases[$key] = [
                    'reference' => $reference,
                    'count' => $action->toItem->count - $action->fromItem->count,
                ];
            }
        }

        if ($decreases === [] || array_keys($decreases) !== array_keys($increases)) {
            return [[], 'unbalanced_transfer'];
        }
        $translated = [];
        foreach ($decreases as $kind => $source) {
            $destination = $increases[$kind];
            if ($source['count'] !== $destination['count']) {
                return [[], 'unbalanced_transfer'];
            }
            $translated[] = new InventoryStackRequestAction(
                InventoryStackRequestActionType::Take,
                $source['reference'],
                $destination['reference'],
                $source['count'],
            );
        }

        return [$translated, null];
    }

    private static function legacyInventoryReference(
        InventorySourceType $sourceType,
        ?int $containerId,
        int $slot,
        InventoryItemStack $fromItem,
    ): ?InventorySlotReference {
        if ($sourceType !== InventorySourceType::Container) {
            return null;
        }
        $container = match ($containerId) {
            InventoryContainerId::INVENTORY => InventoryContainer::Main,
            InventoryContainerId::UI => InventoryContainer::Cursor,
            InventoryContainerId::ARMOR => InventoryContainer::Armor,
            InventoryContainerId::OFFHAND => InventoryContainer::Offhand,
            default => $containerId !== null && $containerId >= 2 && $containerId <= 99
                ? InventoryContainer::OpenedContainer
                : null,
        };
        if ($container === null || $slot < 0
            || ($container === InventoryContainer::Main && $slot >= PlayerInventory::SLOT_COUNT)
            || ($container === InventoryContainer::Armor && $slot >= PlayerInventory::ARMOR_SLOT_COUNT)
            || (($container === InventoryContainer::Cursor || $container === InventoryContainer::Offhand)
                && $slot !== 0)
            || ($container === InventoryContainer::OpenedContainer && $slot > 0xff)) {
            return null;
        }
        $networkId = $fromItem->runtimeId === 0 && $fromItem->count === 0
            ? 0
            : $fromItem->stackNetworkId;
        if ($networkId === null || $networkId < 0) {
            return null;
        }

        return new InventorySlotReference($container, $slot, $networkId, expectedCount: $fromItem->count);
    }

    /** Empty stacks use an empty key; null means the descriptor is unsupported or inconsistent. */
    private static function legacyItemKind(InventoryItemStack $item): ?string
    {
        if ($item->runtimeId === 0) {
            return $item->count === 0 && $item->aux === 0 && $item->stackNetworkId === null
                && $item->blockRuntimeId === 0 && $item->userData === '' ? '' : null;
        }
        if ($item->count < 1 || $item->count > 64 || $item->stackNetworkId === null) {
            return null;
        }

        return hash('sha256', implode(':', [$item->runtimeId, $item->aux, $item->blockRuntimeId]) . "\0" . $item->userData);
    }

    /** @param list<PlayerBlockAction> $actions */
    private function handleBlockActions(array $actions): bool
    {
        if (count($actions) > $this->limits->maximumCommandsPerPayload - $this->commands->count()) {
            return false;
        }
        foreach ($actions as $action) {
            $intent = match ($action->action) {
                PlayerActionType::StartDestroyBlock, PlayerActionType::ContinueDestroyBlock => BlockBreakAction::Start,
                PlayerActionType::AbortDestroyBlock, PlayerActionType::StopDestroyBlock => BlockBreakAction::Abort,
                PlayerActionType::PredictDestroyBlock => BlockBreakAction::Complete,
                default => null,
            };
            if ($intent === null) {
                continue;
            }
            if ($this->blockSequence === PHP_INT_MAX) {
                return false;
            }
            $position = $action->position;
            if ($intent !== BlockBreakAction::Abort) {
                if ($position === null || $action->face === null || $action->face < 0 || $action->face > 5
                    || !$this->hasLoadedBlock($position)) {
                    continue;
                }
            }
            $commandPosition = $intent === BlockBreakAction::Abort || $position === null
                ? null
                : new WorldBlockPosition($position->x, $position->y, $position->z);
            $this->commands->enqueue($this->commandFactory->breakBlock(
                $this->sessionId,
                ++$this->blockSequence,
                $intent,
                $commandPosition,
                $intent === BlockBreakAction::Abort ? 0 : ($action->face ?? 0),
            ));
        }

        return true;
    }

    private function handleEmbeddedPlacement(PlayerItemUseTransaction $transaction): bool
    {
        if ($transaction->actionType !== ItemUseActionType::Place->value) {
            return true;
        }

        return $this->handlePlacement(
            $transaction->blockPosition,
            $transaction->blockFace,
            $transaction->hotbarSlot,
            $transaction->hand,
            $transaction->clickX,
            $transaction->clickY,
            $transaction->clickZ,
        );
    }

    /** @param list<ItemStackRequest> $requests */
    private function handleItemStackRequests(array $requests): bool
    {
        if (count($requests) > $this->limits->maximumCommandsPerPayload - $this->commands->count()) {
            return false;
        }
        foreach ($requests as $request) {
            $actions = [];
            $dropSource = null;
            $dropCount = 0;
            $creativeStack = null;
            $crafting = null;
            $workstation = null;
            $sawCreativeSelection = false;
            $sawMineBlock = false;
            $rejectionReason = $request->actions === [] ? 'empty_actions' : null;
            foreach ($request->actions as $action) {
                if ($action instanceof DropItemStackRequestAction) {
                    $this->diagnostics->record('play.inventory_request.protocol_trace', [
                        'request_id' => $request->requestId,
                        'action' => 'drop',
                        'amount' => $action->amount,
                        'source_container' => $action->source->containerName->containerNameId,
                        'source_slot' => $action->source->slot,
                        'source_stack_id' => $action->source->stackNetworkId,
                    ]);
                    $dropSource = self::inventorySlotReference($action->source);
                    $dropCount = $action->amount;
                    if ($dropSource === null) {
                        $rejectionReason = 'unsupported_container';
                        break;
                    }
                } elseif ($action instanceof CraftCreativeItemStackRequestAction) {
                    $this->diagnostics->record('play.inventory_request.protocol_trace', [
                        'request_id' => $request->requestId,
                        'action' => 'craft_creative',
                        'creative_item_network_id' => $action->creativeItemNetworkId,
                        'requested_crafts' => $action->requestedCrafts,
                    ]);
                    if ($sawCreativeSelection || $this->inventoryProjector === null) {
                        $rejectionReason = $sawCreativeSelection
                            ? 'duplicate_creative_selection'
                            : 'creative_inventory_unavailable';
                        break;
                    }
                    try {
                        $creativeStack = $this->inventoryProjector->creativeStack(
                            $action->creativeItemNetworkId,
                            1,
                        );
                    } catch (\InvalidArgumentException) {
                        $rejectionReason = 'unknown_creative_item';
                        break;
                    }
                    $sawCreativeSelection = true;
                } elseif ($action instanceof CraftRecipeItemStackRequestAction
                    || $action instanceof AutoCraftRecipeItemStackRequestAction) {
                    $this->diagnostics->record('play.inventory_request.protocol_trace', [
                        'request_id' => $request->requestId,
                        'action' => $action instanceof AutoCraftRecipeItemStackRequestAction
                            ? 'craft_recipe_auto'
                            : 'craft_recipe',
                        'recipe_network_id' => $action->recipeNetworkId,
                        'requested_crafts' => $action->requestedCrafts,
                    ]);
                    if ($crafting !== null || $workstation !== null || $sawCreativeSelection) {
                        $rejectionReason = 'duplicate_crafting_selection';
                        break;
                    }
                    try {
                        if (!($action instanceof AutoCraftRecipeItemStackRequestAction)
                            && in_array(
                                $this->storageContainerType,
                                [ContainerType::Enchantment, ContainerType::Stonecutter],
                                true,
                            )) {
                            $workstation = new WorkstationRequest(
                                $this->storageContainerType === ContainerType::Enchantment
                                    ? WorkstationRequestType::ENCHANT
                                    : WorkstationRequestType::OPTIONAL_RECIPE,
                                $action->recipeNetworkId,
                                requestedCrafts: $action->requestedCrafts,
                                responseSlots: $this->storageContainerType === ContainerType::Enchantment
                                    ? $this->enchantingWorkstationResponseSlots()
                                    : [],
                            );
                        } else {
                            $crafting = new CraftingRequest(
                                $action->recipeNetworkId,
                                $action->requestedCrafts,
                                $action instanceof AutoCraftRecipeItemStackRequestAction,
                            );
                        }
                    } catch (\InvalidArgumentException) {
                        $rejectionReason = 'invalid_crafting_selection';
                        break;
                    }
                } elseif ($action instanceof CraftRecipeOptionalItemStackRequestAction) {
                    if ($workstation !== null || $crafting !== null || $sawCreativeSelection) {
                        $rejectionReason = 'duplicate_workstation_selection';
                        break;
                    }
                    $filteredText = null;
                    if ($request->filterStrings !== []) {
                        if (!isset($request->filterStrings[$action->filteredStringIndex])) {
                            $rejectionReason = 'invalid_filter_string';
                            break;
                        }
                        $filteredText = $request->filterStrings[$action->filteredStringIndex];
                    }
                    $workstation = new WorkstationRequest(
                        WorkstationRequestType::OPTIONAL_RECIPE,
                        $action->recipeNetworkId,
                        $filteredText,
                        responseSlots: $this->optionalWorkstationResponseSlots(),
                    );
                } elseif ($action instanceof CraftRepairAndDisenchantItemStackRequestAction) {
                    if ($workstation !== null || $crafting !== null || $sawCreativeSelection) {
                        $rejectionReason = 'duplicate_workstation_selection';
                        break;
                    }
                    $workstation = new WorkstationRequest(
                        WorkstationRequestType::REPAIR_AND_DISENCHANT,
                        $action->recipeNetworkId,
                        requestedCrafts: $action->requestedCrafts,
                        reportedCost: $action->repairCost,
                    );
                } elseif ($action instanceof CraftLoomItemStackRequestAction) {
                    if ($workstation !== null || $crafting !== null || $sawCreativeSelection) {
                        $rejectionReason = 'duplicate_workstation_selection';
                        break;
                    }
                    $workstation = new WorkstationRequest(
                        WorkstationRequestType::LOOM,
                        patternId: $action->patternId,
                        requestedCrafts: max(1, $action->timesCrafted),
                    );
                } elseif ($action instanceof CraftNonImplementedItemStackRequestAction) {
                    if ($workstation !== null || $crafting !== null || $sawCreativeSelection) {
                        $rejectionReason = 'duplicate_workstation_selection';
                        break;
                    }
                    $workstation = new WorkstationRequest(WorkstationRequestType::CLIENT_COMPUTED);
                } elseif ($action instanceof ConsumeItemStackRequestAction) {
                    $source = self::inventorySlotReference($action->source);
                    if (($crafting === null && $workstation === null) || $source === null
                        || ($workstation !== null && $source->container !== InventoryContainer::OpenedContainer)) {
                        $rejectionReason = 'invalid_crafting_consume';
                        break;
                    }
                    if ($workstation === null
                        && ((!$crafting->automatic && !in_array(
                            $source->container,
                            [InventoryContainer::CraftingInput, InventoryContainer::OpenedContainer],
                            true,
                        )) || ($crafting->automatic && !in_array(
                            $source->container,
                            [InventoryContainer::Main, InventoryContainer::CraftingInput],
                            true,
                        )))) {
                        $rejectionReason = 'invalid_crafting_consume';
                        break;
                    }
                    $actions[] = new InventoryStackRequestAction(
                        InventoryStackRequestActionType::Consume,
                        $source,
                        $source,
                        $action->amount,
                    );
                } elseif ($action instanceof CreateItemStackRequestAction) {
                    $this->diagnostics->record('play.inventory_request.protocol_trace', [
                        'request_id' => $request->requestId,
                        'action' => 'create',
                        'slot' => $action->slot,
                    ]);
                    if (!$sawCreativeSelection && $crafting === null && $workstation === null) {
                        $rejectionReason = 'create_without_selection';
                        break;
                    }
                    if ($crafting !== null || $workstation !== null) {
                        $createdOutput = new InventorySlotReference(
                            InventoryContainer::CreatedOutput,
                            50,
                            0,
                            FullContainerName::CREATED_OUTPUT,
                            responseSlot: 50,
                        );
                        $actions[] = new InventoryStackRequestAction(
                            InventoryStackRequestActionType::SelectCraftingResult,
                            $createdOutput,
                            $createdOutput,
                            $action->slot,
                        );
                    }
                } elseif ($action instanceof CraftResultsItemStackRequestAction) {
                    $this->diagnostics->record('play.inventory_request.protocol_trace', [
                        'request_id' => $request->requestId,
                        'action' => 'craft_results',
                        'result_count' => count($action->results),
                    ]);
                    if (!$sawCreativeSelection && $crafting === null && $workstation === null) {
                        $rejectionReason = 'craft_results_without_selection';
                        break;
                    }
                    // This client report is advisory; the advertised creative ID determines the server-owned stack.
                } elseif ($action instanceof TakeItemStackRequestAction || $action instanceof PlaceItemStackRequestAction) {
                    $this->diagnostics->record('play.inventory_request.protocol_trace', [
                        'request_id' => $request->requestId,
                        'action' => $action instanceof TakeItemStackRequestAction ? 'take' : 'place',
                        'amount' => $action->amount,
                        'source_container' => $action->source->containerName->containerNameId,
                        'source_slot' => $action->source->slot,
                        'source_stack_id' => $action->source->stackNetworkId,
                        'target_container' => $action->destination->containerName->containerNameId,
                        'target_slot' => $action->destination->slot,
                        'target_stack_id' => $action->destination->stackNetworkId,
                    ]);
                    $source = self::inventorySlotReference($action->source);
                    $destination = self::inventorySlotReference($action->destination);
                    if ($source === null || $destination === null) {
                        $rejectionReason = 'unsupported_container';
                        break;
                    }
                    if (!$sawCreativeSelection && $crafting === null && $workstation === null
                        && ($source->container === InventoryContainer::CreatedOutput
                            || $destination->container === InventoryContainer::CreatedOutput)) {
                        $rejectionReason = 'created_output_before_selection';
                        break;
                    }
                    $actions[] = new InventoryStackRequestAction(
                        $action instanceof TakeItemStackRequestAction
                            ? InventoryStackRequestActionType::Take
                            : InventoryStackRequestActionType::Place,
                        $source,
                        $destination,
                        $action->amount,
                    );
                } elseif ($action instanceof SwapItemStackRequestAction) {
                    $this->diagnostics->record('play.inventory_request.protocol_trace', [
                        'request_id' => $request->requestId,
                        'action' => 'swap',
                        'source_container' => $action->source->containerName->containerNameId,
                        'source_slot' => $action->source->slot,
                        'source_stack_id' => $action->source->stackNetworkId,
                        'target_container' => $action->destination->containerName->containerNameId,
                        'target_slot' => $action->destination->slot,
                        'target_stack_id' => $action->destination->stackNetworkId,
                    ]);
                    $source = self::inventorySlotReference($action->source);
                    $destination = self::inventorySlotReference($action->destination);
                    if ($source === null || $destination === null) {
                        $rejectionReason = 'unsupported_container';
                        break;
                    }
                    if (!$sawCreativeSelection && $crafting === null && $workstation === null
                        && ($source->container === InventoryContainer::CreatedOutput
                            || $destination->container === InventoryContainer::CreatedOutput)) {
                        $rejectionReason = 'created_output_before_selection';
                        break;
                    }
                    $actions[] = new InventoryStackRequestAction(
                        InventoryStackRequestActionType::Swap,
                        $source,
                        $destination,
                    );
                } elseif ($action instanceof MineBlockItemStackRequestAction) {
                    $this->diagnostics->record('play.inventory_request.protocol_trace', [
                        'request_id' => $request->requestId,
                        'action' => 'mine_block',
                        'hotbar_slot' => $action->hotbarSlot,
                        'predicted_durability' => $action->predictedDurability,
                        'stack_network_id' => $action->stackNetworkId,
                    ]);
                    if ($sawMineBlock || count($request->actions) !== 1
                        || $action->hotbarSlot < 0 || $action->hotbarSlot >= PlayerInventory::HOTBAR_SIZE) {
                        $rejectionReason = 'invalid_mine_block_prediction';
                        break;
                    }
                    $reference = new InventorySlotReference(
                        InventoryContainer::Main,
                        $action->hotbarSlot,
                        0,
                        FullContainerName::HOTBAR,
                        responseSlot: $action->hotbarSlot,
                    );
                    $actions[] = new InventoryStackRequestAction(
                        InventoryStackRequestActionType::MineBlock,
                        $reference,
                        $reference,
                    );
                    $sawMineBlock = true;
                } else {
                    $rejectionReason = 'unsupported_action';
                    break;
                }
            }
            if ($dropSource !== null && $rejectionReason === null) {
                if (count($request->actions) !== 1) {
                    $rejectionReason = 'mixed_drop_actions';
                } else {
                    $this->commands->enqueue($this->commandFactory->dropItem(
                        $this->sessionId,
                        $request->requestId,
                        $dropSource,
                        $dropCount,
                        InventoryResponseMode::ItemStackResponse,
                    ));
                    continue;
                }
            }
            $this->commands->enqueue($this->commandFactory->inventoryStackRequest(
                $this->sessionId,
                $request->requestId,
                $actions,
                $rejectionReason,
                authoritativeCreativeStack: $creativeStack,
                crafting: $crafting,
                workstation: $workstation,
            ));
        }

        return true;
    }

    private static function isMineBlockRequest(?ItemStackRequest $request): bool
    {
        if ($request === null) {
            return false;
        }
        foreach ($request->actions as $action) {
            if ($action instanceof MineBlockItemStackRequestAction) {
                return true;
            }
        }

        return false;
    }

    private static function inventorySlotReference(ItemStackRequestSlot $slot): ?InventorySlotReference
    {
        if (($slot->containerName->containerNameId === FullContainerName::DYNAMIC)
            !== ($slot->containerName->dynamicId !== null)) {
            return null;
        }
        $slotType = ContainerSlotType::tryFrom($slot->containerName->containerNameId);
        $workstationSlot = match ($slotType) {
            ContainerSlotType::AnvilInput,
            ContainerSlotType::SmithingTableTemplate,
            ContainerSlotType::EnchantingInput,
            ContainerSlotType::FurnaceIngredient,
            ContainerSlotType::BlastFurnaceIngredient,
            ContainerSlotType::SmokerIngredient,
            ContainerSlotType::LoomInput,
            ContainerSlotType::GrindstoneInput,
            ContainerSlotType::StonecutterInput,
            ContainerSlotType::CartographyInput => 0,
            ContainerSlotType::AnvilMaterial,
            ContainerSlotType::SmithingTableInput,
            ContainerSlotType::EnchantingMaterial,
            ContainerSlotType::FurnaceFuel,
            ContainerSlotType::LoomDye,
            ContainerSlotType::GrindstoneAdditional,
            ContainerSlotType::StonecutterResult,
            ContainerSlotType::CartographyAdditional => 1,
            ContainerSlotType::AnvilResult,
            ContainerSlotType::SmithingTableMaterial,
            ContainerSlotType::FurnaceResult,
            ContainerSlotType::LoomMaterial,
            ContainerSlotType::GrindstoneResult,
            ContainerSlotType::CartographyResult => 2,
            ContainerSlotType::SmithingTableResult,
            ContainerSlotType::LoomResult => 3,
            default => null,
        };
        $container = match ($slot->containerName->containerNameId) {
            FullContainerName::COMBINED_HOTBAR_AND_INVENTORY,
            FullContainerName::HOTBAR,
            FullContainerName::INVENTORY => InventoryContainer::Main,
            FullContainerName::ARMOR => InventoryContainer::Armor,
            FullContainerName::OFFHAND => InventoryContainer::Offhand,
            FullContainerName::CURSOR => InventoryContainer::Cursor,
            FullContainerName::CRAFTING_INPUT => InventoryContainer::CraftingInput,
            FullContainerName::CREATED_OUTPUT => InventoryContainer::CreatedOutput,
            FullContainerName::LEVEL_ENTITY,
            FullContainerName::SHULKER_BOX,
            FullContainerName::BARREL,
            FullContainerName::DYNAMIC => InventoryContainer::OpenedContainer,
            default => $workstationSlot === null ? null : InventoryContainer::OpenedContainer,
        };
        $internalSlot = match ($container) {
            InventoryContainer::Offhand => 0,
            InventoryContainer::CraftingInput => match (true) {
                $slot->slot >= 28 && $slot->slot <= 31 => $slot->slot - 28,
                $slot->slot >= 32 && $slot->slot <= 40 => $slot->slot - 32,
                default => -1,
            },
            InventoryContainer::OpenedContainer => $workstationSlot ?? $slot->slot,
            default => $slot->slot,
        };
        if ($container === null
            || $slot->slot < 0
            || ($container === InventoryContainer::Main && $slot->slot >= PlayerInventory::SLOT_COUNT)
            || ($container === InventoryContainer::Armor && $slot->slot >= PlayerInventory::ARMOR_SLOT_COUNT)
            || ($container === InventoryContainer::Cursor && $slot->slot !== 0)
            || ($container === InventoryContainer::CraftingInput && $internalSlot < 0)
            || ($container === InventoryContainer::CreatedOutput && $slot->slot !== 50)
            || ($container === InventoryContainer::OpenedContainer && $slot->slot > 0xff)) {
            return null;
        }

        return new InventorySlotReference(
            $container,
            $internalSlot,
            $slot->stackNetworkId,
            $slot->containerName->containerNameId,
            responseSlot: $slot->slot,
            responseContainerDynamicId: $slot->containerName->dynamicId,
        );
    }

    /** @return list<InventorySlotReference> */
    private function optionalWorkstationResponseSlots(): array
    {
        if ($this->storageContainerType !== ContainerType::Anvil) {
            return [];
        }

        return [
            new InventorySlotReference(InventoryContainer::OpenedContainer, 0, 0, ContainerSlotType::AnvilInput->value, responseSlot: 1),
            new InventorySlotReference(InventoryContainer::OpenedContainer, 1, 0, ContainerSlotType::AnvilMaterial->value, responseSlot: 2),
            new InventorySlotReference(InventoryContainer::OpenedContainer, 2, 0, ContainerSlotType::AnvilResult->value, responseSlot: 50),
            new InventorySlotReference(InventoryContainer::OpenedContainer, 2, 0, FullContainerName::CREATED_OUTPUT, responseSlot: 50),
        ];
    }

    /** @return list<InventorySlotReference> */
    private function enchantingWorkstationResponseSlots(): array
    {
        return [
            new InventorySlotReference(
                InventoryContainer::OpenedContainer,
                0,
                0,
                ContainerSlotType::EnchantingInput->value,
                responseSlot: 14,
            ),
            new InventorySlotReference(
                InventoryContainer::OpenedContainer,
                1,
                0,
                ContainerSlotType::EnchantingMaterial->value,
                responseSlot: 15,
            ),
            new InventorySlotReference(
                InventoryContainer::OpenedContainer,
                0,
                0,
                FullContainerName::CREATED_OUTPUT,
                responseSlot: 50,
            ),
        ];
    }

    private function handlePlacement(
        BlockPosition $clicked,
        int $face,
        int $hotbarSlot,
        int $hand,
        float $clickX,
        float $clickY,
        float $clickZ,
    ): bool {
        if ($this->inventoryProjector === null) {
            return true;
        }
        if ($this->placementSequence === PHP_INT_MAX
            || $this->commands->count() + 2 > $this->limits->maximumCommandsPerPayload) {
            return false;
        }
        if ($hotbarSlot < 0 || $hotbarSlot > 8) {
            return true;
        }
        $this->commands->enqueue($this->commandFactory->selectHotbarSlot(
            $this->sessionId,
            $hotbarSlot,
        ));
        if ($face < 0 || $face > 5 || $hand !== 0
            || $clickX < 0.0 || $clickX > 1.0 || $clickY < 0.0 || $clickY > 1.0
            || $clickZ < 0.0 || $clickZ > 1.0 || !$this->hasLoadedBlock($clicked)) {
            return true;
        }
        $adjacent = match ($face) {
            0 => [$clicked->x, $clicked->y - 1, $clicked->z],
            1 => [$clicked->x, $clicked->y + 1, $clicked->z],
            2 => [$clicked->x, $clicked->y, $clicked->z - 1],
            3 => [$clicked->x, $clicked->y, $clicked->z + 1],
            4 => [$clicked->x - 1, $clicked->y, $clicked->z],
            5 => [$clicked->x + 1, $clicked->y, $clicked->z],
        };
        if ($adjacent[1] < Chunk::MIN_Y || $adjacent[1] > Chunk::MAX_Y
            || !$this->hasSentChunkAt((float) $adjacent[0], (float) $adjacent[2])) {
            return true;
        }
        $this->commands->enqueue($this->commandFactory->placeBlock(
            $this->sessionId,
            ++$this->placementSequence,
            new WorldBlockPosition($clicked->x, $clicked->y, $clicked->z),
            $face,
            $hotbarSlot,
            $hand,
            $clickX,
            $clickY,
            $clickZ,
        ));

        return true;
    }

    private function hasLoadedBlock(BlockPosition $position): bool
    {
        if ($position->y < Chunk::MIN_Y
            || $position->y > Chunk::MAX_Y
            || $this->flatWorld === null || $this->chunkSerializer === null) {
            return false;
        }
        $chunk = new ChunkPosition((int) floor($position->x / 16.0), (int) floor($position->z / 16.0));
        if (!isset($this->retainedChunks[$chunk->key()]) || isset($this->queuedChunkKeys[$chunk->key()])) {
            return false;
        }

        return true;
    }

    private function queueSurvivalAbilities(): bool
    {
        return $this->queuePacket(UpdateAbilitiesPacket::survival($this->runtimeEntityId->toSignedBits()));
    }

    private function queueAuthoritativeAbilities(?bool $flying = null): bool
    {
        $packet = $this->authoritativeAbilities;
        if ($packet === null) {
            return $this->queueSurvivalAbilities();
        }
        if ($flying === null) {
            return $this->queuePacket($packet);
        }
        $layers = [];
        foreach ($packet->abilities->layers as $layer) {
            $enabled = $layer->abilityValues;
            if ($flying && $layer->enabled(Ability::MayFly)) {
                $enabled |= Ability::Flying->mask();
            } else {
                $enabled &= ~Ability::Flying->mask();
            }
            $layers[] = new AbilityLayer(
                $layer->type,
                $layer->abilitiesSet,
                $enabled,
                $layer->flySpeed,
                $layer->verticalFlySpeed,
                $layer->walkSpeed,
            );
        }

        return $this->queuePacket(new UpdateAbilitiesPacket(new PlayerAbilities(
            $packet->abilities->uniqueEntityId,
            $packet->abilities->playerPermission,
            $packet->abilities->commandPermission,
            $layers,
        )));
    }

    private function handleFlightFlags(PlayerAuthInputPacket $packet): bool
    {
        $start = $packet->hasInput(PlayerAuthInputFlag::StartFlying);
        $stop = $packet->hasInput(PlayerAuthInputFlag::StopFlying);
        if ($start === $stop || $this->lastRequestedFlyingState === $start) {
            return true;
        }
        $this->lastRequestedFlyingState = $start;

        return $this->queueAuthoritativeAbilities($start);
    }

    private function fail(string $reason, ?int $packetId = null, ?Throwable $exception = null): bool
    {
        $this->close($reason, $packetId, $exception);

        return false;
    }

    private function diagnose(string $message): void
    {
        $this->diagnostics->record('play.protocol_trace', ['detail' => $message]);
    }

    private function recordInboundTrace(int $packetId, int $bytes): void
    {
        $entry = $this->traceInboundPackets[$packetId] ?? ['packets' => 0, 'bytes' => 0];
        ++$entry['packets'];
        $entry['bytes'] += $bytes;
        $this->traceInboundPackets[$packetId] = $entry;
    }

    private function recordOutboundTrace(string $mode, int $packets, int $bytes): void
    {
        $entry = $this->traceOutboundBatches[$mode] ?? ['batches' => 0, 'packets' => 0, 'bytes' => 0];
        ++$entry['batches'];
        $entry['packets'] += $packets;
        $entry['bytes'] += $bytes;
        $this->traceOutboundBatches[$mode] = $entry;
    }

    private function flushProtocolTraceSummary(bool $force = false): void
    {
        $now = hrtime(true);
        if (!$force
            && $now - $this->traceWindowStartedNanoseconds < self::PROTOCOL_TRACE_SUMMARY_INTERVAL_NANOSECONDS) {
            return;
        }
        foreach ($this->traceInboundPackets as $packetId => $entry) {
            $this->diagnostics->record('play.input_summary.protocol_trace', [
                'session_id' => $this->sessionId,
                'packet_id' => $packetId,
                'packets' => $entry['packets'],
                'bytes' => $entry['bytes'],
            ]);
        }
        foreach ($this->traceOutboundBatches as $mode => $entry) {
            $this->diagnostics->record('play.output_summary.protocol_trace', [
                'session_id' => $this->sessionId,
                'mode' => $mode,
                'batches' => $entry['batches'],
                'packets' => $entry['packets'],
                'bytes' => $entry['bytes'],
            ]);
        }
        if ($this->tracePreparedChunks > 0) {
            $this->diagnostics->record('play.chunk_summary.protocol_trace', [
                'session_id' => $this->sessionId,
                'chunks' => $this->tracePreparedChunks,
                'bytes' => $this->tracePreparedChunkBytes,
            ]);
        }
        if ($this->traceInboundEnvelopes > 0) {
            $this->diagnostics->record('play.input_cost_summary.protocol_trace', [
                'session_id' => $this->sessionId,
                'envelopes' => $this->traceInboundEnvelopes,
                'decrypt_us' => intdiv($this->traceInboundDecryptNanoseconds, 1_000),
                'batch_us' => intdiv($this->traceInboundBatchNanoseconds, 1_000),
                'decode_us' => intdiv($this->traceInboundDecodeNanoseconds, 1_000),
                'handle_us' => intdiv($this->traceInboundHandleNanoseconds, 1_000),
            ]);
        }
        $this->traceInboundPackets = [];
        $this->traceOutboundBatches = [];
        $this->tracePreparedChunks = 0;
        $this->tracePreparedChunkBytes = 0;
        $this->traceInboundEnvelopes = 0;
        $this->traceInboundDecryptNanoseconds = 0;
        $this->traceInboundBatchNanoseconds = 0;
        $this->traceInboundDecodeNanoseconds = 0;
        $this->traceInboundHandleNanoseconds = 0;
        $this->traceWindowStartedNanoseconds = $now;
    }

    /**
     * @template T of Packet|ReusablePlayPacket
     * @param SplQueue<T> $queue
     * @param T $packet
     */
    private function queuePending(SplQueue $queue, Packet|ReusablePlayPacket $packet): bool
    {
        if ($this->closed || $this->totalQueuedPackets() >= $this->limits->maximumOutgoingPayloadsPerSession) {
            $source = $packet instanceof ReusablePlayPacket ? $packet->packet : $packet;
            return $this->fail('output_limit', BedrockPacketCodec::packetId($source));
        }
        $queue->enqueue($packet);

        return true;
    }

    private function totalQueuedPackets(): int
    {
        return $this->outgoing->count() + $this->pendingBootstrapPackets->count()
            + $this->pendingStreamingResponses->count() + count($this->deferredInitializationPackets)
            + ($this->outboundCompression?->outstandingCount() ?? 0)
            + $this->deferredCompressionBatches->count();
    }

    private function releaseCompressedBatches(): bool
    {
        if ($this->outboundCompression === null) {
            return true;
        }
        foreach ($this->outboundCompression->takeReady($this->limits->maximumOutgoingPayloadsPerSession) as $batch) {
            if ($batch->payload === null || $batch->failureCode !== null) {
                return $this->fail('compression_failed');
            }
            try {
                $envelope = $this->encryptor->encryptEnvelope($batch->payload);
            } catch (Throwable $error) {
                return $this->fail('encode_failed', exception: $error);
            }
            if (strlen($envelope) > $this->limits->maximumOutgoingBytesPerSession - $this->outgoingBytes) {
                return $this->fail('output_limit');
            }
            $this->outgoing->enqueue(new OutgoingPlayPayload($envelope));
            $this->outgoingBytes += strlen($envelope);
        }

        return true;
    }

    private function canRetainDeferredCompression(string $batch): bool
    {
        $retained = $this->outgoingBytes + $this->deferredCompressionBytes
            + ($this->outboundCompression?->outstandingBytes() ?? 0);

        return strlen($batch) <= $this->limits->maximumOutgoingBytesPerSession - $retained;
    }

    private function updateSpawnAcknowledged(): void
    {
        if (!$this->admissionReleased && $this->initialized && $this->bootstrapSent) {
            $this->admissionReleased = true;
            $this->spawnAcknowledged = true;
        }
    }

    private function generateChunks(ChunkStreamingBudget $streamingBudget): bool
    {
        if ($this->chunkView === null || $this->flatWorld === null || $this->chunkSerializer === null) {
            return true;
        }
        $view = $this->chunkView;
        $world = $this->flatWorld;
        $attempted = 0;
        foreach ($view->pending(max(1, $view->pendingCount())) as $coordinate) {
            if ($this->generatedChunks->count() >= $this->limits->maximumOutgoingPayloadsPerSession) {
                break;
            }
            $position = new ChunkPosition($coordinate['x'], $coordinate['z']);
            $key = $position->key();
            if (isset($this->queuedChunkKeys[$key])) {
                continue;
            }
            if ($view->isPrepared($position->x, $position->z)) {
                $this->queuedChunkKeys[$key] = true;
                $this->generatedChunks->enqueue($position);
                continue;
            }
            if (!isset($this->pendingChunkRequests[$key])
                && count($this->pendingChunkRequests) >= $this->chunkGenerationQueueSize) {
                break;
            }
            if ($attempted >= $this->chunksGeneratePerTick || !$streamingBudget->claimGeneration()) {
                break;
            }
            ++$attempted;
            try {
                if (!$world->requestRetainChunk($position, false)) {
                    $this->pendingChunkRequests[$key] = $position;
                    continue;
                }
            } catch (Throwable $exception) {
                return $this->fail('chunk_generation_failed', exception: $exception);
            }
            unset($this->pendingChunkRequests[$key]);
            $this->retainedChunks[$key] = $position;
            $view->markPrepared($position->x, $position->z);
            $this->queuedChunkKeys[$key] = true;
            $this->generatedChunks->enqueue($position);
        }

        if ($view->unpreparedVisibleCount() > 0) {
            return true;
        }
        foreach ($view->pendingPrefetch(max(1, $view->prefetchPendingCount())) as $coordinate) {
            if ($this->generatedChunks->count() >= $this->limits->maximumOutgoingPayloadsPerSession) {
                break;
            }
            $position = new ChunkPosition($coordinate['x'], $coordinate['z']);
            $key = $position->key();
            if ($view->isPrepared($position->x, $position->z)) {
                continue;
            }
            if (!isset($this->pendingChunkRequests[$key])
                && count($this->pendingChunkRequests) >= $this->chunkGenerationQueueSize) {
                break;
            }
            if ($attempted >= $this->chunksGeneratePerTick || !$streamingBudget->claimGeneration()) {
                break;
            }
            ++$attempted;
            try {
                if (!$world->requestRetainChunk($position, false)) {
                    $this->pendingChunkRequests[$key] = $position;
                    continue;
                }
            } catch (Throwable $exception) {
                return $this->fail('chunk_generation_failed', exception: $exception);
            }
            unset($this->pendingChunkRequests[$key]);
            $this->retainedChunks[$key] = $position;
            $view->markPrepared($position->x, $position->z);
        }

        return true;
    }

    private function sendGeneratedChunks(ChunkStreamingBudget $streamingBudget): bool
    {
        if ($this->chunkView === null || $this->flatWorld === null || $this->chunkSerializer === null) {
            return true;
        }
        if (!$this->releaseCompressedBatches()) {
            return false;
        }
        $view = $this->chunkView;
        $world = $this->flatWorld;
        $sent = 0;
        $sentBytes = 0;
        $startedAt = hrtime(true);
        while ($sent < $this->chunksSendPerTick
            && $streamingBudget->hasDeliveryCapacity()
            && !$this->generatedChunks->isEmpty()) {
            /** @var ChunkPosition $position */
            $position = $this->generatedChunks->bottom();
            $key = $position->key();
            if (!$view->contains($position->x, $position->z)) {
                $this->generatedChunks->dequeue();
                unset($this->queuedChunkKeys[$key]);
                $this->releaseChunk($position->x, $position->z);
                continue;
            }
            try {
                $chunk = $world->chunk($position);
            } catch (Throwable $exception) {
                $this->generatedChunks->dequeue();
                unset($this->queuedChunkKeys[$key]);
                $this->releaseChunk($position->x, $position->z);

                return $this->fail('chunk_serialization_failed', exception: $exception);
            }
            $prepared = null;
            if ($this->preparedChunks !== null) {
                $lookup = $this->preparedChunks->lookupOrRequest($chunk, $this->protocolVersion);
                if ($lookup->availability === PreparedChunkAvailability::PENDING
                    || $lookup->availability === PreparedChunkAvailability::DEFERRED) {
                    break;
                }
                if ($lookup->availability === PreparedChunkAvailability::READY) {
                    $prepared = $lookup->chunk;
                    if (!$prepared instanceof PreparedChunk
                        || !$this->preparedChunks->isCurrent($prepared, $chunk, $this->protocolVersion)) {
                        break;
                    }
                } elseif ($lookup->availability === PreparedChunkAvailability::SYNCHRONOUS_FALLBACK) {
                    if ($this->hasOutboundOrderingBarrier()) {
                        break;
                    }
                    try {
                        $packet = $this->chunkSerializer->serialize($chunk, $world->dimension());
                        $frame = new PacketFrame(
                            new PacketHeader(BedrockPacketCodec::packetId($packet)),
                            BedrockPacketCodec::encode($packet, $this->protocolVersion),
                        );
                        $clearEnvelope = BedrockBatchCodec::encode(new BedrockBatch(
                            [$frame],
                            CompressionMode::NegotiatedZlib,
                            NetworkCompressionPolicy::THRESHOLD_BYTES,
                        ), $this->batchLimits);
                        $prepared = $this->preparedChunks->retainSynchronous(
                            $chunk,
                            $this->protocolVersion,
                            $clearEnvelope,
                        );
                    } catch (Throwable $exception) {
                        return $this->fail('chunk_preparation_failed', exception: $exception);
                    }
                }
            }

            if ($prepared instanceof PreparedChunk) {
                if ($this->hasOutboundOrderingBarrier()
                    || ($sent > 0 && $sentBytes + $prepared->bytes() > self::CHUNK_DELIVERY_BYTES_PER_TICK)
                    || ($sent > 0 && hrtime(true) - $startedAt >= self::CHUNK_DELIVERY_NANOSECONDS_PER_TICK)) {
                    break;
                }
                if (!$this->queuePreparedChunk($prepared)) {
                    if ($this->closed) {
                        $this->releaseChunk($position->x, $position->z);

                        return false;
                    }

                    break;
                }
                $sentBytes += $prepared->bytes();
            } else {
                try {
                    $packet = $this->chunkSerializer->serialize($chunk, $world->dimension());
                } catch (Throwable $exception) {
                    $this->generatedChunks->dequeue();
                    unset($this->queuedChunkKeys[$key]);
                    $this->releaseChunk($position->x, $position->z);

                    return $this->fail('chunk_serialization_failed', exception: $exception);
                }
                if (!$this->queuePacket($packet)) {
                    if ($this->closed) {
                        $this->releaseChunk($position->x, $position->z);

                        return false;
                    }

                    break;
                }
            }
            if (!$streamingBudget->claimDelivery()) {
                break;
            }
            $this->generatedChunks->dequeue();
            unset($this->queuedChunkKeys[$key]);
            $view->markSent($position->x, $position->z);
            if ($key === $this->worldSwitchCenterKey) {
                $this->worldSwitchCenterQueued = true;
            }
            $this->chunkVisibilityChanges[$position->key()] = true;
            ++$sent;
            $spawnChunkX = (int) floor($this->spawnX / 16.0);
            $spawnChunkZ = (int) floor($this->spawnZ / 16.0);
            $requiredRadius = min($this->spawnRadius, $view->radius());
            if (abs($position->x - $spawnChunkX) <= $requiredRadius
                && abs($position->z - $spawnChunkZ) <= $requiredRadius) {
                $this->sentSpawnChunks[$key] = true;
            }
        }
        $requiredRadius = min($this->spawnRadius, $view->radius());
        $requiredChunks = (2 * $requiredRadius + 1) ** 2;
        if (!$this->spawnStatusQueued && count($this->sentSpawnChunks) >= $requiredChunks) {
            if (!$this->queuePacket(new PlayStatusPacket(PlayStatus::PlayerSpawn))) {
                return !$this->closed;
            }
            $this->spawnStatusQueued = true;
            $this->bootstrapSent = true;
        }

        return true;
    }

    /** Admits detached preparation work without changing the nearest-first delivery queue. */
    private function prepareGeneratedChunks(ChunkStreamingBudget $streamingBudget): bool
    {
        if ($this->preparedChunks === null || $this->flatWorld === null || $this->generatedChunks->isEmpty()) {
            return true;
        }
        $startedAt = hrtime(true);
        $submittedBytes = 0;
        $admitted = 0;
        /** @var SplQueue<ChunkPosition> $pending */
        $pending = clone $this->generatedChunks;
        while (!$pending->isEmpty() && $admitted < $this->chunksSendPerTick) {
            /** @var ChunkPosition $position */
            $position = $pending->dequeue();
            if ($this->chunkView?->contains($position->x, $position->z) !== true) {
                continue;
            }
            if (!$streamingBudget->claimPreparation()) {
                break;
            }
            try {
                $chunk = $this->flatWorld->chunk($position);
                $lookup = $this->preparedChunks->lookupOrRequest($chunk, $this->protocolVersion);
            } catch (Throwable $exception) {
                return $this->fail('chunk_preparation_failed', exception: $exception);
            }
            ++$admitted;
            $submittedBytes += $lookup->submittedBytes;
            if ($submittedBytes >= self::CHUNK_PREPARATION_BYTES_PER_TICK
                || hrtime(true) - $startedAt >= self::CHUNK_PREPARATION_NANOSECONDS_PER_TICK) {
                break;
            }
        }

        return true;
    }

    private function hasOutboundOrderingBarrier(): bool
    {
        return !$this->deferredCompressionBatches->isEmpty()
            || ($this->outboundCompression?->outstandingCount() ?? 0) > 0;
    }

    private function releaseWorldSwitchGateIfReady(): void
    {
        if (!$this->worldSwitchInputGated || $this->pendingWorldSwitch !== null
            || !$this->worldSwitchCenterDrained
            || (!$this->worldSwitchTeleportAcknowledged && $this->worldSwitchGateTicks < 40)) {
            return;
        }
        $this->worldSwitchInputGated = false;
        $this->worldSwitchTeleportAcknowledged = false;
        $this->worldSwitchCenterQueued = false;
        $this->worldSwitchCenterDrained = false;
        $this->worldSwitchGateTicks = 0;
        $this->worldSwitchCenterKey = null;
    }

    private function queuePreparedChunk(PreparedChunk $prepared): bool
    {
        if ($this->closed || $this->totalQueuedPackets() >= $this->limits->maximumOutgoingPayloadsPerSession) {
            return false;
        }
        try {
            $envelope = $this->encryptor->encryptEnvelope($prepared->clearEnvelope);
        } catch (Throwable $error) {
            return $this->fail('encode_failed', exception: $error);
        }
        if (strlen($envelope) > $this->limits->maximumOutgoingBytesPerSession - $this->outgoingBytes) {
            return $this->fail('output_limit');
        }
        $this->outgoing->enqueue(new OutgoingPlayPayload($envelope));
        $this->outgoingBytes += strlen($envelope);
        ++$this->tracePreparedChunks;
        $this->tracePreparedChunkBytes += strlen($envelope);

        return true;
    }

    /** Clears stale delivery ordering so the next tick rebuilds it nearest to the new center. */
    private function resetGeneratedChunkDeliveryQueue(): void
    {
        if ($this->chunkView === null) {
            return;
        }
        $view = $this->chunkView;
        while (!$this->generatedChunks->isEmpty()) {
            $position = $this->generatedChunks->dequeue();
            unset($this->queuedChunkKeys[$position->key()]);
            if (!$view->containsRetained($position->x, $position->z)) {
                $this->releaseChunk($position->x, $position->z);
            }
        }
    }

    private function releaseChunk(int $x, int $z): void
    {
        if ($this->flatWorld === null) {
            return;
        }
        $position = new ChunkPosition($x, $z);
        $key = $position->key();
        if (isset($this->retainedChunks[$key])) {
            $this->flatWorld->releaseChunk($position);
            unset($this->retainedChunks[$key]);
        }
    }

    private static function playerAuthDecodeDetail(Throwable $exception): ?string
    {
        return match ($exception->getMessage()) {
            'Boolean must be encoded as 0 or 1.' => 'invalid_optional_marker',
            'Packet payload contains trailing bytes.' => 'trailing_bytes',
            'PlayerAuthInput conditional payload is truncated.' => 'conditional_truncated',
            'PlayerAuthInput input-data count exceeds its limit.' => 'flag_count',
            'PlayerAuthInput input data contains an unknown or duplicate entry.' => 'invalid_flag',
            'PlayerAuthInput payload is invalid.' => 'invalid_value',
            'Requested bytes exceed the remaining input.',
            'Truncated unsigned VarInt.',
            'Truncated unsigned VarLong.' => 'truncated',
            default => null,
        };
    }

    private static function itemStackPacketDecodeDetail(Throwable $exception): string
    {
        return match ($exception->getMessage()) {
            'Item-stack request count is invalid.' => 'request_count',
            'Item-stack request action count exceeds its limit.' => 'action_count_limit',
            'Item-stack request filter-string count exceeds its limit.' => 'filter_count_limit',
            'Item-stack request is invalid.' => 'invalid_request_value',
            'Packet payload contains trailing bytes.' => 'trailing_bytes',
            'Requested bytes exceed the remaining input.',
            'Truncated unsigned VarInt.',
            'Truncated unsigned VarLong.' => 'truncated',
            default => 'unclassified',
        };
    }
}
