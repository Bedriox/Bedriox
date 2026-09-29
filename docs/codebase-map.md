# Codebase map

This repository is the executable server and composition root. Code belongs here only when it owns application startup, policy, sessions, authoritative state, worlds, commands, or observability. RakNet wire mechanics belong in RakNet, Bedrock packet codecs in Protocol, and immutable versioned registries in Data.

## Entry and composition

- `bedriox.cmd` and `bedriox` are production launchers that select only the adjacent qualified Runtime.
- `bootstrap/bedriox.php` and `src/Environment/` validate Runtime identity and integrity before Composer autoload.
- `tools/install-runtime.php` installs one local archive through the bounded staged installer.
- `bin/bedriox` remains the thin PHP application entry point and is preserved across Runtime installation.
- `src/Bedriox.php` owns the product identity, parses the command, and delegates server construction and execution. `src/BuildInfo.php` assembles public build details from the server, Protocol, plugin API, and running PHP authorities without duplicating their versions.
- `src/Runtime/ServerPropertiesFile.php` and `ServerSettingsFile.php` load the bounded user-facing and advanced configuration files.
- `src/Runtime/ServerConfig.php` is the immutable effective configuration.
- `src/Runtime/ServerBootstrap.php` composes verified data, authentication, world, protocol channels, transport, simulation, and diagnostics.
- `src/Runtime/BootstrappedServer.php`, `RuntimeRunner.php`, and `RuntimeDriver.php` own the launched process lifecycle.

Composition code may connect component APIs but must not reproduce their internals. A wire-format defect is fixed in its owning component and consumed through an exact pin.

## Authentication and login

`src/Authentication/` owns FULL Token policy, trusted-key discovery and caching, claim validation, identity derivation, and strict resource limits. It does not own Bedrock JWT wire decoding.

`src/Login/` owns the pre-play state machine and authentication policy adapter. `BedrockLoginChannel` translates bounded Protocol values into state transitions and effects. Login code must not admit a player directly into mutable world state.

## Runtime and session orchestration

`src/Runtime/ServerRuntime.php` is the bounded event-loop composition boundary. `RuntimeSession` and `SessionPhase` track session-local lifecycle. Configured login and play factories create the appropriate channel without exposing their mutable internals.

`BedrockPlayChannel` validates decoded play input, converts accepted behavior into immutable simulation commands, manages initialization and the session's chunk view, and emits bounded outgoing payloads. It does not directly mutate authoritative players or world simulation.

`RuntimeDiagnostics` is the only application diagnostic boundary. Diagnostics must remain bounded and allowlisted and must never include authentication material or raw private payloads.

`src/Observability/Memory/` owns process-memory sampling, pressure classification with hysteresis, the adaptive cyclic garbage collector, immutable maintenance reports, and the emergency reserve. `ServerRuntime` applies those decisions only at bounded tick boundaries; it may trim rebuildable prepared envelopes and accelerate safe world unload work, but it cannot discard authoritative state.

## Authoritative simulation

`src/Player/` owns the authoritative mutable `Player` aggregate, its authenticated identity, movement state, and the capacity-bounded `PlayerRegistry`. The registry maintains the session and identity indexes together, produces deterministic immutable snapshots, and removes every index as one lifecycle operation. Player objects have no packet, socket, encryption, or transport APIs.

`src/Simulation/` owns deterministic tick processing. Inputs enter as immutable values under `Simulation/Command/`; outputs leave under `Simulation/Event/`. `WorldSimulation` exclusively owns the player registry and is therefore the only layer allowed to mutate live players, while `FixedRateWorldLoop` schedules bounded tick work with monotonic time. `PlayerSnapshot` remains the immutable boundary consumed by runtime packet projection.

Protocol positions and flags are translated before commands reach this layer. Simulation code must not depend on packet IDs, encrypted batches, RakNet sessions, or network runtime block IDs.

## World and blocks

