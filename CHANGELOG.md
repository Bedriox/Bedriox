# Changelog

- Add authoritative aquatic entities with three-dimensional water movement,
  bounded natural populations, air and dry survival, bucket capture and
  release, breeding, persistence, normalized drops, and plugin-visible state.
- Align aquatic actor rotation with authoritative velocity, retain smooth
  water-valid swim headings, and give amphibious mobs distinct land steering.
- Keep aquatic hostiles roaming when idle, make ordinary land-mob AI avoid
  entering water, and apply air depletion and drowning damage after submersion.
- Improve unknown-command feedback with a direct `/help` hint.

- Add dedicated common hostile families with exact public species contracts, bounded climbing, ranged attacks, creeper explosions, slime sizes and splitting, special effects, natural spawning, loot, metadata, and durable species state.
- Centralize current enchantment definitions and apply authoritative combat, armor, mining, durability, breathing, movement, loot, Mending, mace, bow, and crossbow behavior from canonical item state.
- Stream flying projectile positions with one-tick interpolation, keep embedded arrows visible at their impact point, preserve local-player input ticks only for their own knockback, and fully reintroduce extinguished players to nearby viewers after respawn.
- Resolve melee and projectile knockback once, expose cancellable typed knockback and projectile lifecycle events, and preserve server-owned damage, inventory, ammunition, and drop authority.
- Let block-break listeners replace the computed Silk Touch and Fortune drop list before commit, with the final list available to post-event observers.
- Preserve unchanged workstation stack network identities across live processing updates so furnace input, fuel, and output remain removable while cooking.
- Project furnace-family lit block states while fuel is burning and restore their unlit states when processing stops.
- Return complete named anvil-slot responses for rename previews and send bounded non-empty enchanting-table display tokens.
- Accept action-only anvil updates with no output recipe, acknowledge the current workstation slots, and return temporary inputs through targeted inventory synchronization without disconnecting the player.
- Build the startup item catalog from the complete block-item catalog so campfires, stonecutters, cauldrons, and brewing stands retain their authoritative placement states.
- Migrate block translation from positional palette indexes to Data's verified explicit network hashes, keep one signed identity in memory, advertise hash mode at StartGame, and project signed or unsigned wire values only at Protocol packet boundaries.
- Distinguish player, mob, and source-less lethal attacks when presenting death messages so a missing attacker can never be displayed as the victim.
- Sustain the qualified 100-player workload above 18 TPS by moving RakNet service ownership to a supervised local transport process, bounding main-loop admission and streaming work, and preserving per-session ordering and encryption boundaries.
- Reuse immutable chat, combat, posture, player-movement, and non-player movement projections across compatible recipients so fan-out does not repeat packet encoding and compression for every player.
- Move routine world metadata and entity persistence off the authoritative loop, commit entity ownership changes as atomic one-entity deltas, and defer conflicting routine eviction without losing unrelated durable actors.
- Allow the complete local quality gate up to twenty minutes so the expanded deterministic test suite can finish under Composer instead of being terminated by its five-minute default.
- Preserve authoritative zombie knockback against same-tick chase steering, let grounded mobs jump clear one-block rises without crossing two-block walls, and lower natural hostile density to a 24-entity regional cap with bounded two-at-a-time spawning and excess retirement.
- Gate natural hostile spawning by darkness, ignite exposed daylight-sensitive mobs with visible authoritative fire damage, persist bounded fire state, and scale category caps across deduplicated player spawn regions with local-density and soft-despawn control.
- Batch each client's visible non-player movement projection, omit unchanged motion packets, and aggregate repetitive actor trace output so mob movement does not create one compression job and log record per packet.
- Drain entity autosaves as finite generations, checkpoint age without per-tick dirty churn, bound atomic chunk-ownership transfers, and reduce distant grounded-mob physics while preserving continuous motion.
- Keep non-player physics and hostile line-of-sight checks on already loaded terrain, fail closed at missing chunk boundaries, apply entity-use hotbar selection before interaction, and project hostile melee knockback and attack state only for damageable visible targets.
- Project authoritative non-player living actors through complete spawn, movement, health and hurt, death, and removal packet sequences for the event-supplied chunk-visible recipients.
- Preserve authoritative armor and offhand snapshots through runtime recipient filtering so successful equipment transactions cannot be projected as empty equipment.
- Accept the current eating actor advisory without trusting it for item use, preventing ordinary and golden food consumption from disconnecting the player.
- Synchronize only the main-inventory slots changed by item consumption and `give` instead of refreshing every slot.
- Honor legacy requested-slot corrections without escalating successful player drops into a full inventory refresh.
- Reconcile mining stack predictions against the authoritative post-break tool state and correct only the affected hotbar slot instead of refreshing the complete inventory.
- Apply vanilla sprint exhaustion from accepted horizontal movement while retaining cancellable authoritative nutrition events.
- Add bounded plugin item-use, consumption, nutrition, equipment, armor,
  offhand, durability, and item-break values and events, including owner-scoped
  data-only item behavior registration and natural-regeneration events.
