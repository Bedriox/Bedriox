# Operations

Bedriox keeps authoritative gameplay in one bounded PHP process. That process owns configuration, startup validation, UDP binding, the non-blocking runtime loop, diagnostics, signal handling, and graceful cleanup. Supervised local PHP processes handle admitted CPU work, plugin asynchronous tasks, routine file logging, and ordered storage without receiving live gameplay or network-session ownership.

## Starting the server

From the repository root:

```shell
composer install
composer check
php tools/install-runtime.php path/to/the-locked-runtime-archive
```

Start production with `bedriox.cmd serve` on Windows or `./bedriox serve` on
Linux and macOS. Direct `php bin/bedriox serve` remains a development command.
The packaged launchers validate the adjacent PHP binary, configuration,
platform ABI, exact extension set, manifest hash, and complete runtime file
inventory before Composer autoload executes.
They set `OPENSSL_CONF` to the adjacent Runtime's `config/openssl.cnf` and do
not inherit a provider configuration from a system PHP installation.
They keep disposable application extraction and OPcache files outside the
server directory in the current user's operating-system cache. Windows uses
`%LOCALAPPDATA%\Bedriox\Cache`, Linux uses `$XDG_CACHE_HOME/bedriox` or
`~/.cache/bedriox`, and macOS uses `~/Library/Caches/Bedriox`. Set
`BEDRIOX_CACHE_DIR` before launch to select another absolute location. The
cache contains no worlds, player data, configuration, plugins, or logs and may
be deleted while every Bedriox process is stopped. This layout also allows
Windows OPcache to fall back safely when ASLR prevents shared-memory attachment
without changing immutable files in `bin` or cluttering the server directory.

The first successful configuration load creates user-facing `server.properties` and advanced `bedriox.settings` in the current working directory. Built-in defaults load first, followed by `server.properties`, `bedriox.settings`, and explicit command-line overrides. The files are never migrated automatically. Invalid, duplicate, unknown, partial, or out-of-range settings fail before the configured main-process memory limit is applied and before socket bind.

The default game port is UDP `19132`. Bedriox does not currently provide a separate query service or query port; Bedrock server-list discovery uses the game port. FULL authentication is the default and must complete trusted-key discovery before bind. `SELF_SIGNED` is an explicit isolated-development mode and is never a fallback.

## Startup sequence

Before accepting clients, startup verifies the locked packaged Runtime before
Composer autoload, qualifies P-384 key generation through its packaged OpenSSL
configuration, then verifies configuration, the pinned Data artifacts,
canonical block states, translation availability, authentication dependencies,
protocol authority, and transport construction. A failure leaves no partially
running server and never falls back to ambient PHP.

After bind, the runtime polls bounded amounts of socket, session, packet, command, chunk-streaming, persistence, and simulation work. Scheduled world autosave is limited by `chunk-saving.per-tick`; `level.autosave-interval-ticks` controls how often that bounded work is scheduled. Released chunk leases enter a grace queue and are examined under count and elapsed-time budgets. Clean chunks unload directly; dirty chunks remain resident until the exact submitted revision is durably acknowledged.

## Diagnostics

Normal server activity is rendered in a consistent operator format and is mirrored without terminal color codes to `logs/server.log`:

```text
[17-Sep-2026 21:42:10] Bedriox INFO > Starting Bedriox 1.0.0-beta.1
```

`logging.level` accepts `DEBUG`, `INFO`, `NOTICE`, `WARNING`, `ERROR`, or `CRITICAL`. Console and file output may be enabled independently. `logging.console-colors` accepts `auto`, `true`, or `false`; `auto` colors only an interactive terminal. The file rotates into `logs/archive/` at `logging.file-max-size` and retains at most `logging.file-history` archives. Logging failures are contained and never alter simulation state.

Routine file output is sent to one bounded background writer. Console output remains immediate, and crash reports retain their independent synchronous fallback. Queue saturation may discard low-severity file records and emits a rate-limited console warning; `ERROR` and `CRITICAL` records use reserved capacity. A clean stop drains and closes the writer within a bounded deadline.

## Workers and performance status

`workers.core-count=auto` selects a bounded number of local PHP worker processes. An integer from `0` through `32` overrides it; `0` is the rollback mode and keeps the characterized synchronous fallbacks. Workers use the same PHP executable, configuration, extensions, and Bedriox installation as the parent. They communicate only over authenticated `127.0.0.1` streams created before the public RakNet listener binds. No additional PHP extension is required.

`entities.ai.enabled=true` enables the bounded mob AI scheduler. Setting it to `false` preserves entity spawning, physics, persistence, damage, and packet projection while marking mobs as having no AI.

