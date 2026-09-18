<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Discovery\AdvertisedGameMode;
use Bedriox\Protocol\Discovery\BedrockServerAdvertisement;
use Bedriox\Protocol\Identity\ClientDataJwtVerifier;
use Bedriox\Protocol\Security\EphemeralKeyFactory;
use Bedriox\Protocol\Security\OpenSslEphemeralKeyFactory;
use Bedriox\RakNet\DiscoveryServer;
use Bedriox\RakNet\DiscoveryStatus;
use Bedriox\RakNet\TransportConfig;
use Bedriox\Server\Authentication\Discovery\CurlHttpsJsonTransport;
use Bedriox\Server\Authentication\Discovery\MinecraftDiscoveryJwkProvider;
use Bedriox\Server\Authentication\FullTokenAuthenticator;
use Bedriox\Server\Authentication\SystemAuthenticationClock;
use Bedriox\Server\Login\AuthenticationMode;
use Bedriox\Server\Login\ExplicitSelfSignedLoginAuthenticator;
use Bedriox\Server\Login\FullLoginAuthenticatorAdapter;
use Bedriox\Server\Login\LoginAuthenticator;
use Bedriox\Server\Login\SecureHandshakeMaterialFactory;
use Bedriox\Server\Login\SystemMonotonicClock;
use Bedriox\Server\Observability\CrashContextPublisher;
use Bedriox\Server\Simulation\FixedRateWorldLoop;
use Bedriox\Server\Simulation\PluginGameplayEventBridge;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationLimits;
use Bedriox\Server\Simulation\SimulationPluginApiBackend;
use Bedriox\Server\Simulation\SystemSimulationClock;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\Transport\DiscoveryServerTransport;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\DefaultBlockPalette;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldGeneratorFactory;
use Bedriox\Server\World\WorldMetadata;
use Throwable;

/** Fail-closed production dependency composition; FULL discovery completes before UDP bind. */
final class ServerBootstrap
{
    public const string SELF_SIGNED_WARNING = 'WARNING: SELF_SIGNED authentication is insecure and intended only for isolated development.';

    public function __construct(
        private readonly ?ConfiguredWorldFactory $worldFactory = null,
        private readonly ?EphemeralKeyFactory $ephemeralKeys = null,
    ) {}