- Synchronize dropped-item falls with absolute actor positions and bounded motion updates so clients do not interpret server uptime as movement interpolation duration.
- Derive supported block-item placement states from block definitions and settle dropped items against their quarter-block collision body instead of snapping them above the floor.
- Add protocol-trace diagnostics for item-stack request decode failures without logging raw item or packet data.
- Treat obtained cobblestone and cobbled deepslate as placeable block items, including their authoritative block definitions.
- Preserve canonical block identity on cobblestone and other block-item drops so client rendering uses the correct block runtime state.
- Accept bounded creative craft-results advisories after authoritative creative selection without trusting client-reported results.
- Restore block-specific destruction particles through chunk-recipient filtering and send bounded face-specific particles while mining.
All notable changes will be documented here. The project follows Semantic Versioning for its PHP APIs; supported Bedrock protocol versions are tracked separately.

## [Unreleased]

### Fixed

- Fetch public installation artifacts from the Bedriox website while keeping
  source repositories private, and verify launcher checksums alongside the PHAR
  and Runtime before activation.
- Stage same-dimension world transfers behind destination terrain preparation and an ordered source-output fence, then synchronize destination metadata, teleport the owner, publish the new center, and stream only destination chunks without invoking a dimension loading screen.
- Keep the global plugin scheduler on a server-owned monotonic tick timeline when additional worlds are loaded, preventing independent world tick counters from crashing the runtime.
- Keep natural spawning bounded and fair through the full 1,024-player server limit, account candidate preparation against its elapsed budget, and preserve natural-distance despawn ownership across entity save, unload, and restart without affecting command, spawn-egg, or plugin entities.
- Encode peer arm swings without an optional source label and report allowlisted RakNet send-overflow categories with bounded payload, reliability, ordering-channel, and session-phase context.
- Admit every data-mapped creative block to authoritative break handling so crafting tables and other placed blocks no longer revert when mined.
- Keep the world spawn retained and wait for every chunk touched by the player collision footprint before committing a respawn, preventing persistence backlogs from turning respawn into a blocking storage read and whole-server disconnect.
- Give every block emitted by the default generator an admitted gameplay definition and collision shape, allow generated vegetation to be broken normally, and correct truly unknown block predictions without terminating the server.
- Treat grasses, flowers, mushrooms, vines, aquatic plants, rails, and torches as non-colliding while applying bounded partial geometry to snow layers, paths, farmland, cactus, bamboo, panes, bars, lanterns, dripstone, mud, and soul sand for both players and dropped items.
- Anchor structures and vegetation to the captured pre-decoration terrain surface, populate structures before vegetation, and require valid substrates and replaceable volumes so village foundations cannot be placed on tree canopies.
- Translate every generated biome through the complete canonical `LevelChunk` runtime-ID registry, preventing mixed-biome chunks from disconnecting players when definition order overlaps a vanilla biome ID.
- Reject attempts to merge one-slot tools in catalog-free inventories without throwing or changing either slot.

### Added

- Add authoritative vehicle and passenger relationships, adult saddled-pig riding and carrot-on-a-stick steering, safe lifecycle dismounts, multiplayer actor-link synchronization, and typed plugin APIs and events.
- Add authoritative cow, sheep, pig, chicken, and rabbit age, temptation,
  breeding, baby growth, interaction, loot, natural spawning, persistence,
  metadata, controllers, and typed breeding events with organized entity API
  namespaces.
- Add authoritative sheep and skeleton gameplay with species APIs, natural spawning, persistence, sheep interaction and breeding, ranged skeleton combat, typed target and shear events, and entity-owned projectile attribution.
- Add reproducible standalone PHAR packaging with locked production vendors, verified extraction, adjacent Runtime validation, release launchers, and SHA-256 checksums.
- Add an atomic first-run setup wizard, persistent whitelist admission, operator controls, a public whitelist API, typed whitelist changes, and explicit kick causes.
- Add cross-platform public installer scripts for the released `Bedriox.phar`
  and qualified PHP Runtime, with platform detection, SHA-256 verification,
  staged activation, existing-directory protection, and optional deferred
  startup.
