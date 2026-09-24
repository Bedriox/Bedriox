<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Batch\BatchLimits;
use Bedriox\Protocol\Batch\BedrockBatch;
use Bedriox\Protocol\Batch\BedrockBatchCodec;
use Bedriox\Protocol\Batch\CompressionMode;
use Bedriox\Protocol\Encryption\BedrockDecryptor;
use Bedriox\Protocol\Encryption\BedrockEncryptor;
use Bedriox\Protocol\Identity\VerifiedClientData;
use Bedriox\Protocol\Packet\AbilityValueType;
use Bedriox\Protocol\Packet\ActorEventPacket;
use Bedriox\Protocol\Packet\ActorEventType;
use Bedriox\Protocol\Packet\AnimatePacket;
use Bedriox\Protocol\Packet\BasicInventoryTransaction;
use Bedriox\Protocol\Packet\BedrockPacketCodec;
use Bedriox\Protocol\Packet\BlockPosition;
use Bedriox\Protocol\Packet\ChatPacket;
use Bedriox\Protocol\Packet\ChunkRadiusUpdatedPacket;
use Bedriox\Protocol\Packet\ClientCacheStatusPacket;
use Bedriox\Protocol\Packet\CommandRequestPacket;
use Bedriox\Protocol\Packet\ContainerClosePacket;
use Bedriox\Protocol\Packet\ContainerOpenPacket;
use Bedriox\Protocol\Packet\CorrectPlayerMovePredictionPacket;
use Bedriox\Protocol\Packet\CraftCreativeItemStackRequestAction;
use Bedriox\Protocol\Packet\CreateItemStackRequestAction;
use Bedriox\Protocol\Packet\DeathInfoPacket;
use Bedriox\Protocol\Packet\DropItemStackRequestAction;
use Bedriox\Protocol\Packet\EmoteFlag;
use Bedriox\Protocol\Packet\EmoteListPacket;
use Bedriox\Protocol\Packet\EmotePacket;
use Bedriox\Protocol\Packet\FullContainerName;
use Bedriox\Protocol\Packet\HandSlot;
use Bedriox\Protocol\Packet\InteractPacket;
use Bedriox\Protocol\Packet\InventoryAction;
use Bedriox\Protocol\Packet\InventoryContainerId;
use Bedriox\Protocol\Packet\InventoryItemStack;
use Bedriox\Protocol\Packet\InventoryLegacySlot;
use Bedriox\Protocol\Packet\InventorySource;
use Bedriox\Protocol\Packet\InventorySourceFlag;
use Bedriox\Protocol\Packet\InventorySourceType;
use Bedriox\Protocol\Packet\InventoryTransactionPacket;
use Bedriox\Protocol\Packet\InventoryTransactionType;
use Bedriox\Protocol\Packet\InventoryVector3;
use Bedriox\Protocol\Packet\ItemReleaseActionType;
use Bedriox\Protocol\Packet\ItemReleaseInventoryTransaction;
use Bedriox\Protocol\Packet\ItemStackRequest;
use Bedriox\Protocol\Packet\ItemStackRequestPacket;
use Bedriox\Protocol\Packet\ItemStackRequestSlot;
use Bedriox\Protocol\Packet\ItemUseActionType;
use Bedriox\Protocol\Packet\ItemUseClientCooldownState;
use Bedriox\Protocol\Packet\ItemUseInventoryTransaction;
use Bedriox\Protocol\Packet\ItemUseOnEntityActionType;
use Bedriox\Protocol\Packet\ItemUseOnEntityInventoryTransaction;
use Bedriox\Protocol\Packet\ItemUsePredictedResult;
use Bedriox\Protocol\Packet\ItemUseTriggerType;
use Bedriox\Protocol\Packet\LevelChunkPacket;
use Bedriox\Protocol\Packet\MineBlockItemStackRequestAction;
use Bedriox\Protocol\Packet\MobArmorEquipmentPacket;
use Bedriox\Protocol\Packet\MobEquipmentPacket;
use Bedriox\Protocol\Packet\MovementPredictionSyncPacket;
use Bedriox\Protocol\Packet\NetworkChunkPublisherUpdatePacket;
use Bedriox\Protocol\Packet\NetworkStackLatencyPacket;
use Bedriox\Protocol\Packet\Packet;
use Bedriox\Protocol\Packet\PacketFrame;
use Bedriox\Protocol\Packet\PacketHeader;
use Bedriox\Protocol\Packet\PacketIds;
use Bedriox\Protocol\Packet\PlayerActionPacket;
use Bedriox\Protocol\Packet\PlayerActionType;
use Bedriox\Protocol\Packet\PlayerAuthInputFlag;
use Bedriox\Protocol\Packet\PlayerAuthInputPacket;
use Bedriox\Protocol\Packet\PlayerBlockAction;
use Bedriox\Protocol\Packet\PlayerPositionProjection;
use Bedriox\Protocol\Packet\PlayerSkin;
use Bedriox\Protocol\Packet\PlayerSkinPacket;
use Bedriox\Protocol\Packet\RequestAbilityPacket;
use Bedriox\Protocol\Packet\RequestChunkRadiusPacket;
use Bedriox\Protocol\Packet\RespawnPacket;
use Bedriox\Protocol\Packet\RespawnState;
use Bedriox\Protocol\Packet\ServerSettingsRequestPacket;
use Bedriox\Protocol\Packet\SetActorMotionPacket;
use Bedriox\Protocol\Packet\SetLocalPlayerAsInitializedPacket;
use Bedriox\Protocol\Packet\SetPlayerInventoryOptionsPacket;
use Bedriox\Protocol\Packet\SubChunkRequestPacket;
use Bedriox\Protocol\Packet\SystemTextPacket;
use Bedriox\Protocol\Packet\TakeItemStackRequestAction;
use Bedriox\Protocol\Packet\TranslatedTextPacket;
use Bedriox\Protocol\Packet\UpdateAbilitiesPacket;
use Bedriox\Protocol\Packet\VoxelShapesPacket;
use Bedriox\Protocol\Security\OpenSslEphemeralKeyFactory;
use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\RakNet\Connected\ConnectedPayloadEvent;
use Bedriox\RakNet\Protocol\Reliability;
use Bedriox\Server\Login\AuthenticatedLogin;
use Bedriox\Server\Login\LoginChannelReady;
use Bedriox\Server\Player\InventoryContainer;
use Bedriox\Server\Player\InventoryResponseMode;
use Bedriox\Server\Player\InventoryStackRequestActionType;
use Bedriox\Server\Runtime\BedrockChunkPacketSerializer;
use Bedriox\Server\Runtime\BedrockInventoryPacketProjector;
use Bedriox\Server\Runtime\BedrockPlayChannel;
use Bedriox\Server\Runtime\BedrockWorldEventPacketEncoder;
use Bedriox\Server\Runtime\RuntimeDiagnostics;
use Bedriox\Server\Runtime\RuntimeLimits;
use Bedriox\Server\Simulation\BlockBreakAction;
use Bedriox\Server\Simulation\Command\AcknowledgeRespawn;
use Bedriox\Server\Simulation\Command\ApplyInventoryStackRequest;
use Bedriox\Server\Simulation\Command\AttackPlayer;
use Bedriox\Server\Simulation\Command\BreakBlock;
use Bedriox\Server\Simulation\Command\DropItem;
use Bedriox\Server\Simulation\Command\MovePlayer;
use Bedriox\Server\Simulation\Command\PerformEmote;
use Bedriox\Server\Simulation\Command\PlaceBlock;
use Bedriox\Server\Simulation\Command\ReleaseItem;
use Bedriox\Server\Simulation\Command\RespawnPlayer;
use Bedriox\Server\Simulation\Command\SelectHotbarSlot;
use Bedriox\Server\Simulation\Command\SendChat;
use Bedriox\Server\Simulation\Command\SyncInventory;
use Bedriox\Server\Simulation\Command\SyncInventorySlots;
use Bedriox\Server\Simulation\Command\UseItem;
use Bedriox\Server\Simulation\Event\BlockPlaced;
use Bedriox\Server\Simulation\Event\HeldItemChanged;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\Worker\Chunk\PreparedChunkCache;
use Bedriox\Server\Worker\Network\CompressionWorkerDispatcher;
use Bedriox\Server\Worker\Task\PrepareChunkTask;
use Bedriox\Server\Worker\WorkerDispatcher;
use Bedriox\Server\Worker\WorkerPoolSnapshot;
use Bedriox\Server\Worker\WorkerReceipt;
use Bedriox\Server\Worker\WorkerResult;
use Bedriox\Server\Worker\WorkerResultStatus;
use Bedriox\Server\Worker\WorkerSubmission;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\SubChunk;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldGenerator;
use Bedriox\Server\World\WorldMetadata;
use Closure;
use PHPUnit\Framework\TestCase;

final class BedrockPlayChannelTest extends TestCase
{
    private const string BLOCK_ACTION_INPUT = '00000000000000000000803ff43d8342000000c000000000000000000000000001460100000000000000000000070000000000000000000000000000010100027e030200000000000000000000000000000000803f000000000000000000000000';
    private const string ITEM_USE_INPUT = '000000000000000000000000f43d8342000000000000000000000000000000000144010000000000000000000000000000000000000000000000010000000201027e0301000000000000000000000000000000008042000000000000003f0000003f0000003f0001000000000000000000000000000000000000000000000000000000000000000000';
    private const int INVENTORY_TRANSACTION_PACKET_ID = 30;
    private const string INVENTORY_TRANSACTION_ITEM_USE = '000002000001027e0301000000000000000000000000803f00000040000040400000003f0000803e000000bf110100';
    private const string RETAIL_EMPTY_MOB_EQUIPMENT = '070000000000000000000000';
    /** Not produced by ChatPacket: untranslated AuthorAndMessage/CHAT, spoofed attribution, no filtered text. */
    private const string LITERAL_CHAT = '0001010753706f6f666564076c69746572616c0178017000';

