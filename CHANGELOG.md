# Changelog

All notable changes will be documented here. The project follows Semantic Versioning for its PHP APIs; supported Bedrock protocol versions are tracked separately.

## [Unreleased]

### Added

- Add the version-one default overworld generator with domain-warped continents, erosion-shaped mountain ranges and valleys, climate-driven biomes, rivers, deep oceans, slope-aware surfaces, snow and ice, cross-chunk caves, regional ore veins, biome-specific forests, safe spawn selection, bounded regional caching, and deterministic LevelDB persistence while retaining the fixed flat generator.
- Persist and validate the Bedriox generator algorithm version in `level.dat`, failing closed before terrain generation when a stored world requires an unsupported version.
- Add deterministic terrain diagnostics for height and biome maps plus regional distribution, negative-coordinate, generation-order, seam, spawn, and noise-continuity coverage.
- Add native `levelname.txt` creation and stale/missing mirror repair with `level.dat` remaining authoritative.
- Add Mojang-compatible LevelDB world metadata and chunk persistence with canonical block-state palettes, provider-first loading, dirty-revision autosave, save-before-eviction, and graceful close-time flushing.
- Add bounded `level.autosave-interval-ticks` and `chunks.save-per-tick` operator settings with equivalent command-line overrides.
- Add qualified Bedriox PHP Runtime artifacts for Windows x86-64, Linux x86-64/ARM64, and macOS x86-64/ARM64, with exact archive and manifest pins, bounded local installation, rollback, pre-autoload integrity checks, and production launchers that never fall back to system PHP.
- Add a typed, plugin-owned command API with console/player senders, aliases, permissions, sender restrictions, cancellable pre-events, observational post-events, lifecycle cleanup, bounded cooperative jobs, and non-blocking console input, including an isolated Windows console reader that cannot pause networking between commands.
- Add a bounded development-provider admission API while preserving native PHAR-only discovery, allowing PluginTools to own source-folder loading and cleanup.
- Pin the RakNet transport update that keeps transient Windows UDP resets from stopping discovery.
- Add structured colored operator logging in the Bedriox console format, bounded rotating `logs/server.log` output, configurable levels and sinks, and redaction of credential-bearing text.
- Add atomic UTC crash reports with fatal shutdown capture, emergency memory reserve, normalized private paths, bounded recent logs, and configurable inclusion of player identifiers through an injectable crash-context provider.
- Add authoritative player AABB collision against current canonical terrain with vertical/horizontal resolution, wall sliding, bounded stepping, terrain-derived grounding, owner prediction reset, visible-peer reconciliation, and immediate break/place support refresh.
- Add protocol-trace-only, payload-free diagnostics for bounded inventory-request actions and authoritative reconciliation outcomes.
- Add an authoritative `Player` aggregate, separated movement and authenticated identity values, and a capacity-bounded `PlayerRegistry` with deterministic peer snapshots and complete session/identity cleanup.
- Preserve authenticated head yaw plus durable sneaking and sprinting state in authoritative player snapshots, with explicit posture-transition events for peer synchronization.
- Synchronize peer actors with ordered list/add/metadata joins, absolute movement and posture deltas, and actor removal before player-list cleanup.
- Gate peer actor spawn, movement, emotes, hide, and reappearance on chunks actually delivered to each viewer without duplicating player-list membership.
- Document the codebase map, safe-change policy, packet lifecycle, chunk pipeline, permanent client journey, cross-repository workflow, and operational boundaries; contributor rules now freeze established behavior unless a scoped, tested change requires otherwise.
- Add a strict generated `bedriox.settings` operator configuration with typed server, network, level, chunk-streaming, runtime, diagnostic, and optional all-or-none spawn overrides; CLI flags take final precedence.
- Add a deterministic internal flat-world model, bounded shared chunk cache, generic Bedrock serializer, and per-player nearest-first view streaming tied to the configured world tick.
- Add a PMMP-style canonical block-state layer with dense process-local IDs and one explicit translation boundary to the current Bedrock network palette.
- Send the current data-driven block definitions and experiments in StartGame, preserve biome tags, and accept bounded typed block and item-use input without disconnecting the session.
- Generate complete bedrock, dirt, and grass columns on demand across each negotiated view and continuously extend terrain as authoritative movement crosses chunk boundaries.
- Accept bounded own-actor EmoteList notifications before initialization so the retail client can continue its join sequence.
- Make Minecraft 1.26.50 / protocol 2193 the sole wire target, qualify Minecraft 1.26.51 as a same-protocol retail client, and keep session-bound PlayerAuthInput framing.
- Reject unexpected production SubChunk requests because streamed full columns never ask the retail client for separate sections.
- Separate per-world-tick generation and delivery budgets with a bounded serialized staging queue, retained-view accounting, and deterministic cleanup on movement, failure, and disconnect.
- Release player admission only after the configured spawn-radius terrain has been delivered and the valid initialization acknowledgement arrives.
- Treat repeated initialization acknowledgements for the authenticated player as idempotent while continuing to reject mismatched entity IDs.
- Apply bounded protocol-2193 Take, Place, and Swap stack requests from both dedicated and PlayerAuthInput forms to the authoritative main inventory and cursor, with atomic validation, fresh server stack IDs, success responses, and full correction after rejection.
- Route initialized own-actor emotes through an immutable, UUID-validated and five-tick-rate-limited simulation command, then relay an authoritative server-side muted-chat notification only to peers.
- Route validated fixed-flat block actions through the simulation, advertise the 18-tick empty-hand grass progress rate, revalidate predicted completion against authoritative state, mutate the world, and synchronize crack and block updates only to sessions that received the affected chunk.
- Open the retail player's main inventory with one bounded session-local window, echo its matching close, and permit a later reopen without trusting the client-supplied actor target.
- Seed each authoritative player inventory with one 64-count grass stack, synchronize the same stack in bootstrap content and equipment, and support validated six-face grass placement with owner inventory correction and multiplayer block updates.