- Add authoritative furnace, blast-furnace, smoker, campfire, stonecutter, smithing, anvil, grindstone, enchanting, loom, cartography, composter, and cauldron processing with persistent station state, bounded active scheduling, experience rewards and costs, typed plugin events, and atomic inventory reconciliation.
- Add authoritative persisted player experience, derived level and progress projection, typed plugin events and API mutations, and the operator-only `/experience` command with `/xp` alias.
- Add bounded loaded-chunk water and lava flow, source decay and renewal, fluid hardening, authoritative bucket fill/empty behavior, and flowing-fluid immersion.
- Add persistent per-world clear, rain, and thunder cycles, join/transfer synchronization, typed cancellable plugin events, rain extinguishing, public world weather control, and the operator `weather` command.
- Add typed player and living-entity effect snapshots, generation-bound player mutations, cancellable pre-events, committed post-events, bounded durable fallback state, deterministic periodic timing, client effect synchronization, air and fire persistence, and authoritative movement, mining, combat, health, nutrition, breathing, combustion, health-boost, absorption, and visibility behavior.
- Add typed, bounded world particle requests covering the current named effect catalog and data-backed color, block, item, scale, direction, and size families, with optional audiences delivered only after recipients receive the containing chunk.
- Introduce plugin API 0.3 with global discovery on `Server`, session-bound authoritative actions on `Player`, generation-bound block access on `World`, and world-explicit plugin container lookup.
- Add canonical multi-world lifecycle management with named LevelDB worlds, generation-stable public handles, world-aware positions, persisted cross-world player teleportation, per-world simulation and chunk ownership, typed lifecycle events, and owner-scoped plugin terrain generators.
- Add built-in default, flat, and void generator registration with deterministic persisted options, worker-backed built-in generation, and a bounded main-thread execution path for plugin-defined generators.
- Add a public entity-controller hierarchy for transactional transforms, presentation state, health, fire, equipment, AI, movement, targeting, and despawn intent, with cancellable equipment transitions and committed post-events.
- Add durable living-entity armor and hand equipment, difficulty-aware natural zombie gear, authoritative armor wear and melee effects, complete spawn and live projection, and bounded once-evaluated zombie, cow, and equipment death drops customizable through `EntityDeathEvent`.
- Add bounded player/entity target selectors, public entity command parameters, typed entity damage causes, and an authoritative `kill` command with separate self and other permissions.
- Add per-world bounded daylight-cycle time with persisted progression, current-time login synchronization, periodic client correction, authoritative natural-spawn lighting, typed presets, and the operator `time` command.
- Add transaction-isolated custom-mob lifecycle callbacks, catalog-validated vanilla appearances, generation-stable live behavior and state codecs, bounded movement control intents, scheduler-cadenced custom AI, and symmetric persistence load/unload events.

- Add durable chest, trapped-chest, barrel, shulker-box, and player-owned Ender Chest inventories with authoritative dynamic-window transactions, current affected-slot responses, multiplayer synchronization, live block-actor state, first/last-viewer animation, shulker item preservation, typed lifecycle events, and plugin-owned virtual containers.

- Broadcast visibility-scoped arm swings for missed attacks, mining, combat, and plugin requests, with a cancellable missed-swing event.
- Add server-authoritative personal and crafting-table grids, the complete current crafting-grid catalog, ordinary and automatic recipe requests, dynamic special recipes, atomic inventory rollback, and clean recipe synchronization for connected players.
- Add owner-scoped plugin shaped and shapeless recipe registration plus cancellable pre-craft and observational post-craft events with lifecycle cleanup.
- Model durability for non-tool damageable items so repair crafting covers bows, shields, elytra, fishing rods, tridents, crossbows, and the other admitted damageable item families.
- Admit every item definition and block-item mapping from the active Data release, project its complete ordered creative catalog with stable variant IDs, and preserve auxiliary, NBT, and block-state variants through authoritative selection and persistence without claiming unsupported item-specific mechanics.
- Add plugin-owned named command soft enums with bounded live updates, lifecycle cleanup, authoritative binding, and item-catalog suggestions for `give`.
- Replace the pre-alpha closure and raw-argument command API with class-based `Command` and `AbstractCommand` definitions, fluent typed argument schemas and overloads, generated usage, typed `CommandValues`, Bedrock enum autocomplete with live connected-player updates, and result messages.
- Preserve validated X-Z-Y palette word arrays in authoritative block and biome storages, transfer compact revision-specific projection snapshots, and write packed chunk words directly in workers while translating each canonical palette only once.
- Move complete revision-specific chunk packet projection, framing, and compression to managed workers; deduplicate and cache compressed clear envelopes across players; invalidate stale work on immutable revision changes; and enforce count, byte, and elapsed-time streaming budgets without placing unfinished chunks ahead of chat or control traffic.
- Report prepared-chunk cache entries, bytes, pending work, hits, misses, evictions, invalidations, failures, and hit ratio in `status advanced`, and include bounded worker completion polling in runtime-poll latency measurements.

- Split common `server.properties` from advanced `bedriox.settings`, preserve strict layered CLI precedence, and apply a verified 500 MB default main-process memory limit before startup services.

- Add visible-first hidden chunk prefetching, bounded generation admission, prefetch-aware cache sizing, and live cache, streaming, persistence, scheduler, and worker-memory status metrics.

