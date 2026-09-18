# Codebase map

This repository is the executable server and composition root. Code belongs here only when it owns application startup, policy, sessions, authoritative state, worlds, commands, or observability. RakNet wire mechanics belong in RakNet, Bedrock packet codecs in Protocol, and immutable versioned registries in Data.

## Entry and composition

- `bin/bedriox` is the thin executable entry point.
- `src/Application.php` parses the command and delegates server construction and execution.
- `src/Runtime/ServerSettingsFile.php` loads and validates `bedriox.settings`.
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

## Authoritative simulation

`src/Player/` owns the authoritative mutable `Player` aggregate, its authenticated identity, movement state, and the capacity-bounded `PlayerRegistry`. The registry maintains the session and identity indexes together, produces deterministic immutable snapshots, and removes every index as one lifecycle operation. Player objects have no packet, socket, encryption, or transport APIs.

`src/Simulation/` owns deterministic tick processing. Inputs enter as immutable values under `Simulation/Command/`; outputs leave under `Simulation/Event/`. `WorldSimulation` exclusively owns the player registry and is therefore the only layer allowed to mutate live players, while `FixedRateWorldLoop` schedules bounded tick work with monotonic time. `PlayerSnapshot` remains the immutable boundary consumed by runtime packet projection.

Protocol positions and flags are translated before commands reach this layer. Simulation code must not depend on packet IDs, encrypted batches, RakNet sessions, or network runtime block IDs.

## World and blocks

`src/World/` owns world metadata, spawn resolution, generators, chunks, subchunks, and the bounded repository. `World` coordinates the generator and cache. `FlatWorldGenerator` generates deterministic canonical terrain.

`src/World/Block/` owns process-local internal block-state identity. `BlockStateRegistry` resolves canonical `minecraft:*` states; `InternalBlockStateId` is never persisted or sent; `BlockNetworkTranslator` is the explicit boundary to Data's current network palette.

`src/Player/PlayerInventory.php` owns the fixed authoritative main inventory, cursor, selected hotbar slot, and atomic stack-request mutation. `src/Runtime/BedrockInventoryPacketProjector.php` is the only boundary that translates canonical stacks to the current network item and block registries; decoded client stack IDs remain bounded reconciliation intent.

Runtime serialization and view scheduling live under `src/Runtime/` because they compose world state with the active Protocol contract. See [world and chunk pipeline](world-chunk-pipeline.md).

## Transport adapter

`src/Transport/ConnectedTransport.php` is the application-facing transport contract. `DiscoveryServerTransport` adapts RakNet without exposing application code to socket or reliability internals. `RakNetHandshakeDiagnosticReporter` maps the component's bounded pre-ready metadata into protocol-trace events and never receives packet bodies or authentication data. Transport callbacks produce bounded events; they never mutate simulation state.

## Tests and tools

`tests/` mirrors production ownership. Unit tests stay near a subsystem contract; runtime tests exercise composition; tool tests protect manifests and repository rules. `tools/` validates documentation, licenses, component pins, and the six-repository workspace.

When unsure where a change belongs, choose the lowest owning layer that can express it without depending upward. If that creates a new cross-repository abstraction, write an RFC and obtain acceptance before implementation.
