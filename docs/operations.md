# Operations

Bedriox currently runs as one bounded PHP process. The executable owns configuration, startup validation, UDP binding, the non-blocking runtime loop, diagnostics, signal handling, and graceful cleanup.

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

The first successful configuration load creates `bedriox.settings` in the current working directory. Built-in defaults load first, the settings file second, and explicit command-line overrides last. Invalid, duplicate, unknown, partial, or out-of-range settings fail before socket bind.

The default game port is UDP `19132`. Bedriox does not currently provide a separate query service or query port; Bedrock server-list discovery uses the game port. FULL authentication is the default and must complete trusted-key discovery before bind. `SELF_SIGNED` is an explicit isolated-development mode and is never a fallback.

## Startup sequence

Before accepting clients, startup verifies the locked packaged Runtime before
Composer autoload, then verifies configuration, the pinned Data artifacts,
canonical block states, translation availability, authentication dependencies,
protocol authority, and transport construction. A failure leaves no partially
running server and never falls back to ambient PHP.

After bind, the runtime polls bounded amounts of socket, session, packet, command, chunk-streaming, and simulation work. The process should remain responsive even when one peer sends malformed input or exhausts its session-local limits.

## Diagnostics

Normal server activity is rendered in a consistent operator format and is mirrored without terminal color codes to `logs/server.log`:

```text
[17-Sep-2026 21:42:10] Bedriox INFO > Starting Bedriox 0.1.0-alpha.1
```

`logging.level` accepts `DEBUG`, `INFO`, `NOTICE`, `WARNING`, `ERROR`, or `CRITICAL`. Console and file output may be enabled independently. `logging.console-colors` accepts `auto`, `true`, or `false`; `auto` colors only an interactive terminal. The file rotates into `logs/archive/` at `logging.file-max-size` and retains at most `logging.file-history` archives. Logging failures are contained and never alter simulation state.

`logging.protocol-trace` is disabled by default. Enable it only for a bounded reproduction and disable it afterward. Diagnostics may contain event names, phase, packet ID, rejection category, and exception class. They must not contain credentials, JWTs, keys, raw encrypted payloads, account identifiers, client GUIDs, or unbounded packet bodies.

## Crash reports

Unexpected runtime failures and supported PHP fatal errors produce an atomically published UTC report under `crashes/`. A report contains bounded runtime, failure, recent-log, plugin-attribution, and player-session sections. Reports are local only and are never uploaded automatically.

`crash-report.include-player-identifiers=true` includes the bounded player name, UUID, XUID, remote address, platform, and phase supplied by the active crash context. This is enabled by default for diagnosis. Crash reports are therefore sensitive: review and redact them before sharing. Set the option to `false` to retain only a session phase. Tokens, JWT contents, encryption material, credentials, raw packets, full configuration, `phpinfo()` output, source excerpts, and the private server root remain excluded regardless of this setting.

The crash context is a bounded provider owned by the composition root so later plugin and player lifecycle boundaries can publish exact involvement without granting the reporter access to mutable runtime internals. Native-process crashes, forced termination, power loss, and a permanently blocked extension may prevent PHP from writing a report.

Distinguish a client session disconnect from a server process crash. Confirm the process and UDP listener separately, then inspect the last accepted boundary as described in [packet lifecycle](packet-lifecycle.md).

## Shutdown and restart

SIGINT and SIGTERM request graceful shutdown on platforms where PHP exposes process-control signals. Runtime shutdown is idempotent: it closes transport, clears login and play cryptographic state, disconnects admitted players, and releases chunk views and queues.

Before replacing a running development instance, identify the exact Bedriox process and bound port. Do not terminate unrelated PHP processes. After restart, query discovery and rerun the relevant [client journey](client-journey-contract.md); a listening UDP socket alone does not prove join compatibility.

## Qualification and deployment

This private-alpha path uses exact sibling component checkouts and exact
qualified Runtime archives. Operate only from a clean, verified workspace. Do
not deploy from a dirty tree, patched `vendor/`, failed CI revision, modified
runtime files, or an unqualified component combination.

Performance and production claims require reproducible hardware, runtime, workload, duration, raw measurements, resource bounds, and correctness checks. Preserve no private client data in operational evidence.