- Add managed core and plugin worker pools, asynchronous chunk generation and outbound compression, deterministic plugin scheduling, background file logging, performance telemetry, and operator-only basic and advanced `status` views.

- Add RFC 0023's checksummed canonical chunk-transfer format and deterministic flat/default generation worker handler while retaining authoritative world ownership in the server process.

- Add deterministic plugin-owned next-tick, delayed, repeating, and delayed-repeating scheduling with bounded dispatch, lifecycle cleanup, failure attribution, and class-based bounded async task lifecycle callbacks.

- Add bounded typed custom item NBT, schema-four player persistence, Bedrock item-extra-data projection, PMMP-aligned tool wear for mining and combat, and live plugin-owned item stack/creative definitions.

- Add a plugin-facing, cancellable `Player::kick()` with separate reason, optional quit announcement, and disconnect-screen message. Duplicate account logins now receive a visible encrypted reason while the existing player remains connected.

- Add survival, creative, adventure, and spectator authority with persistent game mode, ability projection, spectator visibility, cancellable plugin events, and the `gamemode` command.
- Add canonical gameplay catalogs for admitted blocks, items, tool tiers, hardness, break timing, durability, and deterministic drops, plus data-driven creative content and authoritative creative stack requests.
- Add bounded dropped-item actors with motion, terrain settling, pickup delay, partial-inventory remainder replacement, despawn, late-join visibility, pickup events, and overflow-safe `give` command support.
- Add authoritative player item dropping for both current Bedrock request forms, cancellable drop events, inventory reconciliation, and projected dropped-item actors.
- Correct creative inventory group indexes, canonical item extra-data, and the dynamic main-inventory open/synchronize/close conversation.
- Add block-specific destroy particles, placement sounds, and server-held-tool break progress while keeping client item descriptors non-authoritative.
- Add complete current-protocol text, title, action-bar, popup, tip, toast, and translated-message player APIs plus a trusted typed `PlayerConnection::sendPacket()` escape hatch with normal and immediate delivery modes.
- Add modern authoritative PvP motion composition with exact client-tick projection, grounded and airborne vertical behavior, sprint-hit reconciliation, localized player/fall/generic death chat, and independently customizable death-screen messages.
- Add a bounded public `TranslatableMessage` value and a single mutable `PlayerDeathEvent` carrying the victim, optional killer, cause, final incoming damage, and nullable chat/screen presentation.
- Add server-authoritative player-versus-player combat with a single `pvp` setting, PMMP-aligned reach, hurt cooldown and knockback, typed plugin attack events, and visibility-scoped health, animation, motion, death, and respawn synchronization.
- Add Bedrock player slash-command admission and feedback, server-owned `version`, `help`, `list`, `stop`, `op`, `deop`, and `permission` commands, and atomic UUID-keyed operator and permission persistence.
- Advertise only commands available to each joining player through the complete current command packet conversation, with client-provided command identity rebound to the authenticated session.
- Refresh a connected player's typed ability authority and available command list immediately after an effective operator or permission change.
- Add a public `TextFormat` API covering every current Bedrock color and supported style, including Minecoin Gold and Quartz-through-Resin material colors.
- Add one read-only build-information view for server, Minecraft, protocol, plugin API, and PHP versions.
- Add authoritative player health, PMMP-aligned fall damage, cancellable damage and respawn plugin events, death-state gameplay isolation, complete retail respawn packet handling, 60-tick respawn protection, inventory-retaining respawn synchronization, and schema-two health persistence with schema-one migration.
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

- Include the target player's exact latest client input tick in PvP knockback packets so protocol-2193 clients can decode authoritative actor motion.
- Resolve player command senders through their authenticated UUID so spawned players execute advertised commands instead of receiving a premature availability response.
- Encode current command origins with their string tag and unconditional signed actor ID, preserve authenticated player correlation through dispatch, and echo that complete origin in command output.
- Accept the current serverbound movement-prediction sync notification as validated advisory data without importing client-reported movement, collision, ability, health, or hunger state, preventing a legitimate packet 322 from disconnecting a spawned player.
- Preserve the full unsigned `PlayerAuthInput` tick through authoritative simulation, advertise a bounded 40-tick rewind history by default, and use the current prediction-correction packet for routine movement reconciliation while keeping teleport and respawn movement on their lifecycle path.
- Restore a returning player's saved camera direction through `StartGame` without a second post-initialization movement reset, and normalize gameplay rotation before it enters the simulation.
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

- Present `/list` and `/version` as concise, separately colored player-facing messages while retaining plain console output.
- Keep each server-owned default command in its own class while retaining `BuiltinCommandRegistrar` as the central registration coordinator.

- Name the server composition root `Bedriox` and make its product version the shared authority for CLI output, crash reports, and integration validation.

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
