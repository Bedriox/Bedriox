# Testing

Run the complete local quality gate:

```shell
composer check
```

Or run individual layers:

```shell
composer format:check
composer analyse
composer test
```

The complete gate also validates Composer metadata and advisories, the integration manifest, relative documentation links, and dependency licenses. PHPUnit treats warnings, risky tests, and tests without assertions as failures. Environment tests verify locked archive parsing, hostile archive rejection, staged rollback, pre-autoload Runtime identity and inventory checks, launcher argument forwarding, and the absence of any system-PHP fallback.

Private CI checks out Protocol, RakNet, and Data at the exact commits recorded in the integration manifest. A pre-install exporter reads and validates those immutable pins directly from `bedriox.lock.json`; the workflow contains no second set of component commit hashes to drift. The manifest gate also validates that the workflow consumes all three derived outputs, alongside the exact package-to-component mapping, normalized sibling repository paths, path type, disabled symlinks, explicit versions, locked dist paths and references, and matching sibling Git HEADs when those repositories are present. Its negative tests cover malformed CI pins, literal workflow refs, path substitution, source overrides, pin drift, and premature support claims without invoking fixture-controlled paths. Component pins do not declare any Bedrock client or network protocol supported; those compatibility arrays remain empty until retail qualification is complete.

Future system tests must cover discover, connect, authenticate, spawn, peer visibility, movement, chat, disconnect, and clean reconnect. A real Bedrock version is not declared supported until independent automated tests and retail-client qualification both pass.

The permanent baseline is defined in [`client-journey-contract.md`](client-journey-contract.md). Every milestone reruns all implemented portions of that journey; a new milestone may add coverage but may not retire earlier acceptance checks. Reported crashes, timeouts, malformed terrain, incorrect actor state, or retail disconnects receive a focused regression at the last observable boundary.

Changes to an existing compatibility surface begin with a characterization or golden-vector test. Discovery tests independently inspect the advertised wire fields; protocol tests use literal vectors rather than only production encode/decode round trips; runtime tests exercise component contracts through public APIs; and world tests reconstruct semantic cells rather than accepting packet length alone.

The simulation test layer replays identical command streams, exercises count and byte queue overload, verifies movement coalescing, tick-credit correction, rotation-only publication, grounded-only jump transitions, idle-frame stability, floor jitter, and collision-safe landing, validates per-sender chat order and throttling, enforces a peer-only five-tick emote cooldown without disrupting chat or movement, and runs a synthetic 100-player join/move/chat/disconnect cycle. Inventory tests cover atomic split, cursor transfer, rollback after a later invalid action, stale stack IDs, request-ID lineage, fresh server IDs, selected-stack peer updates, and placement from the resulting hotbar stack. Play-channel coverage checks full unsigned client-tick ordering, protocol-neutral local sequencing, and both dedicated and embedded stack-request routing. These model tests do not replace packet interoperability or retail-client qualification.

Runtime tests drive the complete encrypted login-to-play transfer with a fake transport, prove that world join remains queued until the initialization acknowledgement, and cover valid movement and chat while bounded bootstrap and terrain work continues. They also verify spoofed chat attribution is discarded, inventory peer updates are actor-visibility filtered, and per-session failure isolation and idempotent shutdown. Transport-adapter tests pin the safe RakNet handshake-diagnostic mapping, dropped-event reporting, protocol-trace gating, and exclusion of raw payload and GUID fields. Play-channel tests cover bounded multi-poll bootstrap draining, repeatable and capped SubChunk requests, payload-free request diagnostics, acknowledgement interleaving without a terrain admission latch, authoritative stack-request routing and rejection, typed block and item-use routing, hotbar selection, retail-shaped pre-initialization EmoteList acceptance, initialized own-actor emote admission with spoofable fields discarded, phase-appropriate routine notifications, wrong-entity rejection, latency no-op behavior, and fail-closed malformed and unknown packets. Literal packet-144 fixtures prove block intents become simulation commands without direct mutation, including normalization of the retail abort sentinel into a targetless command; an Animate swing cannot create break state. Independent packet-30 tests prove that initialized placement applies the transaction's bounded slot selection before one authoritative placement command, ignores adversarial client item/runtime prediction hints, cannot place from an empty server slot, leaves unrelated forms as bounded no-ops, fails pre-initialization input as `invalid_play_state`, fails malformed bytes as `decode_failed`, and permits movement and chat afterward. Simulation and projection tests cover the 18-tick break feedback rate, authoritative predicted completion without a prior start, simultaneous-break correction, all six placement faces, reach/state/collision and capacity rejection, one-count server inventory consumption, owner repair packets, byte-identical multiplayer block updates, and held-equipment visibility. They use synthetic ephemeral keys and contain no retail credentials or captures.

The initialization regression suite also replays the literal retail empty-equipment notification before `SetLocalPlayerAsInitialized`, proves repeated valid notifications occupy one pending scalar, releases exactly one authoritative hotbar command after admission, continues into placement, and verifies through the full runtime that an early notification from one of two peers disconnects neither.

The initialization integration test reads Data through its public hash-verifying API, checks the registry and player-state bootstrap order, pins the data-driven StartGame and admitted item-registry payload hashes, verifies the exact shared 64-grass inventory/equipment state, and confirms terrain is delegated to the runtime streamer. World tests cover deterministic flat generation, version-one default generation, regional biome/elevation distribution, negative coordinates, unit-scale continuity, generation-order independence, generator-version rejection, immutable mutation, override capacity, and cache-eviction persistence. `tools/render-terrain-map.php` writes bounded PPM height and biome maps plus a JSON distribution summary for visual tuning. Runtime tests compare translated internal chunks with the qualified full-column vector, stream a complete negotiated view, serialize staged chunks from current authoritative state, enforce distinct per-tick generation and send budgets, cap a sustained generated-coordinate backlog, release retained chunks on failure and disconnect, and load only the newly visible edge after authoritative movement. Protocol tests additionally pin actor NBT, skin/profile-hash, inventory-content/slot, StartGame, biome-definition, v9 section, and negotiated height-map vectors. A loopback bootstrap test exercises real UDP binding and the explicit self-signed warning without accepting a client.

## Cross-repository verification

When all sibling repositories are checked out beneath the same parent
directory, run the workspace gate from the Bedriox repository:

```powershell
powershell.exe -NoProfile -File tools/verify-workspace.ps1
```

PowerShell 7 users may substitute `pwsh` for `powershell.exe`.

The gate resolves only the explicit `Bedriox`, `RakNet`, `Protocol`, `Data`,
`Runtime`, `ExamplePlugin`, `PluginTools`, `Docs`, and `RFCs` siblings and
rejects paths outside their shared parent. It checks clean Git state, required
project documents, Bedriox branding, license declarations, prohibited
legacy license language, Composer validation/tests/audits for code repositories,
and the PHP documentation validators. Runtime is validated through its native
`php tools/validate.php` gate rather than Composer. The Runtime commit in
`bedriox.lock.json` identifies the source revision that produced the pinned
archives; a newer clean Runtime documentation checkout does not rewrite that
artifact provenance.

During initial scaffolding, when expected files have not yet been committed, Git
cleanliness alone may be bypassed while retaining every other check:

```powershell
powershell.exe -NoProfile -File tools/verify-workspace.ps1 -SkipClean
```

Do not use `-SkipClean` for a release gate.

After a gameplay-visible milestone passes automated checks, record the exact server and component commits, client build, settings, journey steps, outcome, and known limitations. Do not retain credentials, account identifiers, raw authentication payloads, or personal packet captures. A successful join alone does not qualify the journey.