    public function testPlayerCommandIntentIsBoundedAndBoundToAuthenticatedIdentity(): void
    {
        [$channel, $client, , $entityId] = $this->channel();
        $channel->drainOutgoing();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $literal = hex2bin(
            '082f76657273696f6e' .
            '06706c61796572' .
            '7766554433221100ffeeddccbbaa9988' .
            '067265712d3432' .
            '0807060504030201' .
            '00' .
            '066c6174657374',
        );
        self::assertIsString($literal);
        $payload = BedrockBatchCodec::encode(new BedrockBatch([
            new PacketFrame(new PacketHeader(PacketIds::COMMAND_REQUEST), $literal),
        ], CompressionMode::NegotiatedZlib, 256), new BatchLimits());
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($payload),
            Reliability::ReliableOrdered,
            0,
        )));
        $commands = $channel->drainPlayerCommands();
        self::assertCount(1, $commands);
        self::assertSame('/version', $commands[0]->command);
        self::assertSame($channel->login()->identity, $commands[0]->origin->uuid);
        self::assertSame('req-42', $commands[0]->origin->requestId);
        self::assertSame(0x0102030405060708, $commands[0]->origin->playerId);
    }

    public function testCipherTransferInitializationAndSpoofProofChatInput(): void
    {
        [$channel, $clientEncryptor, $clientDecryptor, $entityId] = $this->channel([
            new VoxelShapesPacket(),
            new ChunkRadiusUpdatedPacket(4),
        ]);
        $outgoing = $channel->drainOutgoing();
        self::assertCount(1, $outgoing);
        self::assertSame(337, $this->packetId($clientDecryptor->decryptEnvelope($outgoing[0]->payload)));

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([new RequestChunkRadiusPacket(4, 255)])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertCount(1, $channel->drainOutgoing());

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([new SubChunkRequestPacket(0, 0, 3, 0, [
                ['x' => 0, 'y' => 0, 'z' => 0],
            ])])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertTrue($channel->tick());
        self::assertCount(1, $channel->drainOutgoing());

        $payload = $this->encode([
            new SetLocalPlayerAsInitializedPacket($entityId),
            new PlayerAuthInputPacket(
                10.0,
                90.0,
                1.0,
                PlayerPositionProjection::feetToWireY(64.0),
                2.0,
                1.0,
                0.0,
                90.0,
                [
                    PlayerAuthInputFlag::VerticalCollision->value,
                    PlayerAuthInputFlag::StartJumping->value,
                    PlayerAuthInputFlag::Sprinting->value,
                ],
                1,
                0,
                0,
                0.0,
                0.0,
                UnsignedLong::fromInt(5),
                1.0,
                0.0,
                0.0,
                1.0,
                0.0,
                0.0,
                0.0,
                0.0,
                1.0,
                0.0,
            ),
            new ChatPacket('SpoofedName', 'hello', 'spoofed-xuid'),
        ]);
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($payload),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertTrue($channel->takeSpawnAcknowledged());
        self::assertFalse($channel->takeSpawnAcknowledged());
        self::assertTrue($channel->tick());
        self::assertFalse($channel->takeSpawnAcknowledged());
        $commands = $channel->drainCommands();
        self::assertCount(2, $commands);
        self::assertInstanceOf(MovePlayer::class, $commands[0]);
        self::assertSame(1, $commands[0]->sequence);
        self::assertSame(0, $commands[0]->clientTick->high);
        self::assertSame(5, $commands[0]->clientTick->low);
        self::assertEqualsWithDelta(64.0, $commands[0]->position->y, 0.000_01);
        self::assertSame(MovementMode::SPRINTING, $commands[0]->mode);
        self::assertSame(90.0, $commands[0]->headYaw);
        self::assertFalse($commands[0]->sneaking);
        self::assertTrue($commands[0]->sprinting);
        self::assertSame(1.0, $commands[0]->deltaX);
        self::assertSame(0.0, $commands[0]->deltaY);
        self::assertTrue($commands[0]->jumpRequested);
        self::assertInstanceOf(SendChat::class, $commands[1]);
        self::assertSame('hello', $commands[1]->message);
        self::assertSame('session', $commands[1]->session);
    }

    public function testLiteralProtocol2193ChatUsesTheAuthenticatedSessionIdentity(): void
    {
        [$channel, $client, , $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $payload = hex2bin(self::LITERAL_CHAT);
        self::assertIsString($payload);

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encodeFrames([
                new PacketFrame(new PacketHeader(9), $payload),
            ])),
            Reliability::ReliableOrdered,
            0,
        )));

        $commands = $channel->drainCommands();
        self::assertCount(1, $commands);
        self::assertInstanceOf(SendChat::class, $commands[0]);
        self::assertSame('session', $commands[0]->session);
        self::assertSame('literal', $commands[0]->message);
        self::assertFalse($channel->isClosed());
    }

    public function testWrongReliabilityAndWrongEntityFailClosedAndClearWork(): void
    {
        [$wrongReliability, $client] = $this->channel();
        self::assertFalse($wrongReliability->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new ChatPacket('ignored', 'hello')])),
            Reliability::Reliable,
            null,
        )));
        self::assertTrue($wrongReliability->isClosed());
        self::assertSame([], $wrongReliability->drainCommands());

        [$wrongEntity, $client] = $this->channel();
        self::assertFalse($wrongEntity->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket(UnsignedLong::fromInt(99))])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertTrue($wrongEntity->isClosed());
    }

    public function testUnsignedClientTicksAreOrderedBeforeCreatingLocalSequences(): void
    {
        [$channel, $client, , $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([
                new SetLocalPlayerAsInitializedPacket($entityId),
                $this->movementPacket(new UnsignedLong(0x80000000, 1)),
                $this->movementPacket(new UnsignedLong(0x7fffffff, 0xffffffff)),
            ])),
            Reliability::ReliableOrdered,
            0,
        )));

        $commands = $channel->drainCommands();
        self::assertCount(1, $commands);
        self::assertInstanceOf(MovePlayer::class, $commands[0]);
        self::assertSame(1, $commands[0]->sequence);
        self::assertSame(0x80000000, $commands[0]->clientTick->high);
        self::assertSame(1, $commands[0]->clientTick->low);
    }

    public function testEncryptedMovementCorrectionReturnsTheExactRetailInputTick(): void
    {
        [$channel, $client, $server, $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([
                new SetLocalPlayerAsInitializedPacket($entityId),
                $this->movementPacket(new UnsignedLong(0x80000000, 17), x: 1000.0, z: 1000.0),
            ])),
            Reliability::ReliableOrdered,
            0,
        )));

        $commands = $channel->drainCommands();
        self::assertCount(1, $commands);
        self::assertInstanceOf(MovePlayer::class, $commands[0]);
        $simulation = new WorldSimulation();
        $factory = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($factory->join('session', 'identity', 'Player')));
        $simulation->tick();
        self::assertTrue($simulation->enqueue($commands[0]));
        $events = $simulation->tick()->events;
        self::assertCount(1, $events);

        $directed = (new BedrockWorldEventPacketEncoder())->encode($events[0], []);
        self::assertCount(1, $directed);
        self::assertTrue($channel->queuePacket($directed[0]->packet));
        $outgoing = $channel->drainOutgoing();
        self::assertCount(1, $outgoing);
        $packet = $this->decode($server->decryptEnvelope($outgoing[0]->payload));
        self::assertInstanceOf(CorrectPlayerMovePredictionPacket::class, $packet);
        self::assertSame(0x80000000, $packet->tick->high);
        self::assertSame(17, $packet->tick->low);
        self::assertTrue($packet->onGround);
        self::assertFalse($channel->isClosed());
    }

    public function testGameplayBeforeInitializationFailsClosed(): void
    {
        [$channel, $client] = $this->channel();
        self::assertFalse($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new ChatPacket('ignored', 'too early')])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertTrue($channel->isClosed());
        self::assertSame([], $channel->drainCommands());
    }

    public function testEncryptedMovementPredictionSyncIsConsumedWithoutTrustingReportedState(): void
    {
        $lines = [];
        $diagnostics = new RuntimeDiagnostics(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        [$channel, $client, , $entityId] = $this->channel([], $diagnostics);
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $channel->drainOutgoing();
        $channel->drainCommands();

        $sync = $this->movementPredictionSync($entityId, true);
        self::assertSame(58, strlen(BedrockPacketCodec::encode($sync)));
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([$sync])),
            Reliability::ReliableOrdered,
            0,
        )));

        self::assertFalse($channel->isClosed());
        self::assertSame([], $channel->drainOutgoing());
        self::assertSame([], $channel->drainCommands());

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([$sync])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertFalse($channel->isClosed());
        self::assertSame([], $channel->drainOutgoing());
        self::assertSame([], $channel->drainCommands());

        $diagnostic = implode('', $lines);
        self::assertStringContainsString('"event":"play.movement_prediction_sync.protocol_trace"', $diagnostic);
        self::assertStringContainsString('"advisory_consumed":true', $diagnostic);
        self::assertStringContainsString('"reported_flying":true', $diagnostic);
        self::assertStringContainsString('"actor_flag_count":8', $diagnostic);

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([$this->blockActionPacket(new PlayerBlockAction(
                PlayerActionType::StartDestroyBlock,
                new BlockPosition(0, 63, 0),
                1,
            ), 91)])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertFalse($channel->isClosed());
        self::assertNotEmpty($channel->drainCommands());
    }

    public function testRetailEatingActorEventIsAcceptedAsPresentationOnly(): void
    {
        $lines = [];
        $diagnostics = new RuntimeDiagnostics(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        [$channel, $client, , $entityId] = $this->channel([], $diagnostics);
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $channel->drainOutgoing();
        $channel->drainCommands();

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([
                new ActorEventPacket($entityId, ActorEventType::EatingItem, 1),
            ])),
            Reliability::ReliableOrdered,
            0,
        )));

        self::assertFalse($channel->isClosed());
        self::assertSame([], $channel->drainOutgoing());
        self::assertSame([], $channel->drainCommands());
        self::assertStringContainsString(
            '"event":"play.actor_event_advisory.protocol_trace"',
            implode('', $lines),
        );
    }

    public function testEncryptedMovementPredictionSyncDoesNotTreatReportedActorAsAuthority(): void
    {
        [$channel, $client, , $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([
                $this->movementPredictionSync(UnsignedLong::fromInt(99), false),
            ])),
            Reliability::ReliableOrdered,
            0,
        )));

        self::assertFalse($channel->isClosed());
        self::assertSame([], $channel->drainOutgoing());
        self::assertSame([], $channel->drainCommands());
    }

    public function testEncryptedMovementPredictionSyncBeforeInitializationIsHarmless(): void
    {
        [$channel, $client, , $entityId] = $this->channel();

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([$this->movementPredictionSync($entityId, false)])),
            Reliability::ReliableOrdered,
            0,
        )));

        self::assertFalse($channel->isClosed());
        self::assertSame([], $channel->drainOutgoing());
        self::assertSame([], $channel->drainCommands());
    }

    public function testDuplicateInitializationAcknowledgementIsIdempotent(): void
    {
        [$channel, $client, , $entityId] = $this->channel();
        foreach ([1, 2] as $attempt) {
            self::assertTrue($channel->accept(new ConnectedPayloadEvent(
                $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
                Reliability::ReliableOrdered,
                0,
            )), "Initialization acknowledgement {$attempt} should be accepted.");
        }

        self::assertFalse($channel->isClosed());
        self::assertTrue($channel->takeSpawnAcknowledged());
        self::assertFalse($channel->takeSpawnAcknowledged());
    }

    public function testInitializationUsesStartGameOrientationWithoutSendingMovementReset(): void
    {
        [$channel, $client, , $entityId] = $this->channel();

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([
                new SetLocalPlayerAsInitializedPacket($entityId),
                $this->movementPacket(UnsignedLong::fromInt(1)),
            ])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertSame([], $channel->drainOutgoing(), 'Initialization must not override StartGame orientation.');
        self::assertCount(1, $channel->drainCommands(), 'Movement after initialization enters normal gameplay processing.');
    }

    public function testClientRotationUsesPmmpNormalizationBeforeEnteringSimulation(): void
    {
        [$channel, $client, , $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([
                $this->movementPacket(UnsignedLong::fromInt(1), -84.75, -45.25),
            ])),
            Reliability::ReliableOrdered,
            0,
        )));

        $commands = $channel->drainCommands();
        self::assertCount(1, $commands);
        self::assertInstanceOf(MovePlayer::class, $commands[0]);
        self::assertSame(275.25, $commands[0]->yaw);
        self::assertSame(-45.25, $commands[0]->pitch);
        self::assertSame(275.25, $commands[0]->headYaw);
    }

    public function testRetailEquipmentNotificationBeforeInitializationIsDeferredUntilAdmission(): void
    {
        [$channel, $client, , $entityId] = $this->channel([
            new MobEquipmentPacket(UnsignedLong::fromInt(7)),
        ]);
        $payload = hex2bin(self::RETAIL_EMPTY_MOB_EQUIPMENT);
        self::assertIsString($payload);

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encodeFrames([
                new PacketFrame(new PacketHeader(PacketIds::MOB_EQUIPMENT), $payload),
            ])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertFalse($channel->isClosed());
        self::assertSame([], $channel->drainCommands());

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $commands = $channel->drainCommands();
        self::assertCount(1, $commands);
        $command = array_shift($commands);
        self::assertInstanceOf(SelectHotbarSlot::class, $command);
        self::assertSame(0, $command->hotbarSlot);
    }

    public function testLatestBoundedEquipmentNotificationWinsBeforeInitialization(): void
    {
        [$channel, $client, , $entityId] = $this->channel();
        foreach ([8, 4, 4] as $slot) {
            self::assertTrue($channel->accept(new ConnectedPayloadEvent(
                $client->encryptEnvelope($this->encode([new MobEquipmentPacket($entityId, $slot, $slot, 0)])),
                Reliability::ReliableOrdered,
                0,
            )));
            self::assertFalse($channel->isClosed());
            self::assertSame([], $channel->drainCommands());
        }

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $commands = $channel->drainCommands();
        self::assertCount(1, $commands);
        $command = array_shift($commands);
        self::assertInstanceOf(SelectHotbarSlot::class, $command);
        self::assertSame(4, $command->hotbarSlot);
    }

    public function testSpoofedEquipmentNotificationBeforeInitializationFailsClosed(): void
    {
        [$channel, $client] = $this->channel();
        self::assertFalse($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new MobEquipmentPacket(UnsignedLong::fromInt(8))])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertTrue($channel->isClosed());
        self::assertSame([], $channel->drainCommands());
    }

    public function testAuthoritativeEquipmentEchoesRemainBoundedSafeNoOps(): void
    {
        [$channel, $client, , $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertSame([], $channel->drainCommands());

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([
                new MobEquipmentPacket($entityId, 0xff, 0xfe, InventoryContainerId::OFFHAND),
                new MobArmorEquipmentPacket($entityId),
            ])),
            Reliability::ReliableOrdered,
            0,
        )));

        self::assertFalse($channel->isClosed());
        self::assertSame([], $channel->drainCommands());
    }

    public function testObservedPreInitializationNotificationsRemainSafeNoOps(): void
    {
        [$channel, $client, , $entityId] = $this->channel();
        $emotes = new EmoteListPacket($entityId, ['00112233-4455-6677-8899-aabbccddeeff']);
        self::assertSame(18, strlen($emotes->encode()));
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([
                new InteractPacket(2, UnsignedLong::fromInt(0)),
                new InteractPacket(InteractPacket::OPEN_INVENTORY, UnsignedLong::fromInt(0)),
                new ClientCacheStatusPacket(false),
                new AnimatePacket(AnimatePacket::SWING, $entityId, 0.0, 'interact'),
                $emotes,
            ])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertFalse($channel->isClosed());
        self::assertSame([], $channel->drainCommands());
    }

    public function testWrongEntityEmoteListFailsClosed(): void
    {
        [$channel, $client] = $this->channel();
        self::assertFalse($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([
                new EmoteListPacket(UnsignedLong::fromInt(8), ['00112233-4455-6677-8899-aabbccddeeff']),
            ])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertTrue($channel->isClosed());
    }

    public function testEmoteRequiresInitializationAndOwnRuntimeActor(): void
    {
        $emoteId = '00112233-4455-6677-8899-aabbccddeeff';
        [$premature, $client, , $entityId] = $this->channel();
        self::assertFalse($premature->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new EmotePacket($entityId, $emoteId, 0, '', '')])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertTrue($premature->isClosed());

        [$wrongActor, $client, , $entityId] = $this->channel();
        self::assertTrue($wrongActor->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertFalse($wrongActor->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new EmotePacket(
                UnsignedLong::fromInt(8),
                $emoteId,
                0,
                '',
                '',
            )])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertTrue($wrongActor->isClosed());
    }

    public function testEmoteInputQueuesOnlyValidatedIdentityFreeIntent(): void
    {
        [$channel, $client, , $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $emoteId = '00112233-4455-6677-8899-aabbccddeeff';
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new EmotePacket(
                $entityId,
                $emoteId,
                12345,
                'spoofed-xuid',
                'spoofed-platform',
                [EmoteFlag::ServerSide],
            )])),
            Reliability::ReliableOrdered,
            0,
        )));

        $commands = $channel->drainCommands();
        self::assertCount(1, $commands);
        self::assertInstanceOf(PerformEmote::class, $commands[0]);
        self::assertSame('session', $commands[0]->session);
        self::assertSame($emoteId, $commands[0]->emoteId);
        self::assertFalse($channel->isClosed());
    }

    public function testAuthenticatedPlayerSkinUpdateDoesNotCloseSpawnedSession(): void
    {
        [$channel, $client, , $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $appearance = new VerifiedClientData(
            64,
            32,
            str_repeat("\x01", 64 * 32 * 4),
            0,
            0,
            '',
            '{}',
            [],
            skinId: 'skin',
            skinResourcePatchJson: '{"geometry":{"default":"geometry.humanoid.custom"}}',
            profileHash: 'profile',
        );

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new PlayerSkinPacket(
                '00000000-0000-0000-0000-000000000001',
                PlayerSkin::fromVerifiedClientData($appearance),
                'new',
                'old',
            )])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertFalse($channel->isClosed());
        self::assertSame([], $channel->drainCommands());
    }

    public function testPlayerSkinUpdateCannotSpoofAnotherIdentity(): void
    {
        [$channel, $client, , $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $appearance = new VerifiedClientData(
            64,
            32,
            str_repeat("\x01", 64 * 32 * 4),
            0,
            0,
            '',
            '{}',
            [],
            skinId: 'skin',
            skinResourcePatchJson: '{"geometry":{"default":"geometry.humanoid.custom"}}',
        );

        self::assertFalse($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new PlayerSkinPacket(
                '00000000-0000-0000-0000-000000000002',
                PlayerSkin::fromVerifiedClientData($appearance),
                'new',
                'old',
            )])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertTrue($channel->isClosed());
    }

    public function testRecognizedRoutineNotificationsArePostSpawnSafeNoOps(): void
    {
        [$channel, $client, , $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([
                new SetLocalPlayerAsInitializedPacket($entityId),
                new InteractPacket(InteractPacket::OPEN_INVENTORY, UnsignedLong::fromInt(0)),
                new AnimatePacket(1, $entityId, 0.0, 'interact'),
                new ClientCacheStatusPacket(false),
                new MobEquipmentPacket($entityId, 8, 8, 0),
                new PlayerActionPacket(
                    $entityId,
                    PlayerActionType::InternalUpdate,
                    new BlockPosition(0, 64, 0),
                    new BlockPosition(0, 64, 0),
                    0,
                ),
                new ContainerClosePacket(0, 0, false),
                new RequestAbilityPacket(0, AbilityValueType::Bool, true, 0.0),
            ])),
            Reliability::ReliableOrdered,
            0,
        )));

        self::assertFalse($channel->isClosed());
        $commands = $channel->drainCommands();
        self::assertCount(2, $commands);
        self::assertInstanceOf(SyncInventory::class, $commands[0]);
        self::assertInstanceOf(SelectHotbarSlot::class, $commands[1]);
        self::assertSame(8, $commands[1]->hotbarSlot);
    }

    public function testLiteralInventoryRequestOpensOneBoundedMainWindowUntilMatchingClose(): void
    {
        [$channel, $client, $server, $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $openPayload = hex2bin('060100');
        self::assertIsString($openPayload);
        $openFrame = new PacketFrame(new PacketHeader(PacketIds::INTERACT), $openPayload);

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encodeFrames([$openFrame, $openFrame])),
            Reliability::ReliableOrdered,
            0,
        )));
        $opened = $channel->drainOutgoing();
        self::assertCount(1, $opened);
        $frame = $this->decodeFrame($server->decryptEnvelope($opened[0]->payload));
        self::assertSame(PacketIds::CONTAINER_OPEN, $frame->header->packetId);
        $open = BedrockPacketCodec::decode($frame->header->packetId, $frame->payload);
        self::assertInstanceOf(ContainerOpenPacket::class, $open);
        self::assertSame(1, $open->containerId);
        self::assertSame($entityId->toSignedBits(), $open->actorUniqueId);
        $commands = $channel->drainCommands();
        self::assertCount(1, $commands);
        self::assertInstanceOf(SyncInventory::class, $commands[0]);

        $retailClose = hex2bin('ff0000');
        self::assertIsString($retailClose);
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encodeFrames([
                new PacketFrame(new PacketHeader(PacketIds::CONTAINER_CLOSE), $retailClose),
            ])),
            Reliability::ReliableOrdered,
            0,
        )));
        $closed = $channel->drainOutgoing();
        self::assertCount(1, $closed);
        $closedPayload = array_shift($closed);
        self::assertNotNull($closedPayload);
        $closeFrame = $this->decodeFrame($server->decryptEnvelope($closedPayload->payload));
        self::assertSame(PacketIds::CONTAINER_CLOSE, $closeFrame->header->packetId);
        $close = BedrockPacketCodec::decode($closeFrame->header->packetId, $closeFrame->payload);
        self::assertInstanceOf(ContainerClosePacket::class, $close);
        self::assertSame(1, $close->containerId);
        self::assertSame(0xff, $close->containerType);
        self::assertFalse($close->serverInitiated);

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encodeFrames([$openFrame])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertCount(1, $channel->drainOutgoing());
        self::assertFalse($channel->isClosed());
    }

    public function testFlightToggleIsRejectedWithoutDroppingTheSameFrameJump(): void
    {
        [$channel, $client, $server, $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([
                new SetLocalPlayerAsInitializedPacket($entityId),
                $this->flightInput(UnsignedLong::fromInt(41)),
            ])),
            Reliability::ReliableOrdered,
            0,
        )));

        $outgoing = $channel->drainOutgoing();
        self::assertCount(1, $outgoing);
        $abilities = $this->decodeFrame($server->decryptEnvelope($outgoing[0]->payload));
        self::assertSame(PacketIds::UPDATE_ABILITIES, $abilities->header->packetId);
        self::assertSame(UpdateAbilitiesPacket::survival(7)->encode(), $abilities->payload);
        $initialCommands = $channel->drainCommands();
        self::assertCount(1, $initialCommands);
        self::assertInstanceOf(MovePlayer::class, $initialCommands[0]);
        self::assertTrue($initialCommands[0]->jumpRequested);
        self::assertEqualsWithDelta(64.42, $initialCommands[0]->position->y, 0.000_01);
        self::assertEqualsWithDelta(0.34, $initialCommands[0]->deltaY, 0.000_01);
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([
                $this->flightInput(UnsignedLong::fromInt(41)),
                $this->movementPacket(UnsignedLong::fromInt(42)),
            ])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertSame([], $channel->drainOutgoing(), 'A repeated flight state must not amplify output.');
        self::assertCount(1, $channel->drainCommands(), 'Ordinary movement must continue after rejecting flight.');
        self::assertFalse($channel->isClosed());
    }

    public function testHeldJumpStateRemainsAnAuthoritativeJumpRequest(): void
    {
        [$channel, $client, , $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([
                new SetLocalPlayerAsInitializedPacket($entityId),
                $this->heldJumpInput(UnsignedLong::fromInt(51)),
            ])),
            Reliability::ReliableOrdered,
            0,
        )));

        $commands = $channel->drainCommands();
        self::assertCount(1, $commands);
        self::assertInstanceOf(MovePlayer::class, $commands[0]);
        self::assertTrue($commands[0]->jumpRequested);
        self::assertSame(MovementMode::JUMPING, $commands[0]->mode);
        self::assertEqualsWithDelta(64.42, $commands[0]->position->y, 0.000_01);
    }

    public function testAbilityAndSettingsRequestsCannotEnableFlightOrDisconnectTheSession(): void
    {
        [$channel, $client, $server, $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([
                new SetLocalPlayerAsInitializedPacket($entityId),
                new RequestAbilityPacket(19, AbilityValueType::Float, false, 2.0),
                new ServerSettingsRequestPacket(),
            ])),
            Reliability::ReliableOrdered,
            0,
        )));

        $outgoing = $channel->drainOutgoing();
        self::assertCount(1, $outgoing);
        $abilities = $this->decodeFrame($server->decryptEnvelope($outgoing[0]->payload));
        self::assertSame(PacketIds::UPDATE_ABILITIES, $abilities->header->packetId);
        self::assertSame(UpdateAbilitiesPacket::survival(7)->encode(), $abilities->payload);
        self::assertSame([], $channel->drainCommands());
        self::assertFalse($channel->isClosed());
    }

    public function testLiteralInventoryTransactionIsAnInitializedNoOp(): void
    {
        [$channel, $client, , $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encodeFrames([$this->inventoryTransactionFrame()])),
            Reliability::ReliableOrdered,
            0,
        )));

        self::assertFalse($channel->isClosed());
        self::assertSame([], $channel->drainOutgoing());
        self::assertSame([], $channel->drainCommands());
    }

    public function testEncryptedEntityAttackBecomesBoundedGameplayIntent(): void
    {
        [$channel, $client, , $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $packet = new InventoryTransactionPacket(0, [], [], new ItemUseOnEntityInventoryTransaction(
            UnsignedLong::fromInt(22),
            ItemUseOnEntityActionType::Attack,
            0,
            InventoryItemStack::empty(),
            new InventoryVector3(9_999.0, 9_999.0, 9_999.0),
            new InventoryVector3(9_999.0, 9_999.0, 9_999.0),
        ));

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([$packet])),
            Reliability::ReliableOrdered,
            0,
        )));

        $commands = $channel->drainCommands();
        self::assertCount(1, $commands);
        self::assertInstanceOf(AttackPlayer::class, $commands[0]);
        self::assertSame(22, $commands[0]->targetRuntimeActorId);
        self::assertSame(0, $commands[0]->hotbarSlot);
        self::assertFalse($channel->isClosed());
    }

    public function testEncryptedCombatProjectionCarriesMotionTickAndLocalizedDeathText(): void
    {
        [$channel, $client, $server, $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $channel->drainOutgoing();

        $motion = new SetActorMotionPacket(
            UnsignedLong::fromInt(22),
            0.25,
            0.4,
            -0.25,
            new UnsignedLong(0x80000000, 25),
        );
        $death = new TranslatedTextPacket('death.attack.player', ['Target', 'Attacker']);
        self::assertTrue($channel->queuePacket($motion));
        self::assertTrue($channel->queuePacket($death));

        $outgoing = $channel->drainOutgoing();
        self::assertCount(2, $outgoing);
        $decoded = array_map(
            fn($payload): Packet => $this->decode($server->decryptEnvelope($payload->payload)),
            $outgoing,
        );
        self::assertInstanceOf(SetActorMotionPacket::class, $decoded[0]);
        self::assertEquals($motion->runtimeEntityId, $decoded[0]->runtimeEntityId);
        self::assertEqualsWithDelta($motion->motionX, $decoded[0]->motionX, 0.000_001);
        self::assertEqualsWithDelta($motion->motionY, $decoded[0]->motionY, 0.000_001);
        self::assertEqualsWithDelta($motion->motionZ, $decoded[0]->motionZ, 0.000_001);
        self::assertEquals($motion->tick, $decoded[0]->tick);
        self::assertEquals($death, $decoded[1]);
        self::assertFalse($channel->isClosed());
    }

    public function testEncryptedDroppedItemMovementCarriesAbsolutePositionAndBoundedMotion(): void
    {
        [$channel, $client, $server, $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $channel->drainOutgoing();

        $entity = new \Bedriox\Server\Entity\Item\DroppedItemEntity(
            100,
            100,
            new \Bedriox\Server\Player\InventoryStack('minecraft:diamond', 1, 1),
            new \Bedriox\Server\Simulation\Position(2.0, 64.0, 3.0),
            new \Bedriox\Server\Entity\Item\ItemEntityMotion(0.0, 0.0, 0.0),
        );
        $projected = (new BedrockWorldEventPacketEncoder())->encode(
            new \Bedriox\Server\Simulation\Event\ItemEntityMoved($entity, 25_000, ['one']),
            [],
        );
        self::assertCount(2, $projected);
        foreach ($projected as $directed) {
            self::assertTrue($channel->queuePacket($directed->packet));
        }

        $outgoing = $channel->drainOutgoing();
        self::assertCount(2, $outgoing);
        $decoded = array_map(
            fn($payload): Packet => $this->decode($server->decryptEnvelope($payload->payload)),
            $outgoing,
        );
        self::assertInstanceOf(\Bedriox\Protocol\Packet\MoveActorAbsolutePacket::class, $decoded[0]);
        self::assertSame(64.125, $decoded[0]->y);
        self::assertTrue($decoded[0]->onGround());
        self::assertInstanceOf(SetActorMotionPacket::class, $decoded[1]);
        self::assertSame(0.0, $decoded[1]->motionY);
        self::assertSame(0, $decoded[1]->tick->low);
        self::assertFalse($channel->isClosed());
    }

    public function testRetailEmptyMobEquipmentDescriptorKeepsInitializedSessionOpen(): void
    {
        [$channel, $client, , $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $payload = hex2bin(self::RETAIL_EMPTY_MOB_EQUIPMENT);
        self::assertIsString($payload);

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encodeFrames([
                new PacketFrame(new PacketHeader(PacketIds::MOB_EQUIPMENT), $payload),
            ])),
            Reliability::ReliableOrdered,
            0,
        )));

        self::assertFalse($channel->isClosed());
        self::assertSame([], $channel->drainOutgoing());
        $commands = $channel->drainCommands();
        self::assertCount(1, $commands);
        self::assertInstanceOf(SelectHotbarSlot::class, $commands[0]);
        self::assertSame(0, $commands[0]->hotbarSlot);
    }

    public function testLiteralInventoryTransactionBeforeInitializationFailsClosed(): void
    {
        $lines = [];
        $diagnostics = new RuntimeDiagnostics(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        [$channel, $client] = $this->channel([], $diagnostics);

        self::assertFalse($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encodeFrames([$this->inventoryTransactionFrame()])),
            Reliability::ReliableOrdered,
            0,
        )));

        self::assertTrue($channel->isClosed());
        self::assertSame([], $channel->drainOutgoing());
        self::assertSame([], $channel->drainCommands());
        $diagnostic = implode('', $lines);
        self::assertStringContainsString('"reason":"invalid_play_state"', $diagnostic);
        self::assertStringContainsString('"packet_id":30', $diagnostic);
        self::assertStringNotContainsString('"reason":"decode_failed"', $diagnostic);
    }

    public function testMalformedLiteralInventoryTransactionFailsDecodeClosed(): void
    {
        $lines = [];
        $diagnostics = new RuntimeDiagnostics(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        [$channel, $client, , $entityId] = $this->channel([], $diagnostics);
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));

        $validPayload = hex2bin(self::INVENTORY_TRANSACTION_ITEM_USE);
        self::assertIsString($validPayload);
        self::assertFalse($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encodeFrames([
                $this->inventoryTransactionFrame($validPayload . "\0"),
            ])),
            Reliability::ReliableOrdered,
            0,
        )));

        self::assertTrue($channel->isClosed());
        self::assertSame([], $channel->drainOutgoing());
        self::assertSame([], $channel->drainCommands());
        $diagnostic = implode('', $lines);
        self::assertStringContainsString('"reason":"decode_failed"', $diagnostic);
        self::assertStringContainsString('"packet_id":30', $diagnostic);
    }

    public function testLiteralInventoryTransactionDoesNotBlockMovementOrChat(): void
    {
        [$channel, $client, , $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $movement = $this->movementPacket(UnsignedLong::fromInt(1));
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encodeFrames([
                $this->inventoryTransactionFrame(),
                new PacketFrame(new PacketHeader(BedrockPacketCodec::packetId($movement)), BedrockPacketCodec::encode($movement)),
                new PacketFrame(new PacketHeader(PacketIds::TEXT), (new ChatPacket('ignored', 'still active'))->encode()),
            ])),
            Reliability::ReliableOrdered,
            0,
        )));

        $commands = $channel->drainCommands();
        self::assertCount(2, $commands);
        self::assertInstanceOf(MovePlayer::class, $commands[0]);
        self::assertInstanceOf(SendChat::class, $commands[1]);
        self::assertFalse($channel->isClosed());
    }

    public function testBoundedLiteralInventoryTransactionRepetitionCannotGrowOutput(): void
    {
        [$channel, $client, , $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));

        $frames = array_fill(0, 64, $this->inventoryTransactionFrame());
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encodeFrames($frames)),
            Reliability::ReliableOrdered,
            0,
        )));

        self::assertFalse($channel->isClosed());
        self::assertSame([], $channel->drainOutgoing());
        self::assertSame([], $channel->drainCommands());
    }

    public function testUnsolicitedLatencyNotificationsArePostSpawnSafeNoOps(): void
    {
        [$channel, $client, , $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([
                new SetLocalPlayerAsInitializedPacket($entityId),
                new NetworkStackLatencyPacket(UnsignedLong::fromInt(1234), false),
                new NetworkStackLatencyPacket(UnsignedLong::fromInt(1235), true),
            ])),
            Reliability::ReliableOrdered,
            0,
        )));

        self::assertSame([], $channel->drainOutgoing());
        self::assertSame([], $channel->drainCommands());
    }

    public function testEntityScopedRoutineNotificationsCannotSpoofAnotherEntity(): void
    {
        $other = UnsignedLong::fromInt(999);
        foreach ([
            new MobEquipmentPacket($other, 0, 0, 0),
            new AnimatePacket(AnimatePacket::SWING, $other, 0.0, 'interact'),
            new PlayerActionPacket(
                $other,
                PlayerActionType::InternalUpdate,
                new BlockPosition(0, 64, 0),
                new BlockPosition(0, 64, 0),
                0,
            ),
        ] as $packet) {
            [$channel, $client, , $entityId] = $this->channel();
            self::assertTrue($channel->accept(new ConnectedPayloadEvent(
                $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
                Reliability::ReliableOrdered,
                0,
            )));
            self::assertFalse($channel->accept(new ConnectedPayloadEvent(
                $client->encryptEnvelope($this->encode([$packet])),
                Reliability::ReliableOrdered,
                0,
            )));
            self::assertTrue($channel->isClosed());
        }
    }

    public function testDecodeFailureDiagnosticExcludesUntrustedPayloadAndMessage(): void
    {
        $lines = [];
        $diagnostics = new RuntimeDiagnostics(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        [$channel] = $this->channel([], $diagnostics);

        self::assertFalse($channel->accept(new ConnectedPayloadEvent(
            'untrusted-secret-payload',
            Reliability::ReliableOrdered,
            0,
        )));

        self::assertCount(2, $lines);
        self::assertStringContainsString('"event":"play.session_closed"', $lines[1]);
        self::assertStringContainsString('"reason":"decode_failed"', $lines[1]);
        self::assertStringContainsString('"exception":', $lines[1]);
        self::assertStringNotContainsString('untrusted-secret-payload', implode('', $lines));
    }

    public function testMalformedTypedAndUnknownPacketsStillFailClosed(): void
    {
        [$malformed, $malformedClient] = $this->channel();
        self::assertFalse($malformed->accept(new ConnectedPayloadEvent(
            $malformedClient->encryptEnvelope($this->encodeFrames([
                new PacketFrame(new PacketHeader(PacketIds::ANIMATE), "\x01"),
            ])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertTrue($malformed->isClosed());

        [$unknown, $unknownClient] = $this->channel();
        self::assertFalse($unknown->accept(new ConnectedPayloadEvent(
            $unknownClient->encryptEnvelope($this->encodeFrames([
                new PacketFrame(new PacketHeader(1023), ''),
            ])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertTrue($unknown->isClosed());
    }

    public function testCreativeRequestDecodeFailureReportsActionWithoutPacketBytes(): void
    {
        $lines = [];
        $diagnostics = new RuntimeDiagnostics(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        [$channel, $client, , $entityId] = $this->channel([], $diagnostics);
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));

        $wire = hex2bin('010001101000ffffffff');
        self::assertNotFalse($wire);
        self::assertFalse($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encodeFrames([
                new PacketFrame(new PacketHeader(PacketIds::ITEM_STACK_REQUEST), $wire),
            ])),
            Reliability::ReliableOrdered,
            0,
        )));

        $diagnostic = implode('', $lines);
        self::assertStringContainsString('"event":"play.inventory_decode.protocol_trace"', $diagnostic);
        self::assertStringContainsString('"detail":"unsupported_action_type"', $diagnostic);
        self::assertStringContainsString('"action_type":16', $diagnostic);
        self::assertStringContainsString('"byte_offset":3', $diagnostic);
        self::assertStringNotContainsString(bin2hex($wire), $diagnostic);
        self::assertTrue($channel->isClosed());
    }

    public function testRepeatedRadiusRequestsRemainClampedWithoutPublishingTerrain(): void
    {
        [$channel, $clientEncryptor, $clientDecryptor, $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            self::assertTrue($channel->accept(new ConnectedPayloadEvent(
                $clientEncryptor->encryptEnvelope($this->encode([new RequestChunkRadiusPacket(32, 255)])),
                Reliability::ReliableOrdered,
                0,
            )));
            $outgoing = $channel->drainOutgoing();
            self::assertCount(1, $outgoing);
            $radius = $this->decode($clientDecryptor->decryptEnvelope($outgoing[0]->payload));
            self::assertInstanceOf(ChunkRadiusUpdatedPacket::class, $radius);
            self::assertSame(1, $radius->radius);
        }
    }

    public function testRequestedFlatSubChunksAreServedBeforeWorldAdmission(): void
    {
        [$channel, $clientEncryptor, $clientDecryptor] = $this->channel();
        $request = new SubChunkRequestPacket(0, 0, 3, 0, [
            ['x' => 0, 'y' => 0, 'z' => 0],
            ['x' => 0, 'y' => -1, 'z' => 0],
        ]);
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([$request])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertTrue($channel->tick());
        $outgoing = $channel->drainOutgoing();
        if (count($outgoing) !== 1) {
            self::fail('One sub-chunk response payload must be queued.');
        }
        $outgoingPayload = $outgoing[0];
        $batch = BedrockBatchCodec::decode(
            $clientDecryptor->decryptEnvelope($outgoingPayload->payload),
            CompressionMode::NegotiatedZlib,
            new BatchLimits(),
            256,
        );
        self::assertCount(1, $batch->packets);
        self::assertSame(PacketIds::SUB_CHUNK, $batch->packets[0]->header->packetId);
        self::assertGreaterThan(1_000, strlen($batch->packets[0]->payload));
    }

    public function testWorldBootstrapIsDrainedAcrossBoundedTicks(): void
    {
        $limits = new RuntimeLimits(maximumStreamingPacketsPerPoll: 2);
        [$channel, $client] = $this->channel([
            new VoxelShapesPacket(),
            new ChunkRadiusUpdatedPacket(1),
            new VoxelShapesPacket(),
            new VoxelShapesPacket(),
            new VoxelShapesPacket(),
        ], limits: $limits);
        self::assertCount(1, $channel->drainOutgoing());
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new RequestChunkRadiusPacket(1, 1)])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertCount(1, $channel->drainOutgoing());
        self::assertTrue($channel->tick());
        self::assertCount(2, $channel->drainOutgoing());
        self::assertTrue($channel->tick());
        self::assertCount(1, $channel->drainOutgoing());
    }

    public function testInitializationAckReleasesAdmissionWhileTerrainWorkRemainsQueued(): void
    {
        [$channel, $client, , $entityId] = $this->channel([
            new VoxelShapesPacket(),
            new ChunkRadiusUpdatedPacket(1),
        ]);
        $channel->drainOutgoing();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new RequestChunkRadiusPacket(1, 1)])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertTrue($channel->takeSpawnAcknowledged());
        self::assertFalse($channel->takeSpawnAcknowledged());
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([
                $this->movementPacket(UnsignedLong::fromInt(1)),
                new ChatPacket('untrusted-name', 'terrain is still streaming'),
            ])),
            Reliability::ReliableOrdered,
            0,
        )));
        $commands = $channel->drainCommands();
        self::assertCount(2, $commands);
        self::assertInstanceOf(MovePlayer::class, $commands[0]);
        self::assertInstanceOf(SendChat::class, $commands[1]);
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SubChunkRequestPacket(0, 0, 3, 0, [
                ['x' => 0, 'y' => 0, 'z' => 0],
            ])])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertTrue($channel->tick());
        self::assertFalse($channel->takeSpawnAcknowledged());
        self::assertFalse($channel->isClosed());
    }

    public function testRepeatedSubChunkRequestsAreAnsweredAndTrackedWithoutPayloadLogging(): void
    {
        $lines = [];
        $diagnostics = new RuntimeDiagnostics(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        [$channel, $client] = $this->channel([], $diagnostics);
        $request = new SubChunkRequestPacket(0, 0, 3, 0, [['x' => 0, 'y' => 0, 'z' => 0]]);
        foreach ([false, true] as $expectedRepeated) {
            self::assertTrue($channel->accept(new ConnectedPayloadEvent(
                $client->encryptEnvelope($this->encode([$request])),
                Reliability::ReliableOrdered,
                0,
            )));
            self::assertTrue($channel->tick());
            self::assertCount(1, $channel->drainOutgoing());
            $matching = array_values(array_filter(
                $lines,
                static fn(string $line): bool => str_contains($line, '"event":"play.subchunk_request"'),
            ));
            $last = array_pop($matching);
            self::assertIsString($last);
            self::assertStringContainsString('"repeated":' . ($expectedRepeated ? 'true' : 'false'), $last);
        }
        self::assertStringNotContainsString('offsets":[', implode('', $lines));
    }

    public function testOversizedSubChunkRequestFailsClosedBeforeQueueingResponse(): void
    {
        [$channel, $client] = $this->channel();
        $offsets = array_fill(0, 65, ['x' => 0, 'y' => 0, 'z' => 0]);
        self::assertFalse($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SubChunkRequestPacket(0, 0, 3, 0, $offsets)])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertTrue($channel->isClosed());
        self::assertSame([], $channel->drainOutgoing());
    }

    public function testFlatWorldStreamsCompleteViewAndExtendsAfterAuthoritativeChunkMovement(): void
    {
        $data = BedrockDataSet::bundled();
        $network = $data->blockStateRegistry();
        $internal = new BlockStateRegistry($network->states());
        $worldRepository = new ChunkRepository(128);
        $world = new World(
            new WorldMetadata('stream-test', 0),
            new FlatWorldGenerator(FixedFlatBlockPalette::fromRegistry($internal)),
            $worldRepository,
        );
        $serializer = new BedrockChunkPacketSerializer(
            new BlockNetworkTranslator($internal, $network),
        );
        [$channel, $client, $server] = $this->channel(
            [new ChunkRadiusUpdatedPacket(4)],
            limits: new RuntimeLimits(
                maximumOutgoingPayloadsPerSession: 128,
                maximumChunkRadius: 4,
                preloadedChunkRadius: 4,
                maximumStreamingPacketsPerPoll: 4,
            ),
            world: $world,
            serializer: $serializer,
            generatePerTick: 4,
            sendPerTick: 2,
        );
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new RequestChunkRadiusPacket(4, 32)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $initial = $channel->drainOutgoing();
        self::assertCount(2, $initial);
        self::assertInstanceOf(ChunkRadiusUpdatedPacket::class, $this->decode($server->decryptEnvelope($initial[0]->payload)));
        self::assertInstanceOf(NetworkChunkPublisherUpdatePacket::class, $this->decode($server->decryptEnvelope($initial[1]->payload)));
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new RequestChunkRadiusPacket(1, 1)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $repeat = $channel->drainOutgoing();
        self::assertCount(1, $repeat);
        $repeatedRadius = $this->decode($server->decryptEnvelope($repeat[0]->payload));
        self::assertInstanceOf(ChunkRadiusUpdatedPacket::class, $repeatedRadius);
        self::assertSame(4, $repeatedRadius->radius);
        self::assertFalse($channel->hasSentChunkAt(0.0, 0.0));
        self::assertFalse($channel->takeChunkVisibilityChanged());

        $coordinates = [];
        $spawnStatus = 0;
        for ($poll = 0; $poll < 64 && count($coordinates) < 81; ++$poll) {
            self::assertTrue($channel->worldTick());
            $outgoingThisTick = $channel->drainOutgoing();
            self::assertLessThanOrEqual(3, count($outgoingThisTick), 'At most two chunks plus the one-shot spawn status may be sent.');
            foreach ($outgoingThisTick as $outgoing) {
                $packet = $this->decode($server->decryptEnvelope($outgoing->payload));
                if ($packet instanceof LevelChunkPacket) {
                    $coordinates[$packet->chunkX . ':' . $packet->chunkZ] = true;
                } elseif ($packet instanceof \Bedriox\Protocol\Packet\PlayStatusPacket) {
                    ++$spawnStatus;
                }
            }
        }
        self::assertCount(81, $coordinates);
        self::assertSame(1, $spawnStatus);
        self::assertArrayHasKey('-4:-4', $coordinates);
        self::assertArrayHasKey('4:4', $coordinates);
        self::assertTrue($channel->hasSentChunkAt(0.0, 0.0));
        self::assertTrue($channel->takeChunkVisibilityChanged());
        self::assertFalse($channel->takeChunkVisibilityChanged());

        for ($idleTick = 0; $idleTick < 3; ++$idleTick) {
            self::assertTrue($channel->worldTick(), 'A completely streamed view must remain idle without closing.');
            $idleOutgoing = $channel->drainOutgoing();
            self::assertCount(0, $idleOutgoing);
        }

        $movedCoordinates = [];
        foreach ([1, 2, 3] as $centerChunkX) {
            self::assertTrue($channel->updateChunkView($centerChunkX * 16.0, 64.0, 0.0));
            $publisher = $channel->drainOutgoing();
            self::assertCount(1, $publisher);
            self::assertInstanceOf(
                NetworkChunkPublisherUpdatePacket::class,
                $this->decode($server->decryptEnvelope($publisher[0]->payload)),
            );
            self::assertTrue($channel->worldTick());
            foreach ($channel->drainOutgoing() as $outgoing) {
                $packet = $this->decode($server->decryptEnvelope($outgoing->payload));
                self::assertInstanceOf(LevelChunkPacket::class, $packet);
                $movedCoordinates[$packet->chunkX . ':' . $packet->chunkZ] = true;
            }
        }
        self::assertFalse($channel->hasSentChunkAt(-64.0, 0.0));
        self::assertTrue($channel->takeChunkVisibilityChanged());

        $consecutiveIdleTicks = 0;
        for ($poll = 0; $poll < 64 && $consecutiveIdleTicks < 3; ++$poll) {
            self::assertTrue($channel->worldTick());
            $outgoing = $channel->drainOutgoing();
            $consecutiveIdleTicks = $outgoing === [] ? $consecutiveIdleTicks + 1 : 0;
            foreach ($outgoing as $payload) {
                $packet = $this->decode($server->decryptEnvelope($payload->payload));
                self::assertInstanceOf(LevelChunkPacket::class, $packet);
                $movedCoordinates[$packet->chunkX . ':' . $packet->chunkZ] = true;
            }
        }
        self::assertSame(3, $consecutiveIdleTicks, 'The final moved view did not drain to idle.');
        foreach (range(5, 7) as $chunkX) {
            foreach (range(-4, 4) as $chunkZ) {
                self::assertArrayHasKey($chunkX . ':' . $chunkZ, $movedCoordinates);
            }
        }
        $channel->close();
        $worldRepository->clear();
    }

    public function testQueuedChunkIsSerializedFromLatestAuthoritativeWorldAtSendTime(): void
    {
        $data = BedrockDataSet::bundled();
        $network = $data->blockStateRegistry();
        $internal = new BlockStateRegistry($network->states());
        $palette = FixedFlatBlockPalette::fromRegistry($internal);
        $world = new World(
            new WorldMetadata('queued-mutation-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(32),
        );
        $serializer = new BedrockChunkPacketSerializer(
            new BlockNetworkTranslator($internal, $network),
        );
        [$channel, $client, $server] = $this->channel(
            [new ChunkRadiusUpdatedPacket(1)],
            world: $world,
            serializer: $serializer,
            generatePerTick: 4,
            sendPerTick: 1,
            viewDistance: 1,
            spawnRadius: 1,
        );
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new RequestChunkRadiusPacket(1, 1)])),
            Reliability::ReliableOrdered,
            0,
        )));
        foreach ($channel->drainOutgoing() as $outgoing) {
            $server->decryptEnvelope($outgoing->payload);
        }

        self::assertTrue($channel->worldTick());
        $first = $channel->drainOutgoing();
        self::assertCount(1, $first);
        self::assertInstanceOf(LevelChunkPacket::class, $this->decode($server->decryptEnvelope($first[0]->payload)));

        $world->setBlockState(-16, 63, 0, $palette->air);
        self::assertTrue($channel->worldTick());
        $second = $channel->drainOutgoing();
        self::assertCount(1, $second);
        $sent = $this->decode($server->decryptEnvelope($second[0]->payload));
        self::assertInstanceOf(LevelChunkPacket::class, $sent);
        self::assertSame([-1, 0], [$sent->chunkX, $sent->chunkZ]);
        self::assertSame(
            $serializer->serialize($world->chunk(new ChunkPosition(-1, 0)))->encode(),
            $sent->encode(),
        );
    }

    public function testPreparedChunkWorkerResultIsEncryptedAndSentWithoutMainThreadSerialization(): void
    {
        $data = BedrockDataSet::bundled();
        $network = $data->blockStateRegistry();
        $internal = new BlockStateRegistry($network->states());
        $world = new World(
            new WorldMetadata('prepared-stream-test', 0),
            new FlatWorldGenerator(FixedFlatBlockPalette::fromRegistry($internal)),
            new ChunkRepository(32),
        );
        $serializer = new BedrockChunkPacketSerializer(
            new BlockNetworkTranslator($internal, $network),
        );
        $workers = new ImmediatePreparationWorkerDispatcher();
        $cache = new PreparedChunkCache($workers, 5, $internal, str_repeat('b', 32));
        [$channel, $client, $server] = $this->channel(
            [new ChunkRadiusUpdatedPacket(1)],
            world: $world,
            serializer: $serializer,
            generatePerTick: 4,
            sendPerTick: 2,
            viewDistance: 1,
            spawnRadius: 1,
            preparedChunks: $cache,
        );
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new RequestChunkRadiusPacket(1, 1)])),
            Reliability::ReliableOrdered,
            0,
        )));
        foreach ($channel->drainOutgoing() as $outgoing) {
            $server->decryptEnvelope($outgoing->payload);
        }

        self::assertTrue($channel->worldTick());
        self::assertSame([], $channel->drainOutgoing());

        self::assertTrue($channel->queuePacket(new SystemTextPacket('control-before-prepared-chunk')));
        $control = $channel->drainOutgoing();
        self::assertCount(1, $control);
        $controlPacket = $this->decode($server->decryptEnvelope($control[0]->payload));
        self::assertInstanceOf(SystemTextPacket::class, $controlPacket);
        self::assertSame('control-before-prepared-chunk', $controlPacket->message);

        $workers->completeAll();
        self::assertTrue($channel->worldTick());
        $outgoing = $channel->drainOutgoing();
        self::assertNotEmpty($outgoing);
        self::assertLessThanOrEqual(2, count($outgoing));
        $preparedPayload = $outgoing[0] ?? null;
        self::assertInstanceOf(\Bedriox\Server\Runtime\OutgoingPlayPayload::class, $preparedPayload);
        self::assertInstanceOf(LevelChunkPacket::class, $this->decode($server->decryptEnvelope($preparedPayload->payload)));
        self::assertSame(['entries' => 0, 'hits' => 0, 'misses' => 0], $serializer->cacheMetrics());
        self::assertGreaterThanOrEqual(1, $workers->submissions);
    }

    public function testSerializationFailureReleasesTheJustRetainedChunk(): void
    {
        $data = BedrockDataSet::bundled();
        $network = $data->blockStateRegistry();
        $internal = new BlockStateRegistry($network->states());
        $repository = new ChunkRepository(16);
        $unknown = new InternalBlockStateId(count($internal->states()) + 10);
        $generator = new class ($unknown) implements WorldGenerator {
            public function __construct(private readonly InternalBlockStateId $unknown) {}

            public function name(): string
            {
                return 'invalid-test';
            }

            public function generate(ChunkPosition $position): Chunk
            {
                return new Chunk($position, $this->unknown, [SubChunk::uniform(0, $this->unknown)]);
            }

            public function defaultSpawn(): SpawnPosition
            {
                return new SpawnPosition(0, 64, 0);
            }
        };
        $world = new World(new WorldMetadata('invalid-stream-test', 0), $generator, $repository);
        $serializer = new BedrockChunkPacketSerializer(
            new BlockNetworkTranslator($internal, $network),
        );
        [$channel, $client] = $this->channel(
            [new ChunkRadiusUpdatedPacket(1)],
            world: $world,
            serializer: $serializer,
        );
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new RequestChunkRadiusPacket(1, 1)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $channel->drainOutgoing();

        self::assertFalse($channel->worldTick());
        self::assertTrue($channel->isClosed());
        $repository->clear();
        self::assertSame(0, $repository->count());
    }

    public function testGeneratedChunkQueueRemainsBoundedWhenGenerationOutpacesSending(): void
    {
        $data = BedrockDataSet::bundled();
        $network = $data->blockStateRegistry();
        $internal = new BlockStateRegistry($network->states());
        $world = new World(
            new WorldMetadata('bounded-queue-test', 0),
            new FlatWorldGenerator(FixedFlatBlockPalette::fromRegistry($internal)),
            new ChunkRepository(512),
        );
        [$channel, $client] = $this->channel(
            [new ChunkRadiusUpdatedPacket(8)],
            limits: new RuntimeLimits(maximumChunkRadius: 8, preloadedChunkRadius: 1),
            world: $world,
            serializer: new BedrockChunkPacketSerializer(
                new BlockNetworkTranslator($internal, $network),
            ),
            generatePerTick: 64,
            sendPerTick: 1,
            viewDistance: 8,
            spawnRadius: 1,
        );
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new RequestChunkRadiusPacket(8, 8)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $channel->drainOutgoing();
        for ($tick = 0; $tick < 20; ++$tick) {
            self::assertTrue($channel->worldTick());
            self::assertLessThanOrEqual(128, $channel->queuedGeneratedChunkCount());
            $channel->drainOutgoing();
        }
        self::assertGreaterThan(0, $channel->queuedGeneratedChunkCount());
    }

    public function testMovingDuringBacklogReprioritizesDeliveryAroundTheNewCenter(): void
    {
        $data = BedrockDataSet::bundled();
        $network = $data->blockStateRegistry();
        $internal = new BlockStateRegistry($network->states());
        $world = new World(
            new WorldMetadata('moving-backlog-priority-test', 0),
            new FlatWorldGenerator(FixedFlatBlockPalette::fromRegistry($internal)),
            new ChunkRepository(256),
        );
        [$channel, $client, $server] = $this->channel(
            [new ChunkRadiusUpdatedPacket(4)],
            limits: new RuntimeLimits(maximumChunkRadius: 4, preloadedChunkRadius: 1),
            world: $world,
            serializer: new BedrockChunkPacketSerializer(
                new BlockNetworkTranslator($internal, $network),
            ),
            generatePerTick: 64,
            sendPerTick: 1,
            viewDistance: 4,
            spawnRadius: 1,
        );
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new RequestChunkRadiusPacket(4, 4)])),
            Reliability::ReliableOrdered,
            0,
        )));
        foreach ($channel->drainOutgoing() as $outgoing) {
            $server->decryptEnvelope($outgoing->payload);
        }

        self::assertTrue($channel->worldTick());
        $initial = $channel->drainOutgoing();
        self::assertCount(1, $initial);
        $initialChunk = $this->decode($server->decryptEnvelope($initial[0]->payload));
        self::assertInstanceOf(LevelChunkPacket::class, $initialChunk);
        self::assertSame([0, 0], [$initialChunk->chunkX, $initialChunk->chunkZ]);
        self::assertGreaterThan(0, $channel->queuedGeneratedChunkCount());

        self::assertTrue($channel->updateChunkView(64.0, 64.0, 0.0));
        foreach ($channel->drainOutgoing() as $outgoing) {
            self::assertInstanceOf(
                NetworkChunkPublisherUpdatePacket::class,
                $this->decode($server->decryptEnvelope($outgoing->payload)),
            );
        }
        self::assertTrue($channel->worldTick());
        $recentered = $channel->drainOutgoing();
        self::assertCount(1, $recentered);
        $recenteredChunk = $this->decode($server->decryptEnvelope($recentered[0]->payload));
        self::assertInstanceOf(LevelChunkPacket::class, $recenteredChunk);
        self::assertSame([4, 0], [$recenteredChunk->chunkX, $recenteredChunk->chunkZ]);

        $channel->close();
        $world->close();
    }

    public function testHiddenPrefetchRingIsNotSentUntilMovementMakesItVisible(): void
    {
        $data = BedrockDataSet::bundled();
        $network = $data->blockStateRegistry();
        $internal = new BlockStateRegistry($network->states());
        $repository = new ChunkRepository(32);
        $world = new World(
            new WorldMetadata('prefetch-test', 0),
            new FlatWorldGenerator(FixedFlatBlockPalette::fromRegistry($internal)),
            $repository,
        );
        [$channel, $client, $server] = $this->channel(
            [new ChunkRadiusUpdatedPacket(1)],
            limits: new RuntimeLimits(maximumChunkRadius: 1, preloadedChunkRadius: 1),
            world: $world,
            serializer: new BedrockChunkPacketSerializer(
                new BlockNetworkTranslator($internal, $network),
            ),
            generatePerTick: 4,
            sendPerTick: 3,
            viewDistance: 1,
            spawnRadius: 1,
            prefetchRadius: 1,
        );
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new RequestChunkRadiusPacket(1, 1)])),
            Reliability::ReliableOrdered,
            0,
        )));
        foreach ($channel->drainOutgoing() as $outgoing) {
            $server->decryptEnvelope($outgoing->payload);
        }

        $visible = [];
        for ($tick = 0; $tick < 16 && $repository->count() < 25; ++$tick) {
            self::assertTrue($channel->worldTick());
            foreach ($channel->drainOutgoing() as $outgoing) {
                $packet = $this->decode($server->decryptEnvelope($outgoing->payload));
                if ($packet instanceof LevelChunkPacket) {
                    $visible[$packet->chunkX . ':' . $packet->chunkZ] = true;
                }
            }
        }
        self::assertCount(9, $visible, 'The hidden ring must not be sent before it becomes visible.');
        self::assertSame(25, $repository->count(), 'The complete one-chunk hidden ring should be prepared.');
        $ready = $channel->chunkStreamingSnapshot();
        self::assertSame(0, $ready->visiblePending);
        self::assertSame(0, $ready->prefetchPending);
        self::assertSame(0, $ready->deliveryQueued);
        self::assertSame(25, $ready->retained);

        self::assertTrue($channel->updateChunkView(16.0, 64.0, 0.0));
        $moved = $channel->chunkStreamingSnapshot();
        self::assertSame(3, $moved->visiblePending);
        self::assertSame(5, $moved->prefetchPending);
        foreach ($channel->drainOutgoing() as $outgoing) {
            self::assertInstanceOf(
                NetworkChunkPublisherUpdatePacket::class,
                $this->decode($server->decryptEnvelope($outgoing->payload)),
            );
        }
        self::assertTrue($channel->worldTick());
        $promoted = [];
        foreach ($channel->drainOutgoing() as $outgoing) {
            $packet = $this->decode($server->decryptEnvelope($outgoing->payload));
            self::assertInstanceOf(LevelChunkPacket::class, $packet);
            $promoted[] = [$packet->chunkX, $packet->chunkZ];
        }
        self::assertSame([[2, 0], [2, -1], [2, 1]], $promoted);

        $channel->close();
        $repository->clear();
    }

    public function testValidatedInventoryRequestsBecomeOrderedAuthoritativeCommands(): void
    {
        [$channel, $clientEncryptor, , $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([new SetPlayerInventoryOptionsPacket(0, 0, false, 0, 0)])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([new ItemStackRequestPacket([37, -5])])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertSame([], $channel->drainOutgoing());
        $commands = $channel->drainCommands();
        self::assertCount(2, $commands);
        self::assertContainsOnlyInstancesOf(ApplyInventoryStackRequest::class, $commands);
        self::assertSame([37, -5], array_map(
            static fn(ApplyInventoryStackRequest $command): int => $command->requestId,
            $commands,
        ));
        self::assertSame(['empty_actions', 'empty_actions'], array_map(
            static fn(ApplyInventoryStackRequest $command): ?string => $command->rejectionReason,
            $commands,
        ));
    }

    public function testCurrentItemUseAndReleaseTransactionsBecomeAuthoritativeCommands(): void
    {
        [$channel, $clientEncryptor, , $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $item = InventoryItemStack::empty();
        $position = new InventoryVector3(0.0, 64.0, 0.0);
        $use = new InventoryTransactionPacket(0, [], [], new ItemUseInventoryTransaction(
            ItemUseActionType::Use,
            ItemUseTriggerType::PlayerInput,
            new BlockPosition(0, 0, 0),
            0,
            2,
            HandSlot::Mainhand,
            $item,
            $position,
            $position,
            0,
            ItemUsePredictedResult::Failure,
            ItemUseClientCooldownState::Off,
        ));
        $consume = new InventoryTransactionPacket(0, [], [], new ItemReleaseInventoryTransaction(
            ItemReleaseActionType::Consume,
            2,
            $item,
            $position,
        ));
        $release = new InventoryTransactionPacket(0, [], [], new ItemReleaseInventoryTransaction(
            ItemReleaseActionType::Release,
            2,
            $item,
            $position,
        ));

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([$use, $consume, $release])),
            Reliability::ReliableOrdered,
            0,
        )));
        $commands = $channel->drainCommands();
        self::assertCount(3, $commands);
        self::assertInstanceOf(UseItem::class, $commands[0]);
        self::assertInstanceOf(UseItem::class, $commands[1]);
        self::assertInstanceOf(ReleaseItem::class, $commands[2]);
        self::assertSame(2, $commands[0]->hotbarSlot);
        self::assertSame(2, $commands[1]->hotbarSlot);
        self::assertSame(2, $commands[2]->hotbarSlot);
    }

    public function testLegacyPredictedSplitBecomesAnAuthoritativeBoundedTransfer(): void
    {
        [$channel, $clientEncryptor, , $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $grass64 = new InventoryItemStack(7, 64, 0, 1, 4, '');
        $grass32 = new InventoryItemStack(7, 32, 0, 1, 4, '');
        $predicted = new InventoryTransactionPacket(0, [], [
            new InventoryAction(
                new InventorySource(InventorySourceType::Container, 0),
                0,
                $grass64,
                $grass32,
            ),
            new InventoryAction(
                new InventorySource(InventorySourceType::Container, 0),
                1,
                InventoryItemStack::empty(),
                new InventoryItemStack(7, 32, 0, -1, 4, ''),
            ),
        ], new BasicInventoryTransaction(InventoryTransactionType::Normal));

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([$predicted])),
            Reliability::ReliableOrdered,
            0,
        )));
        $commands = $channel->drainCommands();
        self::assertCount(1, $commands);
        self::assertInstanceOf(ApplyInventoryStackRequest::class, $commands[0]);
        self::assertSame(InventoryResponseMode::LegacySlotSync, $commands[0]->responseMode);
        self::assertNull($commands[0]->rejectionReason);
        self::assertCount(1, $commands[0]->actions);
        self::assertSame(32, $commands[0]->actions[0]->count);
        self::assertSame(64, $commands[0]->actions[0]->source->expectedCount);
        self::assertSame(0, $commands[0]->actions[0]->destination->expectedCount);
        self::assertSame(0, $commands[0]->actions[0]->source->slot);
        self::assertSame(1, $commands[0]->actions[0]->destination->slot);
    }

    public function testLegacyRequestedSlotCorrectionsScheduleOnlyExactAuthoritativeSlots(): void
    {
        [$channel, $clientEncryptor, , $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $packet = new InventoryTransactionPacket(-2, [
            new InventoryLegacySlot(FullContainerName::ARMOR, "\0"),
        ], [], new BasicInventoryTransaction(InventoryTransactionType::Normal));

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([$packet])),
            Reliability::ReliableOrdered,
            0,
        )));

        $commands = $channel->drainCommands();
        self::assertCount(2, $commands);
        self::assertInstanceOf(ApplyInventoryStackRequest::class, $commands[0]);
        self::assertSame('empty_actions', $commands[0]->rejectionReason);
        self::assertInstanceOf(SyncInventorySlots::class, $commands[1]);
        self::assertCount(1, $commands[1]->slots);
        self::assertSame(InventoryContainer::Armor, $commands[1]->slots[0]->container);
        self::assertSame(0, $commands[1]->slots[0]->slot);
    }

    public function testLegacyRequestedSlotCorrectionGroupsAreBounded(): void
    {
        [$channel, $clientEncryptor, , $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $packet = new InventoryTransactionPacket(
            -2,
            array_fill(0, 11, new InventoryLegacySlot(FullContainerName::INVENTORY, "\0")),
            [],
            new BasicInventoryTransaction(InventoryTransactionType::Normal),
        );

        self::assertFalse($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([$packet])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertTrue($channel->isClosed());
        self::assertSame([], $channel->drainCommands());
    }

    public function testLegacyDropTransactionKeepsRequestedCorrectionFocusedOnItsSourceSlot(): void
    {
        $data = BedrockDataSet::bundled();
        $internal = new BlockStateRegistry($data->blockStateRegistry()->states());
        $projector = BedrockInventoryPacketProjector::fromData(
            $data,
            new BlockNetworkTranslator($internal, $data->blockStateRegistry()),
        );
        [$channel, $clientEncryptor, , $entityId] = $this->channel(inventoryProjector: $projector);
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $grassCreativeId = null;
        foreach ($data->creativeInventoryRegistry()->entries() as $entry) {
            if ($entry->item()->identifier() === 'minecraft:grass_block') {
                $grassCreativeId = $entry->creativeNetworkId();
                break;
            }
        }
        self::assertNotNull($grassCreativeId);
        $grass = $projector->creativeStack($grassCreativeId, 1);
        $projected = $projector->toProtocol($grass);
        $grass64 = new InventoryItemStack(
            $projected->runtimeId,
            64,
            $projected->aux,
            1,
            $projected->blockRuntimeId,
            $projected->userData,
        );
        $grass63 = new InventoryItemStack(
            $projected->runtimeId,
            63,
            $projected->aux,
            1,
            $projected->blockRuntimeId,
            $projected->userData,
        );
        $grassOne = new InventoryItemStack(
            $projected->runtimeId,
            1,
            $projected->aux,
            null,
            $projected->blockRuntimeId,
            $projected->userData,
        );
        $drop = new InventoryTransactionPacket(-2, [
            new InventoryLegacySlot(FullContainerName::INVENTORY, "\0"),
        ], [
            new InventoryAction(
                new InventorySource(InventorySourceType::WorldInteraction, flag: InventorySourceFlag::DropItem),
                0,
                InventoryItemStack::empty(),
                $grassOne,
            ),
            new InventoryAction(
                new InventorySource(InventorySourceType::Container, 0),
                0,
                $grass64,
                $grass63,
            ),
        ], new BasicInventoryTransaction(InventoryTransactionType::Normal));

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([$drop])),
            Reliability::ReliableOrdered,
            0,
        )));
        $commands = $channel->drainCommands();
        self::assertCount(2, $commands);
        self::assertInstanceOf(DropItem::class, $commands[0]);
        self::assertSame(1, $commands[0]->count);
        self::assertSame(64, $commands[0]->source->expectedCount);
        self::assertSame(InventoryResponseMode::LegacySlotSync, $commands[0]->responseMode);
        self::assertInstanceOf(SyncInventorySlots::class, $commands[1]);
        self::assertCount(1, $commands[1]->slots);
        self::assertSame(InventoryContainer::Main, $commands[1]->slots[0]->container);
        self::assertSame(0, $commands[1]->slots[0]->slot);
    }

    public function testItemStackDropRequestBecomesOneAuthoritativeDropCommand(): void
    {
        $diagnostics = [];
        [$channel, $clientEncryptor, , $entityId] = $this->channel(diagnostics: new RuntimeDiagnostics(
            static function (string $line) use (&$diagnostics): void {
                $diagnostics[] = $line;
            },
        ));
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $request = new ItemStackRequest(-17, [
            new DropItemStackRequestAction(
                3,
                new ItemStackRequestSlot(new FullContainerName(FullContainerName::HOTBAR), 0, 1),
                false,
            ),
        ]);

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([new ItemStackRequestPacket([$request])])),
            Reliability::ReliableOrdered,
            0,
        )), implode('', $diagnostics));
        $commands = $channel->drainCommands();
        self::assertCount(1, $commands);
        self::assertInstanceOf(DropItem::class, $commands[0]);
        self::assertSame(3, $commands[0]->count);
        self::assertSame(-17, $commands[0]->requestId);
        self::assertSame(InventoryResponseMode::ItemStackResponse, $commands[0]->responseMode);
    }

    public function testEmbeddedInventoryRequestBecomesACommandAfterItsMovementFrame(): void
    {
        [$channel, $clientEncryptor, , $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $ordinary = $this->movementPacket(UnsignedLong::fromInt(1))->encode();
        $stackBase = substr($ordinary, 0, 32) . "\1\x48" . substr($ordinary, 33);
        $withStackRequest = substr($stackBase, 0, 59) . "\1\x0a\0\0\xff\xff\xff\xff" . substr($stackBase, 60);
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encodeFrames([new PacketFrame(
                new PacketHeader(PacketIds::PLAYER_AUTH_INPUT),
                $withStackRequest,
            )])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertSame([], $channel->drainOutgoing());
        $commands = $channel->drainCommands();
        self::assertCount(2, $commands);
        self::assertInstanceOf(MovePlayer::class, $commands[0]);
        self::assertInstanceOf(ApplyInventoryStackRequest::class, $commands[1]);
        self::assertSame(5, $commands[1]->requestId);
    }

    public function testEmbeddedInventoryRequestIsNotDiscardedWhenItSharesAMovementTick(): void
    {
        [$channel, $clientEncryptor, , $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $ordinary = $this->movementPacket(UnsignedLong::fromInt(1));
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([$ordinary])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertCount(1, $channel->drainCommands());

        $encoded = $ordinary->encode();
        $stackBase = substr($encoded, 0, 32) . "\1\x48" . substr($encoded, 33);
        $withStackRequest = substr($stackBase, 0, 59) . "\1\x0a\0\0\xff\xff\xff\xff" . substr($stackBase, 60);
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encodeFrames([new PacketFrame(
                new PacketHeader(PacketIds::PLAYER_AUTH_INPUT),
                $withStackRequest,
            )])),
            Reliability::ReliableOrdered,
            0,
        )));

        $commands = $channel->drainCommands();
        self::assertCount(1, $commands);
        self::assertInstanceOf(ApplyInventoryStackRequest::class, $commands[0]);
        self::assertSame(5, $commands[0]->requestId);
    }

    public function testTypedTakeRequestMapsOnlyBoundedMainAndCursorIntent(): void
    {
        [$channel, $clientEncryptor, , $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $request = new ItemStackRequest(-9, [new TakeItemStackRequestAction(
            32,
            new ItemStackRequestSlot(new FullContainerName(FullContainerName::HOTBAR), 0, 1),
            new ItemStackRequestSlot(new FullContainerName(FullContainerName::CURSOR), 0, 0),
        )]);
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([new ItemStackRequestPacket([$request])])),
            Reliability::ReliableOrdered,
            0,
        )));

        $commands = $channel->drainCommands();
        self::assertCount(1, $commands);
        self::assertInstanceOf(ApplyInventoryStackRequest::class, $commands[0]);
        self::assertSame(-9, $commands[0]->requestId);
        self::assertNull($commands[0]->rejectionReason);
        self::assertCount(1, $commands[0]->actions);
        self::assertSame(InventoryStackRequestActionType::Take, $commands[0]->actions[0]->type);
        self::assertSame(InventoryContainer::Main, $commands[0]->actions[0]->source->container);
        self::assertSame(
            FullContainerName::HOTBAR,
            $commands[0]->actions[0]->source->responseContainerId,
        );
        self::assertSame(InventoryContainer::Cursor, $commands[0]->actions[0]->destination->container);
        self::assertSame(32, $commands[0]->actions[0]->count);
    }

    public function testOffhandRequestUsesInternalSlotZeroAndPreservesTheClientResponseSlot(): void
    {
        [$channel, $clientEncryptor, , $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $request = new ItemStackRequest(-11, [new TakeItemStackRequestAction(
            1,
            new ItemStackRequestSlot(new FullContainerName(FullContainerName::HOTBAR), 0, 1),
            new ItemStackRequestSlot(new FullContainerName(FullContainerName::OFFHAND), 40, 0),
        )]);

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([new ItemStackRequestPacket([$request])])),
            Reliability::ReliableOrdered,
            0,
        )));

        $commands = $channel->drainCommands();
        self::assertCount(1, $commands);
        self::assertInstanceOf(ApplyInventoryStackRequest::class, $commands[0]);
        self::assertNull($commands[0]->rejectionReason);
        $destination = $commands[0]->actions[0]->destination;
        self::assertSame(InventoryContainer::Offhand, $destination->container);
        self::assertSame(0, $destination->slot);
        self::assertSame(40, $destination->responseSlotId());
        self::assertSame(FullContainerName::OFFHAND, $destination->responseContainerId);
    }

    public function testCreativeRequestResolvesAdvertisedItemAndCreatedOutputAuthoritatively(): void
    {
        $data = BedrockDataSet::bundled();
        $internal = new BlockStateRegistry($data->blockStateRegistry()->states());
        $projector = BedrockInventoryPacketProjector::fromData(
            $data,
            new BlockNetworkTranslator($internal, $data->blockStateRegistry()),
        );
        [$channel, $clientEncryptor, , $entityId] = $this->channel(inventoryProjector: $projector);
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $request = new ItemStackRequest(-13, [
            new CraftCreativeItemStackRequestAction(1, 1),
            new CreateItemStackRequestAction(50),
            new TakeItemStackRequestAction(
                1,
                new ItemStackRequestSlot(new FullContainerName(FullContainerName::CREATED_OUTPUT), 50, -13),
                new ItemStackRequestSlot(new FullContainerName(FullContainerName::HOTBAR), 1, 0),
            ),
        ]);

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([new ItemStackRequestPacket([$request])])),
            Reliability::ReliableOrdered,
            0,
        )));
        $commands = $channel->drainCommands();
        self::assertCount(1, $commands);
        self::assertInstanceOf(ApplyInventoryStackRequest::class, $commands[0]);
        self::assertNull($commands[0]->rejectionReason);
        self::assertEquals($projector->creativeStack(1, 1), $commands[0]->authoritativeCreativeStack);
        self::assertCount(1, $commands[0]->actions);
        self::assertSame(InventoryContainer::CreatedOutput, $commands[0]->actions[0]->source->container);
        self::assertSame(50, $commands[0]->actions[0]->source->slot);
        self::assertSame(InventoryContainer::Main, $commands[0]->actions[0]->destination->container);
        self::assertFalse($channel->isClosed());
    }

    public function testCreativeSelectionWithCraftResultsAdvisoryRemainsAuthoritative(): void
    {
        $data = BedrockDataSet::bundled();
        $internal = new BlockStateRegistry($data->blockStateRegistry()->states());
        $projector = BedrockInventoryPacketProjector::fromData(
            $data,
            new BlockNetworkTranslator($internal, $data->blockStateRegistry()),
        );
        [$channel, $clientEncryptor, , $entityId] = $this->channel(inventoryProjector: $projector);
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $request = new ItemStackRequest(-13, [
            new CraftCreativeItemStackRequestAction(1, 1),
            new \Bedriox\Protocol\Packet\CraftResultsItemStackRequestAction([], 1),
            new TakeItemStackRequestAction(
                1,
                new ItemStackRequestSlot(new FullContainerName(FullContainerName::CREATED_OUTPUT), 50, -13),
                new ItemStackRequestSlot(new FullContainerName(FullContainerName::HOTBAR), 1, 0),
            ),
        ]);

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([new ItemStackRequestPacket([$request])])),
            Reliability::ReliableOrdered,
            0,
        )));
        $commands = $channel->drainCommands();
        self::assertCount(1, $commands);
        self::assertInstanceOf(ApplyInventoryStackRequest::class, $commands[0]);
        self::assertNull($commands[0]->rejectionReason);
        self::assertCount(1, $commands[0]->actions);
        self::assertFalse($channel->isClosed());
    }

    public function testUnknownCreativeItemIsCorrectedWithoutClosingTheSession(): void
    {
        $data = BedrockDataSet::bundled();
        $internal = new BlockStateRegistry($data->blockStateRegistry()->states());
        [$channel, $clientEncryptor, , $entityId] = $this->channel(inventoryProjector: BedrockInventoryPacketProjector::fromData(
            $data,
            new BlockNetworkTranslator($internal, $data->blockStateRegistry()),
        ));
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $request = new ItemStackRequest(-14, [new CraftCreativeItemStackRequestAction(0xffffffff, 1)]);
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $clientEncryptor->encryptEnvelope($this->encode([new ItemStackRequestPacket([$request])])),
            Reliability::ReliableOrdered,
            0,
        )));

        $commands = $channel->drainCommands();
        self::assertCount(1, $commands);
        self::assertInstanceOf(ApplyInventoryStackRequest::class, $commands[0]);
        self::assertSame('unknown_creative_item', $commands[0]->rejectionReason);
        self::assertFalse($channel->isClosed());
    }

    public function testTypedBlockAndItemInteractionsDoNotCloseInitializedSession(): void
    {
        foreach ([self::BLOCK_ACTION_INPUT, self::ITEM_USE_INPUT] as $hex) {
            [$channel, $clientEncryptor, , $entityId] = $this->channel();
            self::assertTrue($channel->accept(new ConnectedPayloadEvent(
                $clientEncryptor->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
                Reliability::ReliableOrdered,
                0,
            )));
            $payload = hex2bin($hex);
            self::assertIsString($payload);
            self::assertTrue($channel->accept(new ConnectedPayloadEvent(
                $clientEncryptor->encryptEnvelope($this->encodeFrames([new PacketFrame(
                    new PacketHeader(PacketIds::PLAYER_AUTH_INPUT),
                    $payload,
                )])),
                Reliability::ReliableOrdered,
                0,
            )));
            self::assertFalse($channel->isClosed());
            $commands = $channel->drainCommands();
            self::assertCount(1, $commands);
            self::assertInstanceOf(MovePlayer::class, $commands[0]);
        }
    }

    public function testLiteralBlockActionsBecomeAuthoritativeSimulationCommands(): void
    {
        $data = BedrockDataSet::bundled();
        $network = $data->blockStateRegistry();
        $internal = new BlockStateRegistry($network->states());
        $translator = new BlockNetworkTranslator($internal, $network);
        $palette = FixedFlatBlockPalette::fromRegistry($internal);
        $repository = new ChunkRepository(32);
        $world = new World(
            new WorldMetadata('block-feedback-test', 0),
            new FlatWorldGenerator($palette),
            $repository,
        );
        [$channel, $client, $server, $entityId] = $this->channel(
            [new ChunkRadiusUpdatedPacket(1)],
            world: $world,
            serializer: new BedrockChunkPacketSerializer($translator),
            viewDistance: 1,
            spawnRadius: 1,
            fixedFlatRuntimeIds: $palette->toNetworkRuntimeIds($translator),
        );
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new RequestChunkRadiusPacket(1, 1)])),
            Reliability::ReliableOrdered,
            0,
        )));
        foreach ($channel->drainOutgoing() as $outgoing) {
            $server->decryptEnvelope($outgoing->payload);
        }
        for ($tick = 0; $tick < 12; ++$tick) {
            self::assertTrue($channel->worldTick());
            foreach ($channel->drainOutgoing() as $outgoing) {
                $server->decryptEnvelope($outgoing->payload);
            }
        }
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));

        $grassBefore = $world->chunk(new ChunkPosition(0, -1))->blockStateAt(1, 63, 14);
        self::assertSame($palette->grassBlock->value, $grassBefore->value);
        $sendLiteral = function (string $hex) use ($channel, $client): void {
            $payload = hex2bin($hex);
            self::assertIsString($payload);
            self::assertTrue($channel->accept(new ConnectedPayloadEvent(
                $client->encryptEnvelope($this->encodeFrames([new PacketFrame(
                    new PacketHeader(PacketIds::PLAYER_AUTH_INPUT),
                    $payload,
                )])),
                Reliability::ReliableOrdered,
                0,
            )));
        };

        $sendLiteral(self::BLOCK_ACTION_INPUT);
        self::assertSame([], $channel->drainOutgoing());
        $startCommands = $channel->drainCommands();
        self::assertCount(2, $startCommands);
        self::assertInstanceOf(MovePlayer::class, $startCommands[0]);
        self::assertInstanceOf(BreakBlock::class, $startCommands[1]);
        self::assertSame(BlockBreakAction::Start, $startCommands[1]->action);
        self::assertSame([1, 63, -2], [
            $startCommands[1]->position?->x,
            $startCommands[1]->position?->y,
            $startCommands[1]->position?->z,
        ]);

        foreach ([
            [PlayerActionType::AbortDestroyBlock, BlockBreakAction::Abort, 8],
            [PlayerActionType::StartDestroyBlock, BlockBreakAction::Start, 9],
            [PlayerActionType::ContinueDestroyBlock, BlockBreakAction::Start, 10],
            [PlayerActionType::PredictDestroyBlock, BlockBreakAction::Complete, 11],
        ] as [$actionType, $intent, $tick]) {
            $target = new PlayerBlockAction(
                $actionType,
                new BlockPosition(1, 63, -2),
                $actionType === PlayerActionType::AbortDestroyBlock ? -1 : 1,
            );
            $packet = $actionType === PlayerActionType::PredictDestroyBlock
                ? $this->mineBlockActionPacket($target, $tick)
                : $this->blockActionPacket($target, $tick);
            self::assertTrue($channel->accept(new ConnectedPayloadEvent(
                $client->encryptEnvelope($this->encode([$packet])),
                Reliability::ReliableOrdered,
                0,
            )));
            self::assertSame([], $channel->drainOutgoing());
            $commands = $channel->drainCommands();
            self::assertCount($actionType === PlayerActionType::PredictDestroyBlock ? 3 : 2, $commands);
            self::assertInstanceOf(BreakBlock::class, $commands[1]);
            self::assertSame($intent, $commands[1]->action);
            if ($actionType === PlayerActionType::PredictDestroyBlock) {
                self::assertInstanceOf(ApplyInventoryStackRequest::class, $commands[2]);
                self::assertSame(-13, $commands[2]->requestId);
                self::assertCount(1, $commands[2]->actions);
                self::assertSame(InventoryStackRequestActionType::MineBlock, $commands[2]->actions[0]->type);
                self::assertSame(0, $commands[2]->actions[0]->source->slot);
            }
            if ($intent === BlockBreakAction::Abort) {
                self::assertNull($commands[1]->position);
                self::assertSame(0, $commands[1]->face);
            }
        }

        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new AnimatePacket(AnimatePacket::SWING, $entityId, 0.0, 'mine')])),
            Reliability::ReliableOrdered,
            0,
        )));
        self::assertSame([], $channel->drainOutgoing());
        self::assertSame([], $channel->drainCommands(), 'A swing must not create block-break state.');

        foreach ([
            new PlayerBlockAction(PlayerActionType::StartDestroyBlock, new BlockPosition(100, 63, 100), 1),
            new PlayerBlockAction(PlayerActionType::StartDestroyBlock, new BlockPosition(1, 63, -2), 6),
        ] as $invalidAction) {
            self::assertTrue($channel->accept(new ConnectedPayloadEvent(
                $client->encryptEnvelope($this->encode([$this->blockActionPacket($invalidAction, 11 + $invalidAction->face)])),
                Reliability::ReliableOrdered,
                0,
            )));
            self::assertSame([], $channel->drainOutgoing());
            $commands = $channel->drainCommands();
            self::assertCount(1, $commands);
            self::assertInstanceOf(MovePlayer::class, array_shift($commands));
        }

        $grassAfter = $world->chunk(new ChunkPosition(0, -1))->blockStateAt(1, 63, 14);
        self::assertSame($grassBefore->value, $grassAfter->value);
        self::assertFalse($channel->isClosed());
        $channel->close();
        $repository->clear();
    }

    public function testStandalonePlacementSelectsTheServerSlotBeforeAuthoritativePlacement(): void
    {
        $data = BedrockDataSet::bundled();
        $network = $data->blockStateRegistry();
        $internal = new BlockStateRegistry($network->states());
        $translator = new BlockNetworkTranslator($internal, $network);
        $palette = FixedFlatBlockPalette::fromRegistry($internal);
        $repository = new ChunkRepository(16);
        $world = new World(new WorldMetadata('placement-channel-test', 0), new FlatWorldGenerator($palette), $repository);
        [$channel, $client, $server, $entityId] = $this->channel(
            [new ChunkRadiusUpdatedPacket(1)],
            world: $world,
            serializer: new BedrockChunkPacketSerializer($translator),
            viewDistance: 1,
            spawnRadius: 1,
            fixedFlatRuntimeIds: $palette->toNetworkRuntimeIds($translator),
            inventoryProjector: BedrockInventoryPacketProjector::fromData($data, $translator),
        );
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new RequestChunkRadiusPacket(1, 1)])),
            Reliability::ReliableOrdered,
            0,
        )));
        foreach ($channel->drainOutgoing() as $outgoing) {
            $server->decryptEnvelope($outgoing->payload);
        }
        for ($tick = 0; $tick < 12; ++$tick) {
            self::assertTrue($channel->worldTick());
            foreach ($channel->drainOutgoing() as $outgoing) {
                $server->decryptEnvelope($outgoing->payload);
            }
        }
        $heldItem = new InventoryItemStack(
            $data->requiredItems()['minecraft:grass_block']['runtime_id'],
            64,
            0,
            1,
            $translator->toNetwork($palette->grassBlock),
            '',
        );
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new MobEquipmentPacket($entityId, 0, 0, 0, $heldItem)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $deferred = $channel->drainCommands();
        self::assertCount(0, $deferred);
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $selection = $channel->drainCommands();
        self::assertCount(1, $selection);
        $selected = array_shift($selection);
        self::assertInstanceOf(SelectHotbarSlot::class, $selected);
        self::assertSame(0, $selected->hotbarSlot);
        $target = new BlockPosition(1, 63, 0);
        $before = $world->blockStateAt(1, 64, 0);
        $clientPrediction = new InventoryItemStack(32_767, 63, 17, null, 0, "bounded-client-hint");
        $invalidTarget = new InventoryTransactionPacket(0, [], [], new ItemUseInventoryTransaction(
            ItemUseActionType::Place,
            ItemUseTriggerType::PlayerInput,
            $target,
            255,
            8,
            HandSlot::Mainhand,
            $clientPrediction,
            new InventoryVector3(0.0, 64.0, 0.0),
            new InventoryVector3(0.5, 1.0, 0.5),
            0,
            ItemUsePredictedResult::Success,
            ItemUseClientCooldownState::Off,
        ));
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([$invalidTarget])),
            Reliability::ReliableOrdered,
            0,
        )));
        $invalidCommands = $channel->drainCommands();
        self::assertCount(1, $invalidCommands);
        self::assertInstanceOf(SelectHotbarSlot::class, $invalidCommands[0]);
        self::assertSame(8, $invalidCommands[0]->hotbarSlot);
        self::assertSame($before->value, $world->blockStateAt(1, 64, 0)->value);

        $packet = new InventoryTransactionPacket(0, [], [], new ItemUseInventoryTransaction(
            ItemUseActionType::Place,
            ItemUseTriggerType::PlayerInput,
            $target,
            1,
            0,
            HandSlot::Mainhand,
            $clientPrediction,
            new InventoryVector3(0.0, 64.0, 0.0),
            new InventoryVector3(0.5, 1.0, 0.5),
            0,
            ItemUsePredictedResult::Success,
            ItemUseClientCooldownState::Off,
        ));
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([$packet])),
            Reliability::ReliableOrdered,
            0,
        )));

        self::assertSame([], $channel->drainOutgoing());
        $commands = $channel->drainCommands();
        if (count($commands) !== 2) {
            self::fail('Placement input did not produce ordered selection and placement commands.');
        }
        $selection = $commands[0];
        self::assertInstanceOf(SelectHotbarSlot::class, $selection);
        self::assertSame(0, $selection->hotbarSlot);
        $command = $commands[1];
        if (!$command instanceof PlaceBlock) {
            self::fail('Placement input did not produce the expected authoritative command.');
        }
        self::assertSame(0, $command->hotbarSlot);
        self::assertSame($before->value, $world->blockStateAt(1, 64, 0)->value);

        $simulation = new WorldSimulation(blockWorld: $world, blockPalette: $palette);
        $factory = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($factory->join('session', 'identity', 'Player')));
        $simulation->tick();
        self::assertTrue($simulation->enqueue($selection));
        self::assertTrue($simulation->enqueue($command));
        $events = $simulation->tick()->events;
        self::assertCount(2, $events);
        self::assertInstanceOf(HeldItemChanged::class, $events[0]);
        self::assertInstanceOf(BlockPlaced::class, $events[1]);
        self::assertSame(63, $events[1]->remainingStack?->count);
        self::assertSame($palette->grassBlock->value, $world->blockStateAt(1, 64, 0)->value);
        self::assertFalse($channel->isClosed());
        $channel->close();
        $repository->clear();
    }

    public function testEmbeddedRetailPlacementUsesServerInventoryDespiteEmptyClientPrediction(): void
    {
        $data = BedrockDataSet::bundled();
        $network = $data->blockStateRegistry();
        $internal = new BlockStateRegistry($network->states());
        $translator = new BlockNetworkTranslator($internal, $network);
        $palette = FixedFlatBlockPalette::fromRegistry($internal);
        $repository = new ChunkRepository(16);
        $world = new World(new WorldMetadata('embedded-placement-test', 0), new FlatWorldGenerator($palette), $repository);
        [$channel, $client, $server, $entityId] = $this->channel(
            [new ChunkRadiusUpdatedPacket(1)],
            world: $world,
            serializer: new BedrockChunkPacketSerializer($translator),
            viewDistance: 1,
            spawnRadius: 1,
            fixedFlatRuntimeIds: $palette->toNetworkRuntimeIds($translator),
            inventoryProjector: BedrockInventoryPacketProjector::fromData($data, $translator),
        );
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new RequestChunkRadiusPacket(1, 1)])),
            Reliability::ReliableOrdered,
            0,
        )));
        foreach ($channel->drainOutgoing() as $outgoing) {
            $server->decryptEnvelope($outgoing->payload);
        }
        for ($tick = 0; $tick < 12; ++$tick) {
            self::assertTrue($channel->worldTick());
            foreach ($channel->drainOutgoing() as $outgoing) {
                $server->decryptEnvelope($outgoing->payload);
            }
        }
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));

        $placementHex = str_replace('010000000201027e03', '010000000001027e03', self::ITEM_USE_INPUT, $replacements);
        self::assertSame(1, $replacements);
        $payload = hex2bin($placementHex);
        self::assertIsString($payload);
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encodeFrames([new PacketFrame(
                new PacketHeader(PacketIds::PLAYER_AUTH_INPUT),
                $payload,
            )])),
            Reliability::ReliableOrdered,
            0,
        )));
        $commands = $channel->drainCommands();
        self::assertCount(3, $commands);
        self::assertInstanceOf(MovePlayer::class, $commands[0]);
        self::assertInstanceOf(SelectHotbarSlot::class, $commands[1]);
        self::assertInstanceOf(PlaceBlock::class, $commands[2]);

        $simulation = new WorldSimulation(blockWorld: $world, blockPalette: $palette);
        $factory = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($factory->join('session', 'identity', 'Player')));
        $simulation->tick();
        self::assertTrue($simulation->enqueue($commands[1]));
        self::assertTrue($simulation->enqueue($commands[2]));
        $events = $simulation->tick()->events;
        self::assertCount(2, $events);
        self::assertInstanceOf(HeldItemChanged::class, $events[0]);
        self::assertInstanceOf(BlockPlaced::class, $events[1]);
        self::assertSame(63, $events[1]->remainingStack?->count);
        self::assertSame($palette->grassBlock->value, $world->blockStateAt(1, 64, -2)->value);
        self::assertFalse($channel->isClosed());
        $channel->close();
        $repository->clear();
    }

    public function testCloseIsIdempotentAndRejectsFurtherOutput(): void
    {
        [$channel] = $this->channel();
        $channel->close();
        $channel->close();
        self::assertFalse($channel->queuePacket(new ChunkRadiusUpdatedPacket(1)));
        self::assertSame([], $channel->drainOutgoing());
    }

    public function testCompressionSaturationAppliesBackpressureWithoutClosingChannel(): void
    {
        $workers = new NonCompletingCompressionWorkerDispatcher();
        $limits = new RuntimeLimits(
            maximumOutgoingPayloadsPerSession: 16,
            maximumOutgoingBytesPerSession: 4_000,
        );
        [$channel] = $this->channel(limits: $limits, compressionWorkers: $workers);
        $chunk = new LevelChunkPacket(0, 0, 0, 0, str_repeat('x', 5_000));

        self::assertFalse($channel->queuePacket($chunk));
        self::assertFalse($channel->isClosed());
        self::assertSame(0, $workers->submissionCount);
    }

    public function testInitializedDeathConversationAcceptsBothRetailRespawnInputs(): void
    {
        [$channel, $client, $server, $entityId] = $this->channel();
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([new SetLocalPlayerAsInitializedPacket($entityId)])),
            Reliability::ReliableOrdered,
            0,
        )));
        $channel->drainOutgoing();
        $searching = new RespawnPacket(0.0, 64.0, 0.0, RespawnState::ServerSearching, $entityId);
        $suppressedScreen = new DeathInfoPacket('', []);
        self::assertTrue($channel->queuePacket($searching));
        self::assertTrue($channel->queuePacket($suppressedScreen));
        $deathOutput = $channel->drainOutgoing();
        self::assertCount(2, $deathOutput);
        self::assertEquals($searching, $this->decode($server->decryptEnvelope($deathOutput[0]->payload)));
        self::assertEquals($suppressedScreen, $this->decode($server->decryptEnvelope($deathOutput[1]->payload)));
        self::assertTrue($channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($this->encode([
                new RespawnPacket(100.0, -20.0, 100.0, RespawnState::ClientReady, UnsignedLong::fromInt(0)),
                new PlayerActionPacket(
                    $entityId,
                    PlayerActionType::Respawn,
                    new BlockPosition(0, 0, 0),
                    new BlockPosition(0, 0, 0),
                    0,
                ),
            ])),
            Reliability::ReliableOrdered,
            0,
        )));

        $commands = $channel->drainCommands();
        self::assertCount(2, $commands);
        self::assertInstanceOf(AcknowledgeRespawn::class, $commands[0]);
        self::assertInstanceOf(RespawnPlayer::class, $commands[1]);
        $response = new RespawnPacket(0.0, 65.621, 0.0, RespawnState::ServerReady, $entityId);
        self::assertTrue($channel->queuePacket($response));
        $outgoing = $channel->drainOutgoing();
        self::assertCount(1, $outgoing);
        $frame = $this->decodeFrame($server->decryptEnvelope($outgoing[0]->payload));
        self::assertSame(PacketIds::RESPAWN, $frame->header->packetId);
        self::assertSame($response->encode(), BedrockPacketCodec::decode(
            $frame->header->packetId,
            $frame->payload,
        )->encode());
        self::assertFalse($channel->isClosed());
    }

    /**
     * @param list<Packet> $initialization
     * @param null|array{air: int, bedrock: int, dirt: int, grass_block: int} $fixedFlatRuntimeIds
     * @return array{BedrockPlayChannel, BedrockEncryptor, BedrockDecryptor, UnsignedLong}
     */
    private function channel(
        array $initialization = [],
        ?RuntimeDiagnostics $diagnostics = null,
        ?RuntimeLimits $limits = null,
        ?World $world = null,
        ?BedrockChunkPacketSerializer $serializer = null,
        int $generatePerTick = 4,
        int $sendPerTick = 4,
        int $viewDistance = 4,
        int $spawnRadius = 4,
        ?array $fixedFlatRuntimeIds = null,
        ?BedrockInventoryPacketProjector $inventoryProjector = null,
        float $spawnX = 0.0,
        float $spawnY = 64.0,
        float $spawnZ = 0.0,
        ?CompressionWorkerDispatcher $compressionWorkers = null,
        int $prefetchRadius = 0,
        ?PreparedChunkCache $preparedChunks = null,
    ): array {
        $key = str_repeat("\x42", 32);
        $keys = (new OpenSslEphemeralKeyFactory(dirname(__DIR__) . '/Fixtures/openssl.cnf'))->generate();
        $data = new VerifiedClientData(1, 1, "\0\0\0\0", 0, 0, '', '{}', []);
        $ready = new LoginChannelReady(
            new AuthenticatedLogin('RealName', '00000000-0000-0000-0000-000000000001', 'real-xuid', $keys->publicKey, $data),
            new BedrockEncryptor($key),
            new BedrockDecryptor($key),
            2193,
        );
        $entityId = UnsignedLong::fromInt(7);

        return [
            new BedrockPlayChannel(
                $ready,
                'session',
                $entityId,
                $initialization,
                fixedFlatRuntimeIds: $fixedFlatRuntimeIds
                    ?? ['air' => 1, 'bedrock' => 2, 'dirt' => 3, 'grass_block' => 4],
                limits: $limits ?? new RuntimeLimits(),
                diagnostics: $diagnostics,
                flatWorld: $world,
                chunkSerializer: $serializer,
                viewDistance: $world === null ? 1 : $viewDistance,
                spawnRadius: $world === null ? 1 : $spawnRadius,
                chunksGeneratePerTick: $world === null ? 1 : $generatePerTick,
                chunksSendPerTick: $world === null ? 1 : $sendPerTick,
                chunkPrefetchRadius: $world === null ? 0 : $prefetchRadius,
                spawnX: $spawnX,
                spawnY: $spawnY,
                spawnZ: $spawnZ,
                inventoryProjector: $inventoryProjector,
                compressionWorkers: $compressionWorkers,
                compressionTaskTypeId: $compressionWorkers === null ? 0 : 3,
                preparedChunks: $preparedChunks,
            ),
            new BedrockEncryptor($key),
            new BedrockDecryptor($key),
            $entityId,
        ];
    }

    private function movementPacket(
        UnsignedLong $tick,
        float $yaw = 0.0,
        float $pitch = 0.0,
        float $x = 0.0,
        float $y = 64.0,
        float $z = 0.0,
    ): PlayerAuthInputPacket {
        return new PlayerAuthInputPacket(
            pitch: $pitch,
            yaw: $yaw,
            wireX: $x,
            wireY: PlayerPositionProjection::feetToWireY($y),
            wireZ: $z,
            moveX: 0.0,
            moveZ: 0.0,
            headYaw: $yaw,
            inputFlags: [],
            inputMode: 1,
            playMode: 0,
            interactionMode: 0,
            interactPitch: 0.0,
            interactYaw: 0.0,
            tick: $tick,
            deltaX: 0.0,
            deltaY: 0.0,
            deltaZ: 0.0,
            analogMoveX: 0.0,
            analogMoveZ: 0.0,
            cameraX: 0.0,
            cameraY: 0.0,
            cameraZ: 0.0,
            rawMoveX: 0.0,
            rawMoveZ: 0.0,
        );
    }

    private function movementPredictionSync(
        UnsignedLong $runtimeActorId,
        bool $flying,
    ): MovementPredictionSyncPacket {
        return new MovementPredictionSyncPacket(
            [1, 3, 14, 19, 35, 47, 48, 49],
            0.6,
            1.8,
            0.6,
            0.1,
            0.02,
            0.02,
            0.42,
            1.0,
            1.0,
            123.0,
            456.0,
            789.0,
            $runtimeActorId,
            $flying,
        );
    }

    private function flightInput(UnsignedLong $tick): PlayerAuthInputPacket
    {
        return new PlayerAuthInputPacket(
            pitch: 0.0,
            yaw: 0.0,
            wireX: 0.0,
            wireY: PlayerPositionProjection::feetToWireY(64.42),
            wireZ: 0.0,
            moveX: 0.0,
            moveZ: 0.0,
            headYaw: 0.0,
            inputFlags: [PlayerAuthInputFlag::StartFlying->value, PlayerAuthInputFlag::StartJumping->value],
            inputMode: 1,
            playMode: 0,
            interactionMode: 0,
            interactPitch: 0.0,
            interactYaw: 0.0,
            tick: $tick,
            deltaX: 0.0,
            deltaY: 0.34,
            deltaZ: 0.0,
            analogMoveX: 0.0,
            analogMoveZ: 0.0,
            cameraX: 0.0,
            cameraY: 0.0,
            cameraZ: 0.0,
            rawMoveX: 0.0,
            rawMoveZ: 0.0,
        );
    }

    private function heldJumpInput(UnsignedLong $tick): PlayerAuthInputPacket
    {
        return new PlayerAuthInputPacket(
            pitch: 0.0,
            yaw: 0.0,
            wireX: 0.0,
            wireY: PlayerPositionProjection::feetToWireY(64.42),
            wireZ: 0.0,
            moveX: 0.0,
            moveZ: 0.0,
            headYaw: 0.0,
            inputFlags: [PlayerAuthInputFlag::Jumping->value],
            inputMode: 1,
            playMode: 0,
            interactionMode: 0,
            interactPitch: 0.0,
            interactYaw: 0.0,
            tick: $tick,
            deltaX: 0.0,
            deltaY: 0.34,
            deltaZ: 0.0,
            analogMoveX: 0.0,
            analogMoveZ: 0.0,
            cameraX: 0.0,
            cameraY: 0.0,
            cameraZ: 0.0,
            rawMoveX: 0.0,
            rawMoveZ: 0.0,
        );
    }

    private function blockActionPacket(PlayerBlockAction $action, int $tick = 99): PlayerAuthInputPacket
    {
        return new PlayerAuthInputPacket(
            0.0,
            0.0,
            0.0,
            PlayerPositionProjection::feetToWireY(64.0),
            0.0,
            0.0,
            0.0,
            0.0,
            [PlayerAuthInputFlag::PerformBlockActions->value],
            1,
            0,
            0,
            0.0,
            0.0,
            UnsignedLong::fromInt($tick),
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            true,
            blockActions: [$action],
        );
    }

    private function mineBlockActionPacket(PlayerBlockAction $action, int $tick): PlayerAuthInputPacket
    {
        return new PlayerAuthInputPacket(
            0.0,
            0.0,
            0.0,
            PlayerPositionProjection::feetToWireY(64.0),
            0.0,
            0.0,
            0.0,
            0.0,
            [
                PlayerAuthInputFlag::PerformItemStackRequest->value,
                PlayerAuthInputFlag::PerformBlockActions->value,
            ],
            1,
            0,
            0,
            0.0,
            0.0,
            UnsignedLong::fromInt($tick),
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            true,
            -13,
            [$action],
            itemStackRequest: new ItemStackRequest(-13, [new MineBlockItemStackRequestAction(0, 1, 1)]),
        );
    }

    private function inventoryTransactionFrame(?string $payload = null): PacketFrame
    {
        if ($payload === null) {
            $payload = hex2bin(self::INVENTORY_TRANSACTION_ITEM_USE);
            self::assertIsString($payload);
        }

        return new PacketFrame(new PacketHeader(self::INVENTORY_TRANSACTION_PACKET_ID), $payload);
    }

    /** @param list<Packet> $packets */
    private function encode(array $packets): string
    {
        $frames = array_map(
            static fn(Packet $packet): PacketFrame => new PacketFrame(
                new PacketHeader(BedrockPacketCodec::packetId($packet)),
                BedrockPacketCodec::encode($packet),
            ),
            $packets,
        );

        return $this->encodeFrames($frames);
    }

    /** @param list<PacketFrame> $frames */
    private function encodeFrames(array $frames): string
    {
        return BedrockBatchCodec::encode(new BedrockBatch($frames, CompressionMode::NegotiatedZlib, 256), new BatchLimits());
    }

    private function decode(string $payload): Packet
    {
        $batch = BedrockBatchCodec::decode($payload, CompressionMode::NegotiatedZlib, new BatchLimits(), 256);

        return BedrockPacketCodec::decode($batch->packets[0]->header->packetId, $batch->packets[0]->payload);
    }

    private function decodeFrame(string $payload): PacketFrame
    {
        $batch = BedrockBatchCodec::decode($payload, CompressionMode::NegotiatedZlib, new BatchLimits(), 256);
        if (count($batch->packets) !== 1) {
            self::fail('Expected exactly one decoded packet frame.');
        }

        return $batch->packets[0];
    }

    private function packetId(string $payload): int
    {
        $batch = BedrockBatchCodec::decode($payload, CompressionMode::NegotiatedZlib, new BatchLimits(), 256);

        return $batch->packets[0]->header->packetId;
    }
}