### Fixed

- Restore a returning player's saved camera direction with an authoritative post-initialization movement reset, ignoring stale movement bundled with the initialization acknowledgement.
- Bind every packaged launch and staged-runtime probe to Runtime's relocatable OpenSSL provider configuration, and qualify the exact Bedrock P-384 key path before world access or UDP bind.
- Create an external Runtime OPcache directory from packaged launchers and staged probes so Windows ASLR fallback cannot make an otherwise qualified server unstartable.
- Accept the protocol-2193 hotbar-to-cursor container pair used by retail authoritative move and split requests.
- Advertise the authoritative inventory system during StartGame so retail inventory moves and splits use validated item-stack requests instead of immediately reverted legacy predictions.
- Reconcile both dedicated/embedded item-stack requests and bounded legacy packet-30 inventory predictions through server-owned inventory state; preserve same-tick embedded actions, validate predicted counts and stack IDs atomically, synchronize changed slots with fresh server IDs, and keep split destination stacks usable for later placement.
- Revalidate predicted grass-break completion against current authoritative reach and world state without rejecting it solely because client/server tick boundaries differ; accept continuous-mining targets through the same server-owned break path.
- Make grass placement fully server-authoritative: apply the transaction's bounded hotbar selection before placement, derive the placed block and decrement from the server-owned stack, and treat client item, count, stack-network, runtime-ID, and prediction fields only as non-authoritative reconciliation hints.
- Synchronize every authoritative selected-stack count change to visible peers so placing from a split hotbar stack cannot leave stale held equipment.
- Normalize the retail targetless abort-break sentinel before creating a simulation command so stopping a break clears server state without validating invented coordinates or a non-semantic face.
- Defer the latest bounded, self-owned hotbar notification received during initialization and apply it only after admission instead of disconnecting retail clients that echo server equipment before `SetLocalPlayerAsInitialized`.
- Preserve process-local block changes across chunk-cache eviction and serialize staged chunks from the latest authoritative state at send time instead of sending stale pre-mutation bytes.
- Follow the retail peer-introduction order with `PlayerList`, `AddPlayer`, then `PlayerSkin`; omit redundant same-tick movement after a first introduction and project the privacy-safe unknown build platform consistently.
- Send each visible player's complete initialized actor metadata inside `AddPlayer` so a second player's spawn is atomic for retail clients.
- Decode and authenticate normal packet-93 player skin synchronization after peer introduction instead of disconnecting spawned multiplayer sessions.
- Accept state-bound RakNet final acknowledgements without parsing client-varying unused address/timing bodies, and isolate malformed pending peers while retaining strict endpoint, state, reliability, ordering, and MTU checks.
- Isolate world-event encoding and fan-out budget failures to their causal session so healthy multiplayer recipients remain connected.
- Send the current collision and affected-by-gravity actor flag indexes so survival clients remain grounded instead of hovering above flat terrain.
- Accept the canonical zero-count empty MobEquipment descriptor emitted by retail Minecraft 1.26.51 so opening or changing the empty hotbar cannot terminate an initialized session.
- Route valid packet-30 and embedded item-use placement through server-owned inventory and world state after play initialization; unrelated typed transaction forms remain bounded notification-only input, while malformed and premature forms still fail closed.
- Derive all private component checkout refs from `bedriox.lock.json` and validate the CI workflow so duplicated stale commit hashes cannot break a previously valid integration pin.
- Advertise the joinable retail discovery value `Survival;1` instead of the rejected `Survival;0` combination.
- Keep completed chunk views idle after their pending queues drain, including while recentering repeatedly across chunk boundaries.
- Reject client flight toggles and ability escalation with normalized survival abilities while preserving same-frame movement and jump input; accept retail main-inventory close aliases and empty server-settings requests without disconnecting play.

