<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Batch\BatchLimits;
use Bedriox\Protocol\Batch\BedrockBatch;
use Bedriox\Protocol\Batch\BedrockBatchCodec;
use Bedriox\Protocol\Batch\CompressionMode;
use Bedriox\Protocol\Codec\ByteBufferReader;
use Bedriox\Protocol\Encryption\BedrockDecryptor;
use Bedriox\Protocol\Encryption\BedrockEncryptor;
use Bedriox\Protocol\Identity\VerifiedClientData;
use Bedriox\Protocol\Packet\AuthenticationType;
use Bedriox\Protocol\Packet\AvailableCommandsPacket;
use Bedriox\Protocol\Packet\BedrockPacketCodec;
use Bedriox\Protocol\Packet\ChatPacket;
use Bedriox\Protocol\Packet\ChunkRadiusUpdatedPacket;
use Bedriox\Protocol\Packet\ClientToServerHandshakePacket;
use Bedriox\Protocol\Packet\CommandOrigin;
use Bedriox\Protocol\Packet\CommandOriginType;
use Bedriox\Protocol\Packet\CommandOutputPacket;
use Bedriox\Protocol\Packet\CommandPermissionLevel;
use Bedriox\Protocol\Packet\CommandRequestPacket;
use Bedriox\Protocol\Packet\FullContainerName;
use Bedriox\Protocol\Packet\ItemStackRequest;
use Bedriox\Protocol\Packet\ItemStackRequestPacket;
use Bedriox\Protocol\Packet\ItemStackRequestSlot;
use Bedriox\Protocol\Packet\LoginAuthentication;
use Bedriox\Protocol\Packet\LoginPacket;
use Bedriox\Protocol\Packet\MobEquipmentPacket;
use Bedriox\Protocol\Packet\Packet;
use Bedriox\Protocol\Packet\PacketFrame;
use Bedriox\Protocol\Packet\PacketHeader;
use Bedriox\Protocol\Packet\PacketIds;
use Bedriox\Protocol\Packet\PlayerAbilities;
use Bedriox\Protocol\Packet\PlayerAuthInputPacket;
use Bedriox\Protocol\Packet\PlayerPermission;
use Bedriox\Protocol\Packet\PlayerPositionProjection;
use Bedriox\Protocol\Packet\RequestChunkRadiusPacket;
use Bedriox\Protocol\Packet\RequestNetworkSettingsPacket;
use Bedriox\Protocol\Packet\ResourcePackClientResponsePacket;
use Bedriox\Protocol\Packet\ResourcePackResponseStatus;
use Bedriox\Protocol\Packet\ServerToClientHandshakePacket;
use Bedriox\Protocol\Packet\SetLocalPlayerAsInitializedPacket;
use Bedriox\Protocol\Packet\SubChunkRequestPacket;
use Bedriox\Protocol\Packet\SwapItemStackRequestAction;
use Bedriox\Protocol\Packet\UpdateAbilitiesPacket;
use Bedriox\Protocol\Packet\VoxelShapesPacket;
use Bedriox\Protocol\ProtocolVersion;
use Bedriox\Protocol\Security\HandshakeJwt;
use Bedriox\Protocol\Security\OpenSslEphemeralKeyFactory;
use Bedriox\Protocol\Security\P384;
use Bedriox\Protocol\Security\P384KeyPair;
use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\RakNet\Protocol\Reliability;
use Bedriox\RakNet\ReceivedPayload;
use Bedriox\RakNet\SessionClosedEvent;
use Bedriox\RakNet\SessionCloseReason;
use Bedriox\RakNet\SessionInfo;
use Bedriox\RakNet\SessionOpenedEvent;
use Bedriox\Server\Login\AuthenticatedLogin;
use Bedriox\Server\Login\BedrockLoginChannel;
use Bedriox\Server\Login\HandshakeMaterial;
use Bedriox\Server\Login\HandshakeMaterialFactory;
use Bedriox\Server\Login\LoginAuthenticator;
use Bedriox\Server\Login\LoginSession;
use Bedriox\Server\Login\MonotonicClock;
use Bedriox\Server\Observability\MutableCrashContextProvider;
use Bedriox\Server\Permission\PermissionStore;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Plugin\Command\CommandRegistry;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\Runtime\BedrockPlayChannelFactory;
use Bedriox\Server\Runtime\DirectedPacket;
use Bedriox\Server\Runtime\LoginChannelFactory;
use Bedriox\Server\Runtime\PlayInitializationFactory;
use Bedriox\Server\Runtime\RuntimeDiagnostics;
use Bedriox\Server\Runtime\RuntimeLimits;
use Bedriox\Server\Runtime\RuntimeSession;
use Bedriox\Server\Runtime\ServerRuntime;
use Bedriox\Server\Runtime\WorldEventPacketEncoder;
use Bedriox\Server\Simulation\Event\ChatBroadcast;
use Bedriox\Server\Simulation\Event\HeldItemChanged;
use Bedriox\Server\Simulation\Event\InventoryStackRequestProcessed;
use Bedriox\Server\Simulation\Event\PlayerJoined;
use Bedriox\Server\Simulation\Event\PlayerMoved;
use Bedriox\Server\Simulation\Event\WorldEvent;
use Bedriox\Server\Simulation\FixedRateWorldLoop;
use Bedriox\Server\Simulation\PlayerSnapshot;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationClock;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\Tests\World\InMemoryWorldProvider;
use Bedriox\Server\Transport\ConnectedTransport;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;
use Throwable;