`src/World/` owns world metadata, spawn resolution, the `default` and `flat` generators, chunks, subchunks, the bounded repository, and grace-based safe chunk unloading. `src/World/Generation/` owns deterministic noise, warped climate, paired base/carved three-dimensional density sampling, complete biome resolution, generation-only canonical palettes, mutable worker-local chunk construction, caves, aquifers, decoration, and terrain-stage values. `World` coordinates provider-first loading, generation, dirty revisions, fair bounded autosave, exact-revision save-before-unload, and close-time flushing. Generators create deterministic canonical terrain only for missing chunks. `src/World/Provider/` defines the format-independent persistence boundary and its writable LevelDB implementation; `src/World/Storage/` owns bounded `level.dat`, `levelname.txt`, NBT, palette, key, and native LevelDB adapters.

`src/Player/Persistence/` owns UUID-keyed player profile encoding, atomic file storage, authenticated restoration, dirty revision acknowledgement, and failed-save retry. `PlayerBootstrap` is the session-independent state shared by StartGame, initial inventory projection, chunk centering, and authoritative simulation admission. See [player persistence](player-persistence.md).

`src/Persistence/` owns bounded ordered I/O queues and authenticated storage subprocess proxies. `Persistence/World/` is the production `AsynchronousWorldProvider`: its child exclusively owns the LevelDB handle, while the parent polls deduplicated reads and exact-revision write acknowledgements. The same provider implements chunk-owned non-player entity snapshots and atomic cross-chunk ownership transfer; see [entity persistence](entity-persistence.md). `Persistence/Player/` applies the same ownership model to atomic player files; bounded login reads remain synchronous within admission, while routine writes do not block the simulation loop. `bootstrap/bedriox-io.php` is the private entry point for world, player, and log owners; private worker entry points remain outside the manifest-validated packaged Runtime in `bin/`.

`src/World/Block/` owns process-local internal block-state identity. `BlockStateRegistry` resolves canonical `minecraft:*` states; `InternalBlockStateId` is never persisted or sent; `BlockNetworkTranslator` is the explicit boundary to Data's current network palette.

`src/World/Environment/` owns bounded scheduled environmental work. Its fluid planner reads loaded chunks through an immutable view and returns revision-safe mutation plans; `WorldSimulation` remains the only commit owner. `WeatherCycle` owns deterministic persistent per-world progression, while Protocol level events are emitted only by runtime projection.

`src/Worker/` owns the managed process runtime, bounded task registry, core/plugin pool boundaries, and time-bounded main-loop completion dispatch. `src/Worker/Chunk/` owns RFC 0023's complete canonical chunk-transfer codec, the compact packed projection transfer, generation/preparation request codecs, revision-aware prepared-envelope LRU, deduplication, invalidation, and snapshots. `Worker/Task/GenerateChunkTask` is the detached core-world handler. `Worker/Task/PrepareChunkTask` maps the compact snapshot palettes, passes validated word arrays to Protocol, frames the `LevelChunk` batch, and compresses it through one reusable immutable worker context. Neither handler has authoritative installation, provider, player, session, cipher, or transport access. `src/Worker/Network/` owns ordered compression for non-prepared session batches. Authoritative validation and commit remain in `World` and the runtime channel.

`src/Gameplay/` owns the canonical item, tool, block, and crafting behavior catalogs, including stack sizes, durability, creative visibility, hardness, break timing, deterministic drops, recipe matching, and dynamic special-recipe results. The production `BlockCatalog` admits every built-in generator state and `src/World/Collision/BlockCollisionRegistry.php` assigns its process-local empty, full-cube, or bounded partial collision geometry. The same collision query serves players and dropped items. One shared live `ItemCatalog` serves commands, gameplay, persistence, packet projection, crafting, and bounded plugin definition registration. It admits every item in the active Data network registry, overlays explicit Bedriox mechanics such as tools and drops, and derives placeability from the admitted block-item mappings. Catalog admission alone does not claim unique gameplay behavior for every vanilla item. `src/Gameplay/Crafting/` owns the revisioned active recipe registry, two-by-two and three-by-three matching, special transformations, and current protocol projection; see [authoritative crafting](crafting.md). `src/Entity/Item/` owns the bounded lifecycle and collision of dropped-item actors. `src/Player/PlayerInventory.php` owns the fixed authoritative main inventory, cursor, selected hotbar slot, transient crafting inputs, custom item NBT, auxiliary variant, and atomic stack-request mutation. `src/Runtime/BedrockInventoryPacketProjector.php` is the only boundary that translates canonical stacks, durability NBT, auxiliary values, block states, and stable Data creative IDs to the current network item and block registries; decoded client stack IDs remain bounded reconciliation intent.