`spawn-animals=true` and `spawn-monsters=true` are user-facing `server.properties` controls for natural spawning. Spawn eggs, `/summon`, and plugin-created entities remain available when either natural-spawn category is disabled. Peaceful difficulty prevents natural monster spawning regardless of the monster setting. Candidate planning rotates through the configured player population within fixed per-pass candidate, attempt, spawn, and elapsed-time budgets. Candidates sample loaded vertical columns so eligible cave, water, and dimension-specific habitats are considered instead of only the highest surface. Species rules use exact dimension and biome identities; structure-owned populations such as guardians, cave spiders, and wither skeletons remain closed until the candidate can prove ownership by the required structure. Category caps scale with deduplicated eligible player spawn regions instead of applying one fixed whole-world count; the hostile local-density limit remains four per candidate chunk. Naturally owned hostile mobs beyond 32 blocks have a bounded randomized despawn opportunity, while the deterministic hard-distance boundary remains 128 blocks. With no eligible players, spawning stops and distance cleanup defers to normal chunk unloading so persisted actors can become dormant instead of being deleted. Natural-distance ownership is persisted with each actor; explicit command, spawn-egg, and plugin origins are never adopted by that cleanup policy after a chunk reload or restart.

Core workers handle missing-chunk generation, complete revision-specific chunk packet preparation, and sufficiently large non-chunk outbound batch compression. Chunk preparation transfers canonical palettes with pre-packed word arrays, translates each palette once, and performs `LevelChunk` framing and zlib compression without expanding every block and biome cell. Compatible sessions share one bounded in-flight request and compressed clear-envelope cache; only revision validation, session encryption, ordering, and transport remain on the server process. Plugin asynchronous tasks use a separate worker pool so plugin work cannot consume core capacity.

Fan-out reuses immutable packet and frame objects for identical chat, combat, posture, and non-player movement projections. Compatible sessions can therefore share one bounded clear compressed envelope instead of repeating packet encoding and zlib work for every recipient. Session encryption counters, delivery ordering, congestion state, and RakNet ownership remain private to each connection and never cross the reuse boundary.

The main loop collects no more than eight core completions or five milliseconds of completion work per poll. Chunk preparation admits no more than the configured send count, 4 MiB of immutable snapshots, or five milliseconds per world tick. Ready delivery likewise obeys the configured count plus a 512 KiB compressed-byte and five-millisecond boundary. A single item may cross a time or byte boundary to guarantee progress; a burst cannot drain an unbounded completion or delivery queue in one tick.

Each active named world has one ordered storage process which exclusively owns its LevelDB handle for the Overworld, Nether, and End. Dimension-qualified chunk and entity requests share that owner while retaining separate runtime caches, simulations, generation, and visibility. The server submits immutable canonical chunk revisions and clears dirty state only after the matching world, dimension, coordinate, and revision are acknowledged. Chunk reads are deduplicated and polled; generation begins only after storage confirms that the coordinate is missing. Player login performs a bounded profile read and restores its saved world and dimension before initial terrain is selected, while routine player saves use the same nonblocking revision-and-acknowledgement model through a separate ordered owner. Clean shutdown stops gameplay, drains final world and player revisions, closes each shared world owner exactly once, and only then finishes process cleanup. A corrupt, unsupported, unreadable, or timed-out record is never treated as missing and is never silently regenerated.

Entity chunk saves and chunk-ownership transfers follow the same asynchronous acknowledgement rule. An ownership transfer commits only the moving entity as an atomic source-and-destination delta against the current durable records; unrelated entities are preserved even when an older in-memory snapshot completes later. A routine eviction that observes a genuine compare-and-swap conflict defers that coordinate without discarding its live owner or resident chunk.

Operators with `bedriox.command.status` can run `status` from the console or in game for a concise PMMP-inspired overview of the server version, uptime, players, current and average TPS/MSPT, memory, and sampled network rates. Dashed headers keep the output readable, and metrics that have not been sampled are omitted from this basic view.

Run `status advanced` for the complete grouped diagnostic view; `status advance` remains an accepted compatibility alias. The advanced view adds minimum TPS, MSPT percentiles, tick utilization and sample count, runtime polling, world/entity totals, memory-pressure classification, adaptive GC results, chunk-unload progress, authoritative chunk-cache retention, prepared-envelope entries/bytes/pending work/hit ratio/invalidation/failure counters, visible/prefetch/generation/delivery queue depth, worker queue/outcome and per-process memory reports, plugin scheduler pressure, world/player persistence pressure, background-log health, ten-second receive/send payload rates, and exclusive recent cost for every instrumented runtime subsystem. It reports disabled, offline, awaiting-report, and unavailable states separately whenever the underlying snapshot can prove that distinction, and never presents an uncollected subsystem cost as zero. Runtime polling includes bounded worker-completion and background-service polling rather than hiding that work outside the sample. Tick statistics and subsystem costs come from the latest immutable completed-tick snapshot; an in-progress tick is never exposed. The monitor retains exactly 1,200 completed ticks and uses nearest-rank percentiles.

