# Repository Instructions

## Purpose and boundaries

This repository is the Bedriox executable server and composition root. It owns startup, configuration, session orchestration, the authoritative simulation, players, worlds, commands, and observability.

Keep dependency direction one-way:

```text
Bedriox -> RakNet
       -> Protocol
       -> Data
```

Do not implement RakNet wire behavior, Bedrock packet codecs, or versioned Bedrock registries here. Network input must be validated and converted into commands before it can affect mutable game state. World and player state must have one authoritative owner; queues and decoded data must remain bounded.

## Repository layout

- `bin/bedriox`: executable CLI entry point.
- `src/`: production server code under `Bedriox\Server\`.
- `src/Api/`: experimental public plugin API under `Bedriox\Api\`; never expose protocol, transport, registry, queue, or process-local block-state objects here.
- `tests/`: PHPUnit tests under `Bedriox\Server\Tests\`.
- `docs/`: repository-specific architecture, development, usage, testing, security, compatibility, and troubleshooting documentation.
- `tools/`: manifest, documentation, license, and cross-repository validation tools.
- `bedriox.lock.json`: supported server/component/Bedrock compatibility manifest.

Do not commit `vendor/`, credentials, access tokens, Minecraft client assets, personal packet captures, or build output.

## Coding rules

- Target 64-bit PHP 8.4 through PHP 8.x and begin every PHP source file with `declare(strict_types=1);`.
- Keep `bin/bedriox` thin; behavior belongs in testable classes under `src/`.
- Prefer immutable commands, events, configuration, and boundary values.
- Use explicit limits and deterministic failures for all external input, queueing, timing, decompression, and allocation decisions.
- Treat `bedriox.settings` as the operator-facing configuration authority: built-in defaults load first, the file second, and explicit CLI overrides last. Reject unknown, duplicate, malformed, partial, and out-of-range settings before binding sockets; do not add settings for behavior that does not exist.
- Use monotonic time for protocol/session deadlines. Do not let socket callbacks mutate simulation state directly.
- Preserve backward compatibility deliberately. Update the compatibility manifest and documentation when component or protocol support changes.
- Name current Bedrock packet, channel, codec, and data APIs without protocol-number suffixes or versioned namespaces. Keep the bounded compatible release family in the owning component's version authority, and route proven wire/data differences by the negotiated protocol.
- The executable admits protocol 2193 only. Minecraft Bedrock 1.26.50 is the pinned wire authority and 1.26.51 is the qualified same-protocol retail client; older protocol routes must fail closed at the first version-bearing login packet.
- World state uses Bedriox-owned process-local block-state IDs resolved from canonical `minecraft:*` states. Never persist, expose, or send those IDs; translate through `BlockNetworkTranslator` only at the Bedrock packet boundary.
- Follow the proven PocketMine-MP authority model for gameplay interactions: decoded client item descriptors, counts, stack-network IDs, block runtime IDs, and prediction fields are bounded intent or reconciliation hints only. The server-selected inventory slot, server-owned stack, and authoritative world state alone authorize and determine a placement. Apply a transaction's valid hotbar selection before evaluating its placement, and correct rejected predictions instead of accepting client-owned state.
- Treat predicted block-break completion like PocketMine-MP: revalidate reach, the current server-owned block, and breakability when completion arrives, and derive any tool effects from the current server-held item. Break timing drives progress feedback but is not a strict packet-arrival authorization gate; client/server tick phase must not restore an otherwise valid break. A continue action starts the next authoritative target, and late stop/abort input remains benign.
- Generate complete fixed-flat LevelChunk columns on demand through the world model and stream each player's bounded configured view nearest-first. Keep generation, sending, caching, and retained views bounded; translate internal state IDs only at serialization. Validate any compatibility SubChunk requests through Protocol and serve only the configured radius and section range.
- Treat the world provider as the persistence authority. Load before generating, generate only on an explicit missing result, and never replace corrupt, unsupported, or unreadable storage with new terrain. Persist canonical block-state identity rather than process-local or network runtime IDs. A dirty chunk remains dirty until its exact revision is acknowledged by a successful write; save dirty eviction candidates, bound routine autosave work, and flush world data before closing its provider.
- Do not introduce worker processes, native extensions, global mutable state, or new cross-repository abstractions without an accepted RFC and measured need.
- Load production plugins only from bounded `plugins/*.phar` archives. Require a strong embedded PHAR signature, validate the complete archive and dependency plan before entry-point execution, and never deserialize PHAR metadata. Source-directory loading belongs only in explicit development tooling.
- Bedriox must never enumerate or read source-plugin directories. Development tooling may register one complete bounded source-definition batch during its PHAR `onLoad`; Bedriox validates metadata and dependencies, admits the batch only after PHAR enablement, and applies an implicit provider dependency so source plugins stop before their loader. Keep discovery, path validation, autoloading, and packaging in PluginTools.
- Keep plugin lifecycle and event work attributed through `PluginExecutionContext`. A plugin exception must discard that listener's staged work, restore controlled event state, disable the plugin, clean its owned resources, and leave the server and unrelated players running.
- Dispatch cancellable pre-events only after core validation and before authoritative gameplay mutation. Cancellation must use the existing correction or reconciliation path. Post-events are observational. `MONITOR` listeners may not mutate events or stage server actions.
- Public plugin operations are bounded intent. Revalidate them in the authoritative simulation and express blocks/items with canonical `minecraft:*` identifiers; never hand plugins mutable server objects or internal numeric IDs.
- Keep console lines in `[DD-Mon-YYYY HH:mm:ss] Bedriox LEVEL > message` format. File logs must contain no ANSI codes. Crash reports stay local, bounded, atomic, and free of tokens, JWT contents, key material, credentials, raw packets, complete configuration, source excerpts, and private workspace paths. Player names, UUIDs, XUIDs, and remote addresses are intentionally included when the documented setting is enabled.
- Keep command names, aliases, input length, argument counts, queued input, per-poll execution, and cooperative jobs bounded. Commands belong to their registering plugin and must be removed on disable. Enforce sender and permission policy before plugin code; command or job failure must retain exact plugin attribution and isolation. Never block a command handler or job poll while waiting for a process or external I/O.

## Quality commands

Install dependencies and run the complete local gate:

```shell
composer install
composer check
php bin/bedriox --version
```

`composer check` validates Composer metadata and advisories, `bedriox.lock.json`, relative documentation links, dependency licenses, formatting, maximum-level PHPStan, and PHPUnit.

When all sibling repositories are present, run:

```powershell
powershell.exe -NoProfile -File tools/verify-workspace.ps1
```

Use `-SkipClean` only during initial local scaffolding, never as a release gate. Add focused unit tests for local behavior and integration tests for cross-component contracts. A Bedrock version is supported only after automated interoperability coverage and retail-client qualification.

## Documentation, licensing, and security

Update repository-local documentation and `CHANGELOG.md` with behavior changes. Architectural decisions and cross-project proposals belong in `RFCs`; public operator and plugin guides belong in `Docs` once those repositories are involved.

Do not copy implementation code or data with unknown or incompatible rights. Record required third-party copyright and license attribution in `THIRD_PARTY_NOTICES.md` and `NOTICE`, and keep dependency versions and immutable component revisions in Composer's lock file and `bedriox.lock.json`.

Treat UDP, protocol data, paths, configuration, logs, and credentials as untrusted. Add negative tests for malformed and oversized input. Never log authentication tokens, JWT chains, encryption keys, raw credentials, or unrelated personal information. Follow `SECURITY.md` for private vulnerability reporting; do not disclose suspected vulnerabilities in public issues.

Crash reports are sensitive local diagnostics. They may include the configured player identity and network fields needed to reproduce a failure; do not copy them into issues without operator review and redaction.

## Cross-repository coordination

- Pin released component versions through Composer and `bedriox.lock.json`; do not depend on undocumented sibling internals.
- Coordinate breaking interfaces with the owning repository and update consumer tests, compatibility documentation, and the lock manifest together.
- Keep repository-local implementation changes in their owning repository. Do not edit siblings unless the task explicitly includes them.
- Run both the owning repository gate and the workspace gate after a cross-repository contract change.

## Change preservation and regression policy

Existing working behavior is frozen unless the task explicitly requires changing it. Passing tests do not authorize unrelated behavior changes. Any intentional compatibility change requires its own declared scope, characterization or regression tests, documentation, and qualification evidence.

Before editing, state the intended repository, affected subsystem and files, observable outcome, and behavior that must remain unchanged. If implementation reveals a necessary change outside that scope, stop and expand the scope explicitly before editing it. Do not combine opportunistic cleanup, renaming, defaults, wire changes, or dependency upgrades with feature work.

Treat discovery fields, protocol admission, packet IDs and layouts, registry hashes, chunk framing, authentication policy, default ports, advertised game mode, operator defaults, and component pins as protected compatibility surfaces. Characterize the existing behavior before changing one. Every reported retail-client failure requires a permanent regression test at the last observable failure boundary.

Treat each Bedrock feature as a complete packet conversation, not one outbound packet. Before enabling any server-emitted packet or behavior, audit the current protocol for every client reply, acknowledgement, update, and teardown packet that emission can trigger; register bounded codecs and phase-correct handlers for all reachable responses, and add an encrypted end-to-end channel test. An outbound packet is not shippable while a normal client response can fall through the packet registry or default play-state rejection.

For gameplay modeled after PocketMine-MP, review the complete upstream behavior path before implementing: packet entry points (including dedicated and `PlayerAuthInput` forms), prediction tracking, authoritative mutation, response/correction, peer synchronization, and subsequent use of the resulting state. Record any legally required attribution in the third-party notices. A partial port that handles the first packet but misses an alternate retail path, same-tick action ordering, correction, or follow-up action is not complete. Add a regression that crosses the last user-visible failure boundary; isolated codec or unit tests alone do not qualify the feature.

PocketMine-MP is the mandatory primary implementation reference for gameplay behavior unless the applicable protocol did not exist in its pinned supported release. Before writing or changing gameplay code, inspect PMMP's complete feature path and every constant/translator it depends on, then compare the corresponding Bedriox path field by field. Do not substitute memory, inferred enum ordinals, synthetic fixtures, or a partial method review for this audit. For inventory work this explicitly includes StartGame inventory mode, packet entry points, `ItemStackRequestExecutor`, container-ID translation, stack-ID/request lineage, transaction staging, response construction, correction sync, and the next action consuming the changed stack. A retail failure caused by a missed PMMP dependency must add a literal regression for the observed semantic shape and strengthen this checklist before another attempted fix.

Implement the verified PMMP behavior within Bedriox's existing ownership and dependency boundaries. Do not copy PMMP implementation text by default. Any literal or adapted source reuse requires an explicit license-compatibility decision, required LGPL notices/source availability, and confirmation that the repository's outbound licensing remains accurate before the code is introduced.

Classify decoded client input deliberately as applied, bounded deferred input, safe no-op, authoritative correction, or session disconnect. Do not disconnect solely because a valid retail notification arrives earlier, later, or more than once than the nominal sequence; characterize observed ordering and keep harmless timing variation bounded. Reserve disconnects for malformed framing or codecs, authentication or cryptographic failure, actor or identity spoofing, exhausted limits, repeated abuse, and states that cannot be corrected safely.

The production RakNet adapter must drain bounded pre-ready diagnostics on every poll. Keep them protocol-trace gated and allowlisted; never add packet bodies, GUIDs, authentication material, embedded addresses, or exception messages. Transport diagnostics must not change admission, lifecycle capacity, or peer isolation.

Never patch `vendor/` or depend on a dirty sibling checkout. Change the owning repository first, run its complete gate, commit it, then update Bedriox's lock manifest and consumer tests in a separate coordinated change. A component update must not silently alter unrelated application behavior.

Tests are cumulative across milestones. Before a commit, run the focused tests, `composer check`, and the workspace verifier when applicable; then inspect `git status --short`, `git diff --name-only`, `git diff --stat`, the complete diff, and `git diff --check`. Unexplained files or behavior block the commit. Do not begin the next milestone until the current milestone's automated gates pass, its pushed CI is green, and any required retail-client journey has been recorded.

Follow [`docs/change-safety.md`](docs/change-safety.md) for the working procedure and [`docs/client-journey-contract.md`](docs/client-journey-contract.md) for the permanent playable baseline.

## Commits

Keep commits focused and use an imperative, descriptive subject, preferably with the established `type: summary` form. Commit messages must not contain personal email addresses or identity trailers. Do not rewrite or discard unrelated contributor work.

## Definition of done

A change is complete when its architecture boundary remains intact, relevant tests cover success and failure paths, `composer check` passes, applicable workspace checks pass, documentation, changelog, and notices are current, security limits have been reviewed, and the final diff contains only intended files with no secrets or unlicensed material.