    public function create(
        ServerConfig $config,
        ?PlayInitializationFactory $initialization = null,
        ?RuntimeDiagnostics $diagnostics = null,
        ?CrashContextPublisher $crashContext = null,
        ?PluginGameplayEventBridge $pluginEvents = null,
    ): BootstrappedServer {
        $diagnostics ??= RuntimeDiagnostics::disabled();
        $authenticationClock = new SystemAuthenticationClock();
        $authenticator = $this->authenticator($config->authenticationMode, $authenticationClock);
        $configuredOpenSsl = getenv('OPENSSL_CONF');
        $openSslConfiguration = OpenSslConfiguration::discover(
            PHP_BINARY,
            is_string($configuredOpenSsl) && $configuredOpenSsl !== '' ? $configuredOpenSsl : null,
        );
        $ephemeralKeys = $this->ephemeralKeys ?? new OpenSslEphemeralKeyFactory($openSslConfiguration);
        // A listener that cannot complete Bedrock's P-384 handshake is not joinable.
        // Qualify the exact production key factory before opening the world or binding UDP.
        $ephemeralKeys->generate();
        $runtimeLimits = new RuntimeLimits(
            maximumSessions: $config->maximumPlayers,
            maximumChunkRadius: $config->viewDistance,
            preloadedChunkRadius: $config->spawnRadius,
            maximumStreamingPacketsPerPoll: $config->chunksSendPerTick,
        );
        $simulationLimits = new SimulationLimits(
            ticksPerSecond: $config->ticksPerSecond,
            maximumPlayers: $config->maximumPlayers,
            maximumQueuedLifecycleCommands: $config->maximumPlayers,
            maximumQueuedLifecycleBytes: max(65_536, $config->maximumPlayers * 144),
        );
        $data = BedrockDataSet::bundled();
        $networkStates = $data->blockStateRegistry();
        $internalStates = new BlockStateRegistry($networkStates->states());
        $flatPalette = FixedFlatBlockPalette::fromRegistry($internalStates);
        $defaultPalette = DefaultBlockPalette::fromRegistry($internalStates);
        $openedWorld = $this->worldFactory?->open($config, $data)
            ?? $this->ephemeralWorld($config, $internalStates);
        $flatWorld = $openedWorld->world;
        $discovery = null;
        try {
            $initialization ??= BedrockPlayInitializationFactory::forWorld(
                $data,
                $runtimeLimits,
                $openedWorld->data,
            );
            $spawn = $flatWorld->spawn();
            $world = new WorldSimulation(
                $simulationLimits,
                new Position($spawn->x, $spawn->y, $spawn->z),
                $flatWorld,
                $flatPalette,
                $pluginEvents,
                $defaultPalette->water,
                $defaultPalette->lava,
            );
            $chunkSerializer = new BedrockChunkPacketSerializer(
                $blockTranslator = new BlockNetworkTranslator($internalStates, $networkStates),
                $data->plainsBiomeRuntimeId(),
            );
            $inventoryProjector = BedrockInventoryPacketProjector::fromData($data, $blockTranslator);
            $serverGuid = random_int(1, PHP_INT_MAX);
            $advertisement = new BedrockServerAdvertisement(
                motd: $config->serverName,
                onlinePlayers: 0,
                maximumPlayers: $config->maximumPlayers,
                serverId: $serverGuid,
                subMotd: $config->motd,
                gameMode: AdvertisedGameMode::Survival,
                nintendoLimited: false,
                ipv4Port: $config->port,
            );
            $status = new DiscoveryStatus(
                $advertisement->encode(),
                acceptingConnections: true,
            );
            $discovery = DiscoveryServer::bind(
                new TransportConfig(
                    bindAddress: $config->bindAddress,
                    port: $config->port,
                    maximumSessions: $config->maximumPlayers,
                    maximumPendingHandshakes: $config->maximumPlayers,
                    maximumSessionEvents: max($config->maximumPlayers, 1_024),
                ),
                $serverGuid,
                $status,
            );
            $runtime = new ServerRuntime(
                new DiscoveryServerTransport($discovery, $diagnostics),
                new ConfiguredLoginChannelFactory(
                    new SystemMonotonicClock(),
                    $authenticator,
                    new SecureHandshakeMaterialFactory($ephemeralKeys),
                    $config->authenticationMode,
                ),
                new BedrockPlayChannelFactory(
                    $initialization,
                    limits: $runtimeLimits,
                    diagnostics: $diagnostics,
                    world: $flatWorld,
                    chunkSerializer: $chunkSerializer,
                    viewDistance: $config->viewDistance,
                    spawnRadius: $config->spawnRadius,
                    chunksGeneratePerTick: $config->chunksGeneratePerTick,
                    chunksSendPerTick: $config->chunksSendPerTick,
                    inventoryProjector: $inventoryProjector,
                ),
                $world,
                new FixedRateWorldLoop($world, new SystemSimulationClock()),
                new BedrockWorldEventPacketEncoder($chunkSerializer, $inventoryProjector),
                $runtimeLimits,
                diagnostics: $diagnostics,
                crashContext: $crashContext,
                persistentWorld: $openedWorld->world,
                autosaveIntervalTicks: $config->levelAutosaveIntervalTicks,
                autosaveChunkBudget: $config->chunksSavePerTick,
            );
        } catch (Throwable $exception) {
            $discovery?->close();
            try {
                $flatWorld->close();
            } catch (Throwable) {
                // Preserve the composition failure while still attempting provider cleanup.
            }
            throw $exception;
        }

        return new BootstrappedServer(
            $runtime,
            $discovery->localAddress(),
            $discovery->localPort(),
            $config->authenticationMode === AuthenticationMode::SELF_SIGNED ? self::SELF_SIGNED_WARNING : null,
            new SimulationPluginApiBackend($world, $flatWorld, $flatPalette),
            $flatWorld,
        );
    }

    private function ephemeralWorld(ServerConfig $config, BlockStateRegistry $states): OpenedWorld
    {
        $spawnOverride = $config->spawnX === null ? null : new SpawnPosition(
            $config->spawnX,
            $config->spawnY ?? 64,
            $config->spawnZ ?? 0,
        );
        $metadata = new WorldMetadata($config->levelName, $config->levelSeed);
        $generator = WorldGeneratorFactory::create($config->levelGenerator, $config->levelSeed, $states);
        $world = new World(
            $metadata,
            $generator,
            new ChunkRepository($config->chunkCacheLimit),
            $spawnOverride,
        );

        return new OpenedWorld($world, new WorldData(
            $metadata,
            $world->generatorName(),
            $world->spawn(),
            difficulty: match ($config->difficulty) {
                'peaceful' => 0,
                'easy' => 1,
                'normal' => 2,
                'hard' => 3,
                default => throw new \LogicException('Validated difficulty became unsupported.'),
            },
        ));
    }

    private function authenticator(AuthenticationMode $mode, SystemAuthenticationClock $clock): LoginAuthenticator
    {
        $clientData = new ClientDataJwtVerifier();
        if ($mode === AuthenticationMode::SELF_SIGNED) {
            return new ExplicitSelfSignedLoginAuthenticator($clock, $clientData);
        }
        $provider = new MinecraftDiscoveryJwkProvider(new CurlHttpsJsonTransport(), $clock);
        $provider->refresh(true);
        $provider->keys();

        return new FullLoginAuthenticatorAdapter(new FullTokenAuthenticator($provider, $clock, $clientData));
    }
}