`src/Entity/` owns non-player entity definitions, authoritative identity, spatial indexing, physics, equipment, typed loot resolution, bounded natural spawning, activation-aware behavior, navigation values, and chunk-owned persistence coordination. `src/Api/Entity/` and `src/Api/Event/Entity/` are the protocol-neutral plugin surface; the public controller hierarchy submits bounded entity intent, while equipment and death events provide authoritative transition and loot boundaries. Plugins never own runtime IDs, actor packets, registries, or persistence records. `src/Runtime/BedrockLivingActorProjector.php` is the only translation from live living entities to the current Protocol actor metadata, attributes, properties, and equipment. See [entities, controllers, equipment, and loot](entities.md).

`src/Inventory/` owns canonical live storage inventories, paired-inventory composition, optimistic revisions, world block-entity persistence, portable shulker item data, and plugin-owned virtual containers. The simulation owns each player's active window and performs atomic player/container transactions; runtime code only projects the resulting events. See [storage containers](containers.md).

Runtime serialization and view scheduling live under `src/Runtime/` because they compose world state with the active Protocol contract. See [world and chunk pipeline](world-chunk-pipeline.md).

## Transport adapter

`src/Transport/ConnectedTransport.php` is the application-facing transport contract. `DiscoveryServerTransport` adapts RakNet without exposing application code to socket or reliability internals. `RakNetHandshakeDiagnosticReporter` maps the component's bounded pre-ready metadata into protocol-trace events and never receives packet bodies or authentication data. Transport callbacks produce bounded events; they never mutate simulation state.

## Plugins and commands

`src/Api/` is the immutable public plugin surface. Stable gameplay methods remain protocol-neutral; `Api/Player/PlayerConnection` is the deliberate typed current-protocol output escape hatch and never exposes raw bytes, sockets, encryption, or a mutable runtime session. Custom mobs retain the immutable definition generation which created them, admit their vanilla network appearance through the active entity catalog, and expose the common entity-controller hierarchy for transforms, presentation, health, equipment, AI, movement, targeting, and despawn intent. Those intents share the owner-attributed action transaction and apply only after a successful callback; custom AI callbacks additionally use the ordinary activation cadence and fair AI budget. `Runtime/PlayerConnectionDirectory` resolves immutable player snapshots to their newest live session and invalidates them on disconnect. `src/Plugin/` owns PHAR lifecycle, source-definition admission, failure attribution, owned event, command, and scheduler registrations, and cleanup. `src/Plugin/Scheduler/` owns deterministic tick scheduling and the bounded adapter between class-based plugin computation and the managed plugin-worker capacity; worker results return to the authoritative main thread before plugin completion code runs. Bedriox never scans source-plugin directories; an enabled development provider such as PluginTools performs discovery and submits one bounded definition batch during its PHAR load phase. `src/Plugin/Command/` parses and dispatches bounded console and player command input and polls cooperative plugin jobs without giving plugins runtime ownership. `src/Command/Default/` gives each server-owned default command one focused class; `BuiltinCommandRegistrar` is the central coordinator that constructs and registers that set. `src/Permission/` owns UUID-keyed operator and permission persistence. `BedrockCommandPacketProjector` is the sole projection of those server-owned rules into player ability and available-command packets at login and after effective live changes. Unix-like systems poll `StreamConsoleInput` directly. Windows uses a lifecycle-owned helper process for the blocking console-handle read and polls only an authenticated non-blocking loopback channel, preventing idle operator input from pausing networking or simulation. `ConsoleCommandDriver` composes either input around the existing runtime without changing network or simulation behavior.

## Tests and tools

`tests/` mirrors production ownership. Unit tests stay near a subsystem contract; runtime tests exercise composition; tool tests protect manifests and repository rules. `tools/` validates documentation, licenses, component pins, and the six-repository workspace.

When unsure where a change belongs, choose the lowest owning layer that can express it without depending upward. If that creates a new cross-repository abstraction, write an RFC and obtain acceptance before implementation.