final class ServerRuntimeTest extends TestCase
{
    public function testFullLoginTransfersCipherThenJoinsOnlyAfterInitializationAck(): void
    {
        $transport = new FakeConnectedTransport();
        $clock = new RuntimeTestClock();
        $world = new WorldSimulation();
        $loginFactory = new RuntimeLoginFactory();
        $events = new RecordingEventEncoder();
        $crashContext = new MutableCrashContextProvider();
        $runtime = new ServerRuntime(
            $transport,
            $loginFactory,
            new BedrockPlayChannelFactory(new EmptyInitializationFactory()),
            $world,
            new FixedRateWorldLoop($world, $clock),
            $events,
            crashContext: $crashContext,
        );
        $info = new SessionInfo('127.0.0.1', 20_001, 42, 1_400, 11);
        $clientEncryptor = $this->advanceToInitializing($runtime, $transport, $info, $loginFactory);
        self::assertSame([], $world->snapshot()->players);

        $this->receiveEncrypted($transport, $info, $clientEncryptor, new SetLocalPlayerAsInitializedPacket(UnsignedLong::fromInt(1)));
        self::assertTrue($runtime->poll());
        self::assertSame([], $world->snapshot()->players, 'Join remains queued until the authoritative tick.');
        $clock->advance(50_000_000);
        self::assertTrue($runtime->poll());
        self::assertCount(1, $world->snapshot()->players);
        self::assertSame([PlayerJoined::class], $events->classes);
        self::assertSame(1, $crashContext->current()->tick);
        self::assertCount(1, $crashContext->current()->players);
        self::assertSame('Player', $crashContext->current()->players[0]->name);
        self::assertSame('00000000-0000-0000-0000-000000000001', $crashContext->current()->players[0]->uuid);
        self::assertSame('1', $crashContext->current()->players[0]->xuid);
        self::assertSame('127.0.0.1:20001', $crashContext->current()->players[0]->remoteAddress);
        self::assertSame('SPAWNED', $crashContext->current()->players[0]->sessionPhase);

        // Regression: consuming the one-shot spawn acknowledgement must not revoke gameplay admission.
        $this->receiveEncrypted($transport, $info, $clientEncryptor, new PlayerAuthInputPacket(
            10.0,
            90.0,
            2.0,
            PlayerPositionProjection::feetToWireY(64.0),
            3.0,
            1.0,
            0.0,
            90.0,
            [],
            1,
            0,
            0,
            0.0,
            0.0,
            UnsignedLong::fromInt(7),
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
        ));
        self::assertTrue($runtime->poll());
        self::assertSame(1, $runtime->sessionCount());
        $clock->advance(50_000_000);
        self::assertTrue($runtime->poll());
        $movedPlayers = $world->snapshot()->players;
        self::assertCount(1, $movedPlayers);
        $movedPlayer = array_shift($movedPlayers);
        self::assertInstanceOf(PlayerSnapshot::class, $movedPlayer);
        self::assertSame(1, $movedPlayer->movementSequence);
        $movedPosition = $movedPlayer->position;
        self::assertInstanceOf(Position::class, $movedPosition);
        self::assertEqualsWithDelta(64.0, $movedPosition->y, 0.000_01);

        $transport->events[] = new SessionClosedEvent($info, SessionCloseReason::RemoteDisconnect);
        self::assertTrue($runtime->poll());
        self::assertCount(1, $world->snapshot()->players);
        $clock->advance(50_000_000);
        self::assertTrue($runtime->poll());
        self::assertSame([], $world->snapshot()->players);
    }

    public function testRetailEquipmentEchoBeforeInitializationIsAppliedAfterAdmission(): void
    {
        $transport = new FakeConnectedTransport();
        $clock = new RuntimeTestClock();
        $world = new WorldSimulation();
        $loginFactory = new RuntimeLoginFactory();
        $events = new RecordingEventEncoder();
        $runtime = new ServerRuntime(
            $transport,
            $loginFactory,
            new BedrockPlayChannelFactory(new EmptyInitializationFactory([
                new MobEquipmentPacket(UnsignedLong::fromInt(1)),
            ])),
            $world,
            new FixedRateWorldLoop($world, $clock),
            $events,
        );
        $info = new SessionInfo('127.0.0.1', 20_001, 42, 1_400, 11);
        $client = $this->advanceToInitializing($runtime, $transport, $info, $loginFactory);

        $this->receiveEncrypted($transport, $info, $client, new MobEquipmentPacket(
            UnsignedLong::fromInt(1),
            4,
            4,
            0,
        ));
        self::assertTrue($runtime->poll());
        self::assertSame(1, $runtime->sessionCount());
        self::assertSame([], $world->snapshot()->players);

        $this->receiveEncrypted(
            $transport,
            $info,
            $client,
            new SetLocalPlayerAsInitializedPacket(UnsignedLong::fromInt(1)),
        );
        self::assertTrue($runtime->poll());
        self::assertSame(1, $runtime->sessionCount());
        $clock->advance(50_000_000);
        self::assertTrue($runtime->poll());
        self::assertCount(1, $world->snapshot()->players);
        self::assertSame([PlayerJoined::class, HeldItemChanged::class], $events->classes);
    }

    public function testAckAdmitsMovementAndChatBeforeBootstrapAndTerrainQueuesDrain(): void
    {
        $transport = new FakeConnectedTransport();
        $clock = new RuntimeTestClock();
        $world = new WorldSimulation();
        $loginFactory = new RuntimeLoginFactory();
        $limits = new RuntimeLimits(maximumStreamingPacketsPerPoll: 1);
        $events = new RecordingEventEncoder();
        $runtime = new ServerRuntime(
            $transport,
            $loginFactory,
            new BedrockPlayChannelFactory(new EmptyInitializationFactory([
                new VoxelShapesPacket(),
                new ChunkRadiusUpdatedPacket(1),
                new VoxelShapesPacket(),
                new VoxelShapesPacket(),
                new VoxelShapesPacket(),
            ]), limits: $limits),
            $world,
            new FixedRateWorldLoop($world, $clock),
            $events,
            $limits,
        );
        $info = new SessionInfo('127.0.0.1', 20_011, 52, 1_400, 21);
        $client = $this->advanceToInitializing($runtime, $transport, $info, $loginFactory);
        $this->receiveEncrypted($transport, $info, $client, new RequestChunkRadiusPacket(1, 1));
        $this->receiveEncrypted($transport, $info, $client, new SubChunkRequestPacket(
            0,
            0,
            3,
            0,
            [['x' => 1, 'y' => 0, 'z' => 0]],
        ));
        $this->receiveEncrypted(
            $transport,
            $info,
            $client,
            new SetLocalPlayerAsInitializedPacket(UnsignedLong::fromInt(1)),
        );
        $this->receive(
            $transport,
            $info,
            $client->encryptEnvelope($this->encode([
                $this->stationaryMovementPacket(UnsignedLong::fromInt(1)),
                new SubChunkRequestPacket(0, 0, 3, 0, [['x' => -1, 'y' => 0, 'z' => 0]]),
            ], CompressionMode::NegotiatedZlib)),
        );
        $transport->sent = [];
        self::assertTrue($runtime->poll());
        self::assertSame([], $world->snapshot()->players);
        self::assertSame(1, $runtime->sessionCount());
        self::assertCount(2, $transport->sent, 'Radius confirmation and one bounded stream packet should be sent.');

        $this->receiveEncrypted($transport, $info, $client, new ChatPacket('spoofed', 'hello while streaming'));
        self::assertTrue($runtime->poll());
        self::assertSame(1, $runtime->sessionCount());
        self::assertCount(3, $transport->sent, 'Streaming should continue after admission.');

        $clock->advance(50_000_000);
        self::assertTrue($runtime->poll());
        self::assertCount(1, $world->snapshot()->players);
        self::assertSame(1, $runtime->sessionCount());
        self::assertSame([PlayerJoined::class, ChatBroadcast::class, PlayerMoved::class], $events->classes);
    }

