# Security architecture

Bedriox processes untrusted UDP and Bedrock input. All packet lengths, decompressed sizes, NBT depth, collections, fragments, per-session queues, and handshake durations must be bounded before public deployment.

Authentication tokens, JWT chains, encryption keys, and raw credentials must never be logged. Development authentication must be explicitly named insecure. Startup composition must emit the secret-free notice exposed by the login channel's security posture whenever `SELF_SIGNED` is configured.

Login authentication defaults to `FULL`. The explicit `SELF_SIGNED` mode is development-only and never acts as fallback. FULL Token verification is described in [`authentication.md`](authentication.md). Its trusted JWK snapshot is injected; tokens cannot trigger network or key discovery. Failures never include JWT or key contents; see [`login-session.md`](login-session.md).

Production discovery disables redirects and implicit environment proxies, enforces an exact HTTPS host allowlist and TLS verification, bounds every response and JSON structure, applies capped exponential retry backoff, and retains only an unexpired atomic last-known-good key snapshot. Response bodies and keys must never be logged.

The runtime accepts only reliable ordered application payloads on channel zero. Per-poll work, session maps, decoded batches, generated commands, and outgoing encrypted payloads are bounded. Session-local decode, authentication, initialization, cipher, and transport-send failures remove only that endpoint and request authoritative world cleanup. Client-supplied chat names and XUIDs never determine broadcast attribution.

Client movement positions, deltas, and collision hints are untrusted. The simulation coalesces one predicted frame per session, validates displacement credit, terrain, world bounds, jump eligibility, landing, and tick order, and sends an authoritative reset when prediction is rejected. Coalescing retains a jump edge without allowing input floods to grow the queue.

Routine play traffic is allowlisted by decoded Protocol type and session phase. Notifications that have no implemented gameplay effect remain explicit no-ops; entity-scoped forms must identify the authenticated session's runtime entity. Unknown IDs, malformed payloads, and invalid phase or entity use fail closed for the affected session without widening the packet boundary.

The production bootstrap validates all required protocol-2193 bootstrap data and, in FULL mode, obtains a current trusted JWK snapshot before binding UDP. Startup never falls back to insecure authentication. The world runtime clamps the client radius to the configured view, generates complete selected-generator columns on demand, and bounds generation, staged delivery, retained views, and cached chunks. The cache must accommodate the worst-case configured player views. Full columns do not request separate sections, so production sessions reject unexpected SubChunk requests instead of maintaining a second terrain path.

Report vulnerabilities using the private process in [`SECURITY.md`](../SECURITY.md).
