# Operations

Bedriox currently runs as one bounded PHP process. The executable owns configuration, startup validation, UDP binding, the non-blocking runtime loop, diagnostics, signal handling, and graceful cleanup.

## Starting the server

From the repository root:

```shell
composer install
composer check
php bin/bedriox serve
```

The first successful configuration load creates `bedriox.settings` in the current working directory. Built-in defaults load first, the settings file second, and explicit command-line overrides last. Invalid, duplicate, unknown, partial, or out-of-range settings fail before socket bind.

The default game port is UDP `19132`. Bedriox does not currently provide a separate query service or query port; Bedrock server-list discovery uses the game port. FULL authentication is the default and must complete trusted-key discovery before bind. `SELF_SIGNED` is an explicit isolated-development mode and is never a fallback.

## Startup sequence

Before accepting clients, startup verifies configuration, PHP/platform requirements, the pinned Data artifacts, canonical block states, translation availability, authentication dependencies, protocol authority, and transport construction. A failure leaves no partially running server.

After bind, the runtime polls bounded amounts of socket, session, packet, command, chunk-streaming, and simulation work. The process should remain responsive even when one peer sends malformed input or exhausts its session-local limits.

## Diagnostics

`logging.protocol-trace` is disabled by default. Enable it only for a bounded reproduction and disable it afterward. Diagnostics may contain event names, phase, packet ID, rejection category, and exception class. They must not contain credentials, JWTs, keys, raw encrypted payloads, account identifiers, client GUIDs, or unbounded packet bodies.

Distinguish a client session disconnect from a server process crash. Confirm the process and UDP listener separately, then inspect the last accepted boundary as described in [packet lifecycle](packet-lifecycle.md).

## Shutdown and restart

SIGINT and SIGTERM request graceful shutdown on platforms where PHP exposes process-control signals. Runtime shutdown is idempotent: it closes transport, clears login and play cryptographic state, disconnects admitted players, and releases chunk views and queues.

Before replacing a running development instance, identify the exact Bedriox process and bound port. Do not terminate unrelated PHP processes. After restart, query discovery and rerun the relevant [client journey](client-journey-contract.md); a listening UDP socket alone does not prove join compatibility.

## Qualification and deployment

This private-alpha path uses exact sibling component checkouts and is not yet a standalone distribution. Operate only from a clean, verified workspace. Do not deploy from a dirty tree, patched `vendor/`, failed CI revision, or unqualified component combination.

Performance and production claims require reproducible hardware, runtime, workload, duration, raw measurements, resource bounds, and correctness checks. Preserve no private client data in operational evidence.