    public function testInvalidAuthenticatedGameplayNameClosesOnlyThatPeerAtInitializationAck(): void
    {
        $transport = new FakeConnectedTransport();
        $clock = new RuntimeTestClock();
        $world = new WorldSimulation();
        $loginFactory = new RuntimeLoginFactory(displayName: 'SeventeenCharsHere');
        $runtime = new ServerRuntime(
            $transport,
            $loginFactory,
            new BedrockPlayChannelFactory(new EmptyInitializationFactory()),
            $world,
            new FixedRateWorldLoop($world, $clock),
            new RecordingEventEncoder(),
        );
        $info = new SessionInfo('127.0.0.1', 20_001, 42, 1_400, 11);
        $clientEncryptor = $this->advanceToInitializing($runtime, $transport, $info, $loginFactory);
        $this->receiveEncrypted($transport, $info, $clientEncryptor, new SetLocalPlayerAsInitializedPacket(UnsignedLong::fromInt(1)));

        self::assertTrue($runtime->poll());
        self::assertFalse($runtime->isClosed());
        self::assertSame(0, $runtime->sessionCount());
        self::assertSame([], $world->snapshot()->players);
    }

    public function testDuplicateIdentityAdmissionRejectsOnlyTheSecondSession(): void
    {
        $transport = new FakeConnectedTransport();
        $clock = new RuntimeTestClock();
        $world = new WorldSimulation();
        $loginFactory = new RuntimeLoginFactory();
        $runtime = new ServerRuntime(
            $transport,
            $loginFactory,
            new BedrockPlayChannelFactory(new EmptyInitializationFactory()),
            $world,
            new FixedRateWorldLoop($world, $clock),
            new RecordingEventEncoder(),
        );
        $first = new SessionInfo('127.0.0.1', 20_001, 41, 1_400, 11);
        $second = new SessionInfo('127.0.0.1', 20_002, 42, 1_400, 11);
        $firstEncryptor = $this->advanceToInitializing($runtime, $transport, $first, $loginFactory);
        $secondEncryptor = $this->advanceToInitializing($runtime, $transport, $second, $loginFactory);

        $this->receiveEncrypted($transport, $first, $firstEncryptor, new SetLocalPlayerAsInitializedPacket(UnsignedLong::fromInt(1)));
        self::assertTrue($runtime->poll());
        $this->receiveEncrypted($transport, $second, $secondEncryptor, new SetLocalPlayerAsInitializedPacket(UnsignedLong::fromInt(2)));
        self::assertTrue($runtime->poll());
        self::assertSame([], $world->snapshot()->players);

        $clock->advance(50_000_000);
        self::assertTrue($runtime->poll());
        self::assertSame(1, $runtime->sessionCount());
        self::assertCount(1, $world->snapshot()->players);
        self::assertContains(['127.0.0.1', 20_002], $transport->removed);
    }

    public function testEarlyEquipmentFromOnePeerDoesNotDisconnectEitherInitializingPeer(): void
    {
        $transport = new FakeConnectedTransport();
        $clock = new RuntimeTestClock();
        $world = new WorldSimulation();
        $loginFactory = new RuntimeLoginFactory(uniqueIdentities: true);
        $runtime = new ServerRuntime(
            $transport,
            $loginFactory,
            new BedrockPlayChannelFactory(new EmptyInitializationFactory()),
            $world,
            new FixedRateWorldLoop($world, $clock),
            new RecordingEventEncoder(),
        );
        $first = new SessionInfo('127.0.0.1', 20_001, 41, 1_400, 11);
        $second = new SessionInfo('127.0.0.1', 20_002, 42, 1_400, 11);
        $firstClient = $this->advanceToInitializing($runtime, $transport, $first, $loginFactory);
        $secondClient = $this->advanceToInitializing($runtime, $transport, $second, $loginFactory);

        $this->receiveEncrypted(
            $transport,
            $first,
            $firstClient,
            new MobEquipmentPacket(UnsignedLong::fromInt(1), 4, 4, 0),
        );
        self::assertTrue($runtime->poll());
        self::assertSame(2, $runtime->sessionCount());
        self::assertSame([], $transport->removed);
        self::assertSame([], $world->snapshot()->players);

        $this->receiveEncrypted(
            $transport,
            $first,
            $firstClient,
            new SetLocalPlayerAsInitializedPacket(UnsignedLong::fromInt(1)),
        );
        self::assertTrue($runtime->poll());
        $this->receiveEncrypted(
            $transport,
            $second,
            $secondClient,
            new SetLocalPlayerAsInitializedPacket(UnsignedLong::fromInt(2)),
        );
        self::assertTrue($runtime->poll());
        $clock->advance(50_000_000);
        self::assertTrue($runtime->poll());
        self::assertSame(2, $runtime->sessionCount());
        self::assertCount(2, $world->snapshot()->players);
        self::assertSame([], $transport->removed);
    }