### Changed

- Pin RakNet commit `aa84f71169f6401029b070af35ecb3f85b7fcac4` and surface its bounded pre-ready rejection metadata only through protocol-trace diagnostics.
- Move Bedrock discovery advertisement semantics into Protocol while RakNet transports only the bounded opaque payload and accepting-connections policy, preserving the qualified `Survival;1` wire output.
- Keep commit messages free of personal-email sign-off trailers.

- Initial private engineering scaffold.
- Project branding, licensing, governance, documentation, and quality configuration.
- Add the bounded protocol-975 pre-spawn login state machine, explicit authentication policy, encryption transition effects, and narrow RakNet payload boundary.
- Add bounded protocol-975 FULL Token verification with injected trusted JWK and clock boundaries, strict RS256/claim validation, P-384 client-key binding, and verified client-data results.
- Add bounded HTTPS Minecraft discovery and atomic JWKS caching with strict endpoint policy, rotation refresh, and fail-closed hard-stale handling.
- Bound failed discovery refreshes with capped exponential backoff and make cURL handle cleanup compatible with PHP 8.5.
- Add an explicit insecure self-signed login adapter with single-certificate and client-data proof verification, injected wall-clock validation, and strict no-downgrade mode and envelope guards.
- Harden private component manifest validation across exact repository and lock metadata with adversarial path, mapping, pin, and support-claim regressions.
- Add a deterministic bounded 20 TPS world domain with authenticated player joins, peer visibility events, authoritative movement correction, attributed rate-limited chat, clean disconnects, replay tests, and a 100-player model stress cycle.
- Add bounded RakNet-to-login-to-play runtime orchestration, exact cipher ownership transfer, initialization-gated world joins, protocol-975 gameplay routing, authoritative event projection, per-session failure isolation, and idempotent shutdown.
- Add an experimental current-Bedrock runtime target with isolated component codecs and an explicit registry view; retail qualification remains pending.
- Add a fail-closed `serve` CLI, eager data/authentication startup checks, an exact 89-packet fixed-flat spawn sequence with radius-four coverage, serverbound PlayerAuthInput movement, and graceful signal handling where supported.
- Consume RakNet alpha.6 so retail Bedrock clients can complete connected setup when their New Incoming packet refreshes relative timing fields.
- Accept the optional one-time client-cache status during retail login while retaining non-cache chunk delivery.
- Map verified opaque FULL-token subjects to stable RFC 4122 UUIDv5 player identities for Bedrock wire packets and world admission.
- Align world admission by preserving the client skin profile hash, advertising only implemented actor types, pruning obsolete biome data, and using conservative v9/V2 chunk storage.
- Align the join bootstrap with verified Nukkit wire behavior by correcting empty item instances, StartGame signed spawn coordinates and permission/default fields, player-spawn type, and by omitting the virtual UI inventory snapshot while retaining player, armor, and offhand state.
- Isolate malformed play sessions, accept recognized interaction and animation notifications as safe no-ops, project accepted movement only to peers, and emit bounded structured runtime failure diagnostics without packet or credential contents.
- Replace protocol-numbered current-version classes and namespaces with stable `Bedrock` APIs; the server continues to support one explicitly pinned Bedrock version at a time.
- Add authoritative fixed-flat prediction validation with coalesced movement frames, grounded and airborne state, grounded-only jump transitions, collision-safe landing, tick-credit correction, unsigned client-tick ordering, and authoritative clientbound reset and ground state.
- Accept a narrow allowlist of bounded routine play notifications as phase-aware no-ops, validate entity-scoped notices, and retain session-local fail-closed handling for malformed and unknown packets.

### Changed

- Reduce the fixed-flat MVP radius from four (81 chunks) to one (9 chunks) to bound join latency; larger streamed radii remain a later world-system milestone.

### Security

- Prevent unknown or canceled pre-join disconnect requests from consuming the simulation lifecycle queue reserved for active-player cleanup.