The memory manager classifies allocated main-process memory using the configured soft, high, and critical thresholds with hysteresis. Pressure transitions trim disposable prepared envelopes, accelerate safe chunk unloading, run PHP's cyclic collector, and release allocator caches at high pressure. An emergency reserve is released only at critical pressure so diagnostics and orderly cleanup retain working space. Use `gc status`, `gc run`, and `gc chunks` for operator inspection and bounded manual maintenance.

If worker startup fails, Bedriox fails before advertising a joinable server. If a task is rejected or fails after startup, its owning subsystem applies its documented bounded fallback or isolates the affected work. For diagnosis, enable `logging.protocol-trace=true`, capture `status advanced`, and review `logs/server.log` and the local crash report.

## Verified capacity baseline

The current development build completed a controlled five-minute local Windows qualification with 100 independently encrypted `SELF_SIGNED` sessions in four 25-client load drivers. Each client maintained a view distance of eight chunks, sent movement at 20 Hz with randomized sprinting, jumping, and turning, and sent chat every ten seconds. The 251 full-population samples after a ten-second stabilization window averaged 19.854 TPS, reached a minimum of 18.389 TPS, and contained no sample below 18 TPS. Average MSPT was 30.803, p95 MSPT was 35.333, and maximum MSPT was 44.350.

The workload completed 511,491 movement actions and 2,774 chat actions with zero connection failures or unexpected disconnects. Peak main-process allocated memory was 380 MiB. The observed complete 19-process group used approximately 940 MiB of working memory and 672 MiB of private memory during full load, within the 2 GiB configured server limit. After every client left, retained chunks fell from 569 to 3 and live entities from 27 to 2 without a fatal error or persistence conflict.

This result qualifies the stated workload on the measured machine and build; it is not a universal hardware-capacity promise. Repeat the same sustained test after changes to session admission, movement, world streaming, entity projection, compression, persistence, worker scheduling, or runtime configuration. The initial admission ramp is measured separately from the stabilized full-population window and must not be presented as steady-state capacity.

`logging.protocol-trace` is disabled by default. Enable it only for a bounded reproduction and disable it afterward. Diagnostics may contain event names, phase, packet ID, rejection category, and exception class. They must not contain credentials, JWTs, keys, raw encrypted payloads, account identifiers, client GUIDs, or unbounded packet bodies.

## Crash reports

Unexpected runtime failures and supported PHP fatal errors produce an atomically published UTC report under `crashes/`. A report contains bounded runtime, failure, recent-log, plugin-attribution, and player-session sections. Reports are local only and are never uploaded automatically.

`crash-report.include-player-identifiers=true` includes the bounded player name, UUID, XUID, remote address, platform, and phase supplied by the active crash context. This is enabled by default for diagnosis. Crash reports are therefore sensitive: review and redact them before sharing. Set the option to `false` to retain only a session phase. Tokens, JWT contents, encryption material, credentials, raw packets, full configuration, `phpinfo()` output, source excerpts, and the private server root remain excluded regardless of this setting.

The crash context is a bounded provider owned by the composition root so later plugin and player lifecycle boundaries can publish exact involvement without granting the reporter access to mutable runtime internals. Native-process crashes, forced termination, power loss, and a permanently blocked extension may prevent PHP from writing a report.

Distinguish a client session disconnect from a server process crash. Confirm the process and UDP listener separately, then inspect the last accepted boundary as described in [packet lifecycle](packet-lifecycle.md).

## Shutdown and restart

SIGINT and SIGTERM request graceful shutdown on platforms where PHP exposes process-control signals. Runtime shutdown is idempotent: it stops new work, disconnects admitted players, releases chunk views and queues, flushes dirty world data, closes the world provider, and closes transport and remaining resources. A normal shutdown does not apply the per-tick autosave cap to its final durability flush.

Before replacing a running development instance, identify the exact Bedriox process and bound port. Do not terminate unrelated PHP processes. After restart, query discovery and rerun the relevant [client journey](client-journey-contract.md); a listening UDP socket alone does not prove join compatibility.

## Qualification and deployment

This development path uses exact sibling component checkouts and exact
qualified Runtime archives. Operate only from a clean, verified workspace. Do
not deploy from a dirty tree, patched `vendor/`, failed CI revision, modified
runtime files, or an unqualified component combination.

Performance and production claims require reproducible hardware, runtime, workload, duration, raw measurements, resource bounds, and correctness checks. Preserve no private client data in operational evidence.