final class NonCompletingCompressionWorkerDispatcher implements CompressionWorkerDispatcher
{
    public int $submissionCount = 0;

    public function workerCount(): int
    {
        return 1;
    }

    public function submit(
        int $taskTypeId,
        string $payload,
        Closure $completion,
        ?int $deadlineNanoseconds = null,
    ): WorkerSubmission {
        ++$this->submissionCount;

        return WorkerSubmission::accepted(new WorkerReceipt(
            'test',
            $this->submissionCount,
            $taskTypeId,
            'network',
            $deadlineNanoseconds ?? PHP_INT_MAX,
        ));
    }

    public function cancel(WorkerReceipt $receipt): bool
    {
        return true;
    }
}

final class ImmediatePreparationWorkerDispatcher implements WorkerDispatcher
{
    public int $submissions = 0;
    /** @var array<int, array{WorkerReceipt, string, Closure(WorkerResult): void}> */
    private array $pending = [];

    public function submit(
        int $taskTypeId,
        string $payload,
        Closure $completion,
        ?int $deadlineNanoseconds = null,
    ): WorkerSubmission {
        $taskId = ++$this->submissions;
        $receipt = new WorkerReceipt('prepared-test', $taskId, $taskTypeId, 'chunk-preparation', PHP_INT_MAX);
        $this->pending[$taskId] = [$receipt, $payload, $completion];

        return WorkerSubmission::accepted($receipt);
    }

    public function completeAll(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as [$receipt, $payload, $completion]) {
            $completion(new WorkerResult(
                $receipt,
                WorkerResultStatus::SUCCESS,
                (new PrepareChunkTask())->execute($payload),
            ));
        }
    }

    public function cancel(WorkerReceipt $receipt): bool
    {
        unset($this->pending[$receipt->taskId]);

        return true;
    }

    public function poll(int $maximumCompletions = 256): void {}

    public function snapshot(): WorkerPoolSnapshot
    {
        return new WorkerPoolSnapshot('', 1, 0, count($this->pending), 0, 0, $this->submissions, 0, 0, 0, 0, 0, 0, true);
    }

    public function shutdown(): void
    {
        $this->pending = [];
    }
}