    public function testInventoryRequestExcludesPeersWithoutActorVisibility(): void
    {
        $transport = new FakeConnectedTransport();
        $clock = new RuntimeTestClock();
        $world = new WorldSimulation();
        $loginFactory = new RuntimeLoginFactory(uniqueIdentities: true);
        $events = new RecordingEventEncoder();
        $runtime = new ServerRuntime(
            $transport,
            $loginFactory,
            new BedrockPlayChannelFactory(new EmptyInitializationFactory()),
            $world,
            new FixedRateWorldLoop($world, $clock),
            $events,
        );
        $first = new SessionInfo('127.0.0.1', 20_001, 41, 1_400, 11);
        $second = new SessionInfo('127.0.0.1', 20_002, 42, 1_400, 11);
        $firstClient = $this->advanceToInitializing($runtime, $transport, $first, $loginFactory);
        $secondClient = $this->advanceToInitializing($runtime, $transport, $second, $loginFactory);
        $this->receiveEncrypted($transport, $first, $firstClient, new SetLocalPlayerAsInitializedPacket(UnsignedLong::fromInt(1)));
        $this->receiveEncrypted($transport, $second, $secondClient, new SetLocalPlayerAsInitializedPacket(UnsignedLong::fromInt(2)));
        self::assertTrue($runtime->poll());
        $clock->advance(50_000_000);
        self::assertTrue($runtime->poll());
        self::assertCount(2, $world->snapshot()->players);

        $events->clearEvents();
        $emptyFirst = new ItemStackRequestSlot(new FullContainerName(FullContainerName::INVENTORY), 0, 0);
        $emptySecond = new ItemStackRequestSlot(new FullContainerName(FullContainerName::INVENTORY), 1, 0);
        $this->receiveEncrypted($transport, $first, $firstClient, new ItemStackRequestPacket([
            new ItemStackRequest(-1, [new SwapItemStackRequestAction($emptyFirst, $emptySecond)]),
        ]));
        self::assertTrue($runtime->poll());
        $clock->advance(50_000_000);
        self::assertTrue($runtime->poll());

        $processed = array_values(array_filter(
            $events->events,
            static fn(WorldEvent $event): bool => $event instanceof InventoryStackRequestProcessed,
        ));
        self::assertCount(1, $processed);
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $processed[0]);
        self::assertTrue($processed[0]->success);
        self::assertSame([], $processed[0]->peerSessionIds);
    }

    public function testFactoryFailureAndInvalidPeerInputAreIsolated(): void
    {
        $transport = new FakeConnectedTransport();
        $clock = new RuntimeTestClock();
        $world = new WorldSimulation();
        $runtime = new ServerRuntime(
            $transport,
            new RuntimeLoginFactory(failPort: 20_001),
            new BedrockPlayChannelFactory(new EmptyInitializationFactory()),
            $world,
            new FixedRateWorldLoop($world, $clock),
            new RecordingEventEncoder(),
        );
        $bad = new SessionInfo('127.0.0.1', 20_001, 1, 1_400, 11);
        $good = new SessionInfo('127.0.0.1', 20_002, 2, 1_400, 11);
        $transport->events = [new SessionOpenedEvent($bad), new SessionOpenedEvent($good)];
        self::assertTrue($runtime->poll());
        self::assertSame(1, $runtime->sessionCount());
        self::assertContains(['127.0.0.1', 20_001], $transport->removed);

        $transport->payloads[] = new ReceivedPayload('127.0.0.1', 20_002, 'bad', Reliability::Reliable, null);
        self::assertTrue($runtime->poll());
        self::assertSame(0, $runtime->sessionCount());
        self::assertFalse($runtime->isClosed());
    }

    public function testLoginRejectionRecordsBoundedStateAndFailureReason(): void
    {
        $transport = new FakeConnectedTransport();
        $lines = [];
        $diagnostics = new RuntimeDiagnostics(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $runtime = new ServerRuntime(
            $transport,
            new RuntimeLoginFactory(),
            new BedrockPlayChannelFactory(new EmptyInitializationFactory()),
            new WorldSimulation(),
            new FixedRateWorldLoop(new WorldSimulation(), new RuntimeTestClock()),
            new RecordingEventEncoder(),
            diagnostics: $diagnostics,
        );
        $info = new SessionInfo('127.0.0.1', 20_001, 42, 1_400, 11);
        $transport->events[] = new SessionOpenedEvent($info);
        self::assertTrue($runtime->poll());

        $this->receive($transport, $info, 'not-a-bedrock-batch');

        self::assertTrue($runtime->poll());
        self::assertStringContainsString('"event":"login.session_closed"', implode('', $lines));
        self::assertStringContainsString('"state_before":"wait_network_request"', implode('', $lines));
        self::assertStringContainsString('"failure":"input_limit"', implode('', $lines));
    }

    public function testMalformedPlayInputDisconnectsOnlyItsSession(): void
    {
        $transport = new FakeConnectedTransport();
        $clock = new RuntimeTestClock();
        $world = new WorldSimulation();
        $loginFactory = new RuntimeLoginFactory();
        $runtime = new ServerRuntime(
            $transport,
            $loginFactory,
            new BedrockPlayChannelFactory(new EmptyInitializationFactory()),
            $world,
            new FixedRateWorldLoop($world, $clock),
            new RecordingEventEncoder(),
        );
        $bad = new SessionInfo('127.0.0.1', 20_001, 41, 1_400, 11);
        $good = new SessionInfo('127.0.0.1', 20_002, 42, 1_400, 11);
        $badEncryptor = $this->advanceToInitializing($runtime, $transport, $bad, $loginFactory);
        $this->advanceToInitializing($runtime, $transport, $good, $loginFactory);

        $this->receive($transport, $bad, $badEncryptor->encryptEnvelope("\xfe" . 'malformed-batch'));

        self::assertTrue($runtime->poll());
        self::assertFalse($runtime->isClosed());
        self::assertSame(1, $runtime->sessionCount());
        self::assertContains(['127.0.0.1', 20_001], $transport->removed);
        self::assertNotContains(['127.0.0.1', 20_002], $transport->removed);
    }

    public function testEventEncodingFailureDisconnectsOnlyItsCausalSession(): void
    {
        $transport = new FakeConnectedTransport();
        $clock = new RuntimeTestClock();
        $world = new WorldSimulation();
        $loginFactory = new RuntimeLoginFactory(uniqueIdentities: true);
        $events = new RecordingEventEncoder(failOnClass: ChatBroadcast::class);
        $runtime = new ServerRuntime(
            $transport,
            $loginFactory,
            new BedrockPlayChannelFactory(new EmptyInitializationFactory()),
            $world,
            new FixedRateWorldLoop($world, $clock),
            $events,
        );
        $causal = new SessionInfo('127.0.0.1', 20_001, 41, 1_400, 11);
        $recipient = new SessionInfo('127.0.0.1', 20_002, 42, 1_400, 11);
        $causalEncryptor = $this->advanceToInitializing($runtime, $transport, $causal, $loginFactory);
        $recipientEncryptor = $this->advanceToInitializing($runtime, $transport, $recipient, $loginFactory);
        $this->receiveEncrypted($transport, $causal, $causalEncryptor, new SetLocalPlayerAsInitializedPacket(UnsignedLong::fromInt(1)));
        $this->receiveEncrypted($transport, $recipient, $recipientEncryptor, new SetLocalPlayerAsInitializedPacket(UnsignedLong::fromInt(2)));
        self::assertTrue($runtime->poll());
        $clock->advance(50_000_000);
        self::assertTrue($runtime->poll());
        self::assertCount(2, $world->snapshot()->players);

        $this->receiveEncrypted($transport, $causal, $causalEncryptor, new ChatPacket('spoofed', 'fan out'));
        self::assertTrue($runtime->poll());
        $clock->advance(50_000_000);

        self::assertTrue($runtime->poll());
        self::assertFalse($runtime->isClosed());
        self::assertSame(1, $runtime->sessionCount());
        self::assertContains(['127.0.0.1', 20_001], $transport->removed);
        self::assertNotContains(['127.0.0.1', 20_002], $transport->removed);
    }

    public function testDirectedPacketBudgetFailureDisconnectsOnlyItsCausalSession(): void
    {
        $transport = new FakeConnectedTransport();
        $clock = new RuntimeTestClock();
        $world = new WorldSimulation();
        $loginFactory = new RuntimeLoginFactory(uniqueIdentities: true);
        $events = new RecordingEventEncoder(packetCountOnClass: ChatBroadcast::class, packetCount: 2);
        $limits = new RuntimeLimits(maximumDirectedPacketsPerPoll: 1);
        $runtime = new ServerRuntime(
            $transport,
            $loginFactory,
            new BedrockPlayChannelFactory(new EmptyInitializationFactory(), limits: $limits),
            $world,
            new FixedRateWorldLoop($world, $clock),
            $events,
            $limits,
        );
        $causal = new SessionInfo('127.0.0.1', 20_001, 41, 1_400, 11);
        $recipient = new SessionInfo('127.0.0.1', 20_002, 42, 1_400, 11);
        $causalEncryptor = $this->advanceToInitializing($runtime, $transport, $causal, $loginFactory);
        $recipientEncryptor = $this->advanceToInitializing($runtime, $transport, $recipient, $loginFactory);
        $this->receiveEncrypted($transport, $causal, $causalEncryptor, new SetLocalPlayerAsInitializedPacket(UnsignedLong::fromInt(1)));
        $this->receiveEncrypted($transport, $recipient, $recipientEncryptor, new SetLocalPlayerAsInitializedPacket(UnsignedLong::fromInt(2)));
        self::assertTrue($runtime->poll());
        $clock->advance(50_000_000);
        self::assertTrue($runtime->poll());
        self::assertCount(2, $world->snapshot()->players);

        $this->receiveEncrypted($transport, $causal, $causalEncryptor, new ChatPacket('spoofed', 'bounded fan out'));
        self::assertTrue($runtime->poll());
        $clock->advance(50_000_000);

        self::assertTrue($runtime->poll());
        self::assertFalse($runtime->isClosed());
        self::assertSame(1, $runtime->sessionCount());
        self::assertContains(['127.0.0.1', 20_001], $transport->removed);
        self::assertNotContains(['127.0.0.1', 20_002], $transport->removed);
    }

    public function testTopLevelPollFailureIsLoggedWithoutItsMessage(): void
    {
        $transport = new FakeConnectedTransport();
        $transport->throwOnPoll = true;
        $lines = [];
        $diagnostics = new RuntimeDiagnostics(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $world = new WorldSimulation();
        $runtime = new ServerRuntime(
            $transport,
            new RuntimeLoginFactory(),
            new BedrockPlayChannelFactory(new EmptyInitializationFactory()),
            $world,
            new FixedRateWorldLoop($world, new RuntimeTestClock()),
            new RecordingEventEncoder(),
            diagnostics: $diagnostics,
        );

        self::assertFalse($runtime->poll());
        self::assertTrue($runtime->isClosed());
        self::assertStringContainsString('"event":"runtime.failed"', implode('', $lines));
        self::assertStringContainsString('"reason":"poll_failed"', implode('', $lines));
        self::assertStringNotContainsString('transport-secret', implode('', $lines));
    }

    public function testCloseIsIdempotentEvenWhenTransportCloseThrows(): void
    {
        $transport = new FakeConnectedTransport();
        $transport->throwOnClose = true;
        $clock = new RuntimeTestClock();
        $world = new WorldSimulation();
        $runtime = new ServerRuntime(
            $transport,
            new RuntimeLoginFactory(),
            new BedrockPlayChannelFactory(new EmptyInitializationFactory()),
            $world,
            new FixedRateWorldLoop($world, $clock),
            new RecordingEventEncoder(),
        );
        $runtime->close();
        $runtime->close();
        self::assertTrue($runtime->isClosed());
        self::assertSame(1, $transport->closeCalls);
    }

    public function testCloseDrainsJoinedPlayerDisconnectLifecycleBeforeTransportClose(): void
    {
        $transport = new FakeConnectedTransport();
        $clock = new RuntimeTestClock();
        $simulation = new WorldSimulation();
        $loginFactory = new RuntimeLoginFactory();
        $runtime = new ServerRuntime(
            $transport,
            $loginFactory,
            new BedrockPlayChannelFactory(new EmptyInitializationFactory()),
            $simulation,
            new FixedRateWorldLoop($simulation, $clock),
            new RecordingEventEncoder(),
        );
        $info = new SessionInfo('127.0.0.1', 20_001, 42, 1_400, 11);
        $client = $this->advanceToInitializing($runtime, $transport, $info, $loginFactory);
        $this->receiveEncrypted(
            $transport,
            $info,
            $client,
            new SetLocalPlayerAsInitializedPacket(UnsignedLong::fromInt(1)),
        );
        self::assertTrue($runtime->poll());
        $clock->advance(50_000_000);
        self::assertTrue($runtime->poll());
        self::assertCount(1, $simulation->snapshot()->players);

        $runtime->close();

        self::assertSame([], $simulation->snapshot()->players);
        self::assertSame(0, $simulation->queuedLifecycleCommands());
        self::assertSame(1, $transport->closeCalls);
    }

    public function testAutosavePassContinuesWithinItsPerTickChunkBudgetUntilClean(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $provider = new InMemoryWorldProvider(new WorldData(
            new WorldMetadata('world', 0),
            'flat',
            new SpawnPosition(0, 64, 0),
        ));
        $blocks = new World(
            new WorldMetadata('world', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
            provider: $provider,
        );
        $blocks->chunk(new ChunkPosition(0, 0));
        $blocks->chunk(new ChunkPosition(1, 0));
        $blocks->chunk(new ChunkPosition(2, 0));
        $simulation = new WorldSimulation(blockWorld: $blocks, blockPalette: $palette);
        $clock = new RuntimeTestClock();
        $runtime = new ServerRuntime(
            new FakeConnectedTransport(),
            new RuntimeLoginFactory(),
            new BedrockPlayChannelFactory(new EmptyInitializationFactory()),
            $simulation,
            new FixedRateWorldLoop($simulation, $clock),
            new RecordingEventEncoder(),
            persistentWorld: $blocks,
            autosaveIntervalTicks: 5,
            autosaveChunkBudget: 1,
        );

        $clock->advance(250_000_000);
        self::assertTrue($runtime->poll());
        self::assertCount(1, $provider->savedRevisions);
        self::assertSame(2, $blocks->dirtyChunkCount());
        $clock->advance(50_000_000);
        self::assertTrue($runtime->poll());
        self::assertCount(2, $provider->savedRevisions);
        self::assertSame(1, $blocks->dirtyChunkCount());
        $clock->advance(50_000_000);
        self::assertTrue($runtime->poll());
        self::assertCount(3, $provider->savedRevisions);
        self::assertSame(0, $blocks->dirtyChunkCount());
    }

    public function testCloseFlushesAndClosesPersistentWorldBeforeTransportCompletion(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $provider = new InMemoryWorldProvider(new WorldData(
            new WorldMetadata('world', 0),
            'flat',
            new SpawnPosition(0, 64, 0),
        ));
        $blocks = new World(
            new WorldMetadata('world', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
            provider: $provider,
        );
        $blocks->chunk(new ChunkPosition(0, 0));
        $simulation = new WorldSimulation(blockWorld: $blocks, blockPalette: $palette);
        $transport = new FakeConnectedTransport();
        $runtime = new ServerRuntime(
            $transport,
            new RuntimeLoginFactory(),
            new BedrockPlayChannelFactory(new EmptyInitializationFactory()),
            $simulation,
            new FixedRateWorldLoop($simulation, new RuntimeTestClock()),
            new RecordingEventEncoder(),
            persistentWorld: $blocks,
        );

        $runtime->close();
        $runtime->close();

        self::assertTrue($provider->closed);
        self::assertSame(1, $provider->closeCalls);
        self::assertCount(1, $provider->chunks);
        self::assertSame(1, $transport->closeCalls);
        self::assertNull($runtime->failure());
    }

    public function testWorldDurabilityFailureIsRetainedWhileShutdownStillClosesResources(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $provider = new InMemoryWorldProvider(new WorldData(
            new WorldMetadata('world', 0),
            'flat',
            new SpawnPosition(0, 64, 0),
        ));
        $blocks = new World(
            new WorldMetadata('world', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
            provider: $provider,
        );
        $blocks->chunk(new ChunkPosition(0, 0));
        $provider->failSaves = true;
        $simulation = new WorldSimulation(blockWorld: $blocks, blockPalette: $palette);
        $transport = new FakeConnectedTransport();
        $runtime = new ServerRuntime(
            $transport,
            new RuntimeLoginFactory(),
            new BedrockPlayChannelFactory(new EmptyInitializationFactory()),
            $simulation,
            new FixedRateWorldLoop($simulation, new RuntimeTestClock()),
            new RecordingEventEncoder(),
            persistentWorld: $blocks,
        );

        $runtime->close();

        self::assertNotNull($runtime->failure());
        self::assertTrue($provider->closed);
        self::assertSame(1, $transport->closeCalls);
        self::assertSame(1, $blocks->dirtyChunkCount());
    }

    public function testExistingOperatorReceivesOperatorAbilitiesAndPermittedCommandsDuringLogin(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-runtime-authority-' . bin2hex(random_bytes(8));
        $permissions = new PermissionStore($directory . DIRECTORY_SEPARATOR . 'permissions.json');
        $permissions->setOperator('00000000-0000-0000-0000-000000000001', 'Player', true);
        $commands = $this->authorityCommands();
        $transport = new FakeConnectedTransport();
        $clock = new RuntimeTestClock();
        $world = new WorldSimulation();
        $loginFactory = new RuntimeLoginFactory();
        $runtime = new ServerRuntime(
            $transport,
            $loginFactory,
            new BedrockPlayChannelFactory(
                new EmptyInitializationFactory([UpdateAbilitiesPacket::survival(1)]),
                commandRegistry: $commands,
                permissionStore: $permissions,
            ),
            $world,
            new FixedRateWorldLoop($world, $clock),
            new RecordingEventEncoder(),
            commandRegistry: $commands,
            permissionStore: $permissions,
        );

        try {
            $this->advanceToInitializing(
                $runtime,
                $transport,
                new SessionInfo('127.0.0.1', 20_001, 42, 1_400, 11),
                $loginFactory,
            );
            $packets = $this->decodeEncryptedPackets($transport->sent, $loginFactory->clientDecryptor());
            $abilities = array_values(array_filter($packets, static fn(Packet $packet): bool => $packet instanceof UpdateAbilitiesPacket));
            $available = array_values(array_filter($packets, static fn(Packet $packet): bool => $packet instanceof AvailableCommandsPacket));

            self::assertCount(1, $abilities);
            self::assertInstanceOf(UpdateAbilitiesPacket::class, $abilities[0]);
            self::assertSame(PlayerPermission::Operator, $abilities[0]->abilities->playerPermission);
            self::assertSame(CommandPermissionLevel::Operator, $abilities[0]->abilities->commandPermission);
            self::assertSame(0xff, $abilities[0]->abilities->layers[0]->abilityValues);
            self::assertCount(1, $available);
            self::assertInstanceOf(AvailableCommandsPacket::class, $available[0]);
            self::assertSame(['public', 'protected'], array_map(
                static fn($definition): string => $definition->name,
                $available[0]->commands,
            ));
        } finally {
            $runtime->close();
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testLiveAuthorityRefreshUsesAbilitiesThenCommandsForOperatorAndCommandsOnlyForGrant(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-runtime-authority-' . bin2hex(random_bytes(8));
        $permissions = new PermissionStore($directory . DIRECTORY_SEPARATOR . 'permissions.json');
        $commands = $this->authorityCommands();
        $transport = new FakeConnectedTransport();
        $clock = new RuntimeTestClock();
        $world = new WorldSimulation();
        $loginFactory = new RuntimeLoginFactory();
        $runtime = new ServerRuntime(
            $transport,
            $loginFactory,
            new BedrockPlayChannelFactory(
                new EmptyInitializationFactory(),
                commandRegistry: $commands,
                permissionStore: $permissions,
            ),
            $world,
            new FixedRateWorldLoop($world, $clock),
            new RecordingEventEncoder(),
            commandRegistry: $commands,
            permissionStore: $permissions,
        );

        try {
            $this->advanceToInitializing(
                $runtime,
                $transport,
                new SessionInfo('127.0.0.1', 20_001, 42, 1_400, 11),
                $loginFactory,
            );
            $decryptor = $loginFactory->clientDecryptor();
            $this->decodeEncryptedPackets($transport->sent, $decryptor);
            $transport->sent = [];

            $permissions->setOperator('00000000-0000-0000-0000-000000000001', 'Player', true);
            $runtime->refreshPlayerAuthority('00000000-0000-0000-0000-000000000001', true);
            self::assertTrue($runtime->poll());
            $operatorPackets = $this->decodeEncryptedPackets($transport->sent, $decryptor);
            self::assertSame([UpdateAbilitiesPacket::class, AvailableCommandsPacket::class], array_map(
                static fn(Packet $packet): string => $packet::class,
                $operatorPackets,
            ));
            $transport->sent = [];

            $permissions->grant('00000000-0000-0000-0000-000000000001', 'Player', 'example.use');
            $runtime->refreshPlayerAuthority('00000000-0000-0000-0000-000000000001', false);
            self::assertTrue($runtime->poll());
            $grantPackets = $this->decodeEncryptedPackets($transport->sent, $decryptor);
            self::assertCount(1, $grantPackets);
            self::assertInstanceOf(AvailableCommandsPacket::class, $grantPackets[0]);
        } finally {
            $runtime->close();
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testCommandOutputEchoesAuthenticatedUuidRequestIdAndActorId(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-runtime-authority-' . bin2hex(random_bytes(8));
        $permissions = new PermissionStore($directory . DIRECTORY_SEPARATOR . 'permissions.json');
        $commands = $this->authorityCommands();
        $transport = new FakeConnectedTransport();
        $clock = new RuntimeTestClock();
        $world = new WorldSimulation();
        $loginFactory = new RuntimeLoginFactory();
        $runtime = new ServerRuntime(
            $transport,
            $loginFactory,
            new BedrockPlayChannelFactory(
                new EmptyInitializationFactory(),
                commandRegistry: $commands,
                permissionStore: $permissions,
            ),
            $world,
            new FixedRateWorldLoop($world, $clock),
            new RecordingEventEncoder(),
            commandRegistry: $commands,
            permissionStore: $permissions,
        );
        $info = new SessionInfo('127.0.0.1', 20_001, 42, 1_400, 11);

        try {
            $client = $this->advanceToInitializing($runtime, $transport, $info, $loginFactory);
            $decryptor = $loginFactory->clientDecryptor();
            $this->decodeEncryptedPackets($transport->sent, $decryptor);
            $transport->sent = [];
            $this->receiveEncrypted($transport, $info, $client, new SetLocalPlayerAsInitializedPacket(UnsignedLong::fromInt(1)));
            self::assertTrue($runtime->poll());
            $clock->advance(50_000_000);
            self::assertTrue($runtime->poll());
            $this->decodeEncryptedPackets($transport->sent, $decryptor);
            $transport->sent = [];

            $this->receiveEncrypted($transport, $info, $client, new CommandRequestPacket(
                '/public',
                new CommandOrigin(
                    CommandOriginType::Player,
                    'ffffffff-ffff-ffff-ffff-ffffffffffff',
                    'retail-request',
                    0x0102030405060708,
                ),
            ));
            self::assertTrue($runtime->poll());
            $packets = $this->decodeEncryptedPackets($transport->sent, $decryptor);
            self::assertCount(1, $packets);
            self::assertInstanceOf(CommandOutputPacket::class, $packets[0]);
            self::assertSame('00000000-0000-0000-0000-000000000001', $packets[0]->origin->uuid);
            self::assertSame('retail-request', $packets[0]->origin->requestId);
            self::assertSame(0x0102030405060708, $packets[0]->origin->playerId);
        } finally {
            $runtime->close();
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    private function authorityCommands(): CommandRegistry
    {
        $plugins = new AuthorityPluginControl();
        $execution = new PluginExecutionContext();
        $actions = new PluginActionBuffer();
        $ownership = new PluginOwnershipRegistry();
        $events = new EventDispatcher($plugins, $execution, $actions, $ownership);
        $commands = new CommandRegistry($plugins, $execution, $actions, $ownership, $events);
        $commands->registerServer(
            new CommandDefinition('public', 'Public command', 'public'),
            static fn(): CommandResult => CommandResult::SUCCESS,
        );
        $commands->registerServer(
            new CommandDefinition('protected', 'Protected command', 'protected', permission: 'example.use'),
            static fn(): CommandResult => CommandResult::SUCCESS,
        );

        return $commands;
    }

    /**
     * @param list<array{string, int, string, Reliability, int}> $sent
     * @return list<Packet>
     */
    private function decodeEncryptedPackets(array $sent, BedrockDecryptor $decryptor): array
    {
        $packets = [];
        foreach ($sent as $payload) {
            $batch = BedrockBatchCodec::decode(
                $decryptor->decryptEnvelope($payload[2]),
                CompressionMode::NegotiatedZlib,
                new BatchLimits(),
                256,
            );
            foreach ($batch->packets as $frame) {
                if ($frame->header->packetId === PacketIds::UPDATE_ABILITIES) {
                    [$abilities, $reader] = PlayerAbilities::read(ByteBufferReader::fromString($frame->payload, strlen($frame->payload)));
                    self::assertSame(0, $reader->remaining());
                    $packets[] = new UpdateAbilitiesPacket($abilities);
                } elseif ($frame->header->packetId === PacketIds::AVAILABLE_COMMANDS) {
                    $packets[] = AvailableCommandsPacket::decode($frame->payload);
                } elseif ($frame->header->packetId === PacketIds::COMMAND_OUTPUT) {
                    $packets[] = CommandOutputPacket::decode($frame->payload);
                }
            }
        }

        return $packets;
    }

    private function receive(FakeConnectedTransport $transport, SessionInfo $info, string $payload): void
    {
        $transport->payloads[] = new ReceivedPayload(
            $info->remoteAddress,
            $info->remotePort,
            $payload,
            Reliability::ReliableOrdered,
            0,
        );
    }

    private function advanceToInitializing(
        ServerRuntime $runtime,
        FakeConnectedTransport $transport,
        SessionInfo $info,
        RuntimeLoginFactory $loginFactory,
    ): BedrockEncryptor {
        $transport->events[] = new SessionOpenedEvent($info);
        self::assertTrue($runtime->poll());
        $this->receive($transport, $info, $this->encode([new RequestNetworkSettingsPacket()], CompressionMode::Uncompressed));
        self::assertTrue($runtime->poll());
        $transport->sent = [];
        $this->receive($transport, $info, $this->encode([$loginFactory->loginPacket()], CompressionMode::NegotiatedZlib));
        self::assertTrue($runtime->poll());
        $handshake = $this->decode($transport->sent[0][2], CompressionMode::NegotiatedZlib);
        self::assertInstanceOf(ServerToClientHandshakePacket::class, $handshake);
        $clientEncryptor = $loginFactory->clientEncryptor($handshake);
        $transport->sent = [];
        $this->receiveEncrypted($transport, $info, $clientEncryptor, new ClientToServerHandshakePacket());
        self::assertTrue($runtime->poll());
        $this->receiveEncrypted($transport, $info, $clientEncryptor, new ResourcePackClientResponsePacket(ResourcePackResponseStatus::HaveAllPacks, []));
        self::assertTrue($runtime->poll());
        $this->receiveEncrypted($transport, $info, $clientEncryptor, new ResourcePackClientResponsePacket(ResourcePackResponseStatus::Completed, []));
        self::assertTrue($runtime->poll());

        return $clientEncryptor;
    }

    private function receiveEncrypted(FakeConnectedTransport $transport, SessionInfo $info, BedrockEncryptor $encryptor, Packet $packet): void
    {
        $this->receive($transport, $info, $encryptor->encryptEnvelope($this->encode([$packet], CompressionMode::NegotiatedZlib)));
    }

    private function stationaryMovementPacket(UnsignedLong $tick): PlayerAuthInputPacket
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
            [],
            1,
            0,
            0,
            0.0,
            0.0,
            $tick,
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
        );
    }

    /** @param list<Packet> $packets */
    private function encode(array $packets, CompressionMode $mode): string
    {
        $frames = array_map(
            static fn(Packet $packet): PacketFrame => new PacketFrame(
                new PacketHeader(BedrockPacketCodec::packetId($packet)),
                BedrockPacketCodec::encode($packet),
            ),
            $packets,
        );

        return BedrockBatchCodec::encode(new BedrockBatch($frames, $mode, 256), new BatchLimits());
    }

    private function decode(string $payload, CompressionMode $mode): Packet
    {
        $batch = BedrockBatchCodec::decode($payload, $mode, new BatchLimits(), 256);

        return BedrockPacketCodec::decode($batch->packets[0]->header->packetId, $batch->packets[0]->payload);
    }
}

final class FakeConnectedTransport implements ConnectedTransport
{
    /** @var list<SessionOpenedEvent|SessionClosedEvent> */ public array $events = [];
    /** @var list<ReceivedPayload> */ public array $payloads = [];
    /** @var list<array{string, int, string, Reliability, int}> */ public array $sent = [];
    /** @var list<array{string, int}> */ public array $removed = [];
    public bool $throwOnClose = false;
    public bool $throwOnPoll = false;
    public int $closeCalls = 0;

    public function poll(int $maximumDatagrams): int
    {
        if ($this->throwOnPoll) {
            throw new \RuntimeException('transport-secret');
        }
        return 0;
    }
    public function drainSessionEvents(): array
    {
        $events = $this->events;
        $this->events = [];
        return $events;
    }
    public function drainReceivedPayloads(): array
    {
        $payloads = $this->payloads;
        $this->payloads = [];
        return $payloads;
    }
    public function sendPayload(string $remoteAddress, int $remotePort, string $payload, Reliability $reliability, int $orderingChannel = 0): void
    {
        $this->sent[] = [$remoteAddress, $remotePort, $payload, $reliability, $orderingChannel];
    }
    public function removeSession(string $remoteAddress, int $remotePort): bool
    {
        $this->removed[] = [$remoteAddress, $remotePort];
        return true;
    }
    public function close(): void
    {
        ++$this->closeCalls;
        if ($this->throwOnClose) {
            throw new \RuntimeException('close');
        }
    }
}

final class RuntimeTestClock implements SimulationClock
{
    public int $now = 1;
    public function nowNanoseconds(): int
    {
        return $this->now;
    }
    public function advance(int $nanoseconds): void
    {
        $this->now += $nanoseconds;
    }
}

final class RuntimeLoginFactory implements LoginChannelFactory
{
    private readonly OpenSslEphemeralKeyFactory $keys;
    private readonly P384KeyPair $client;
    private ?string $lastSessionKey = null;
    public function __construct(
        private readonly ?int $failPort = null,
        private readonly string $displayName = 'Player',
        private readonly bool $uniqueIdentities = false,
    ) {
        $this->keys = new OpenSslEphemeralKeyFactory(dirname(__DIR__) . '/Fixtures/openssl.cnf');
        $this->client = $this->keys->generate();
    }
    public function create(SessionInfo $session): BedrockLoginChannel
    {
        if ($session->remotePort === $this->failPort) {
            throw new \RuntimeException('factory');
        }
        $login = new AuthenticatedLogin(
            $this->displayName,
            $this->uniqueIdentities
                ? sprintf('00000000-0000-0000-0000-%012d', $session->remotePort)
                : '00000000-0000-0000-0000-000000000001',
            '1',
            $this->client->publicKey,
            new VerifiedClientData(1, 1, "\0\0\0\0", 0, 0, '', '{}', []),
        );
        return new BedrockLoginChannel(new LoginSession(
            new RuntimeLoginClock(),
            new RuntimeAuthenticator($login),
            new RuntimeHandshakeFactory($this->keys),
        ));
    }
    public function loginPacket(): LoginPacket
    {
        return new LoginPacket(ProtocolVersion::CURRENT, new LoginAuthentication(AuthenticationType::Full, 'token'), 'client');
    }
    public function clientEncryptor(ServerToClientHandshakePacket $packet): BedrockEncryptor
    {
        $jws = HandshakeJwt::parse($packet->jwt);
        $encodedSalt = $jws->payload['salt'] ?? null;
        $encodedKey = $jws->header['x5u'] ?? null;
        if (!is_string($encodedSalt) || !is_string($encodedKey)) {
            throw new \RuntimeException('handshake');
        }
        $salt = base64_decode($encodedSalt, true);
        if (!is_string($salt)) {
            throw new \RuntimeException('salt');
        }
        $server = P384::importPublicDerBase64($encodedKey);
        $this->lastSessionKey = P384::deriveSessionKey($salt, P384::deriveSharedSecret($this->client->privateKey, $server));

        return new BedrockEncryptor($this->lastSessionKey);
    }

    public function clientDecryptor(): BedrockDecryptor
    {
        if ($this->lastSessionKey === null) {
            throw new \RuntimeException('Client session key has not been established.');
        }

        return new BedrockDecryptor($this->lastSessionKey);
    }
}

final class RuntimeLoginClock implements MonotonicClock
{
    public function nowNanoseconds(): int
    {
        return 1;
    }
}

final readonly class RuntimeAuthenticator implements LoginAuthenticator
{
    public function __construct(private AuthenticatedLogin $login) {}
    public function authenticate(LoginPacket $packet, \Bedriox\Server\Login\AuthenticationMode $mode): AuthenticatedLogin
    {
        return $this->login;
    }
}

final readonly class RuntimeHandshakeFactory implements HandshakeMaterialFactory
{
    public function __construct(private OpenSslEphemeralKeyFactory $keys) {}
    public function create(): HandshakeMaterial
    {
        return new HandshakeMaterial($this->keys->generate(), str_repeat('A', 16));
    }
}

final readonly class EmptyInitializationFactory implements PlayInitializationFactory
{
    /** @param list<Packet> $packets */
    public function __construct(private array $packets = []) {}

    public function create(AuthenticatedLogin $login, UnsignedLong $runtimeEntityId, ?PlayerBootstrap $bootstrap = null): array
    {
        return $this->packets;
    }

    public function fixedFlatRuntimeIds(): array
    {
        return ['air' => 1, 'bedrock' => 2, 'dirt' => 3, 'grass_block' => 4];
    }
}

final class AuthorityPluginControl implements PluginRuntimeControl
{
    public function isEnabled(string $plugin): bool
    {
        return true;
    }

    public function version(string $plugin): string
    {
        return '1.0.0';
    }

    public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void {}
}

final class RecordingEventEncoder implements WorldEventPacketEncoder
{
    /** @var list<class-string> */ public array $classes = [];
    /** @var list<WorldEvent> */ public array $events = [];

    /**
     * @param class-string|null $failOnClass
     * @param class-string|null $packetCountOnClass
     */
    public function __construct(
        private readonly ?string $failOnClass = null,
        private readonly ?string $packetCountOnClass = null,
        private readonly int $packetCount = 0,
    ) {}

    public function clearEvents(): void
    {
        $this->events = [];
    }

    public function encode(WorldEvent $event, array $sessions): array
    {
        $this->classes[] = $event::class;
        $this->events[] = $event;
        if ($event::class === $this->failOnClass) {
            throw new \RuntimeException('event fixture failure');
        }
        if ($event::class === $this->packetCountOnClass) {
            $sessionId = array_key_first($sessions);
            if (!is_string($sessionId)) {
                throw new \RuntimeException('Expected a runtime session fixture.');
            }
            $packets = [];
            for ($index = 0; $index < $this->packetCount; ++$index) {
                $packets[] = new DirectedPacket($sessionId, new ChatPacket('server', 'fixture'));
            }

            return $packets;
        }
        return [];
    }
}
