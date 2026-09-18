# Architecture

Bedriox is the composition root for independently versioned RakNet, Bedrock protocol, and Bedrock data packages. External packets never mutate game state directly. Validated input becomes an immutable command consumed by the authoritative 20 TPS simulation; resulting events are encoded and sent by the network boundary.

The intended flow is:

```text
UDP → RakNet → Bedrock decode → session validation → command queue
    → simulation → event queue → Bedrock encode → RakNet → UDP
```

Mutable world and player state has one authoritative owner. Queues and decoded sizes are bounded. Initial development uses one non-blocking PHP process; worker processes are introduced only behind an interface and only after profiling.

Block state follows the same ownership rule. `Data` supplies canonical immutable states such as `minecraft:grass_block` and the ordered network palette. The server assigns dense process-local `InternalBlockStateId` values independently of that palette, uses those IDs inside world logic, and translates only the states needed by an outgoing palette through `BlockNetworkTranslator`. Internal IDs are intentionally unstable across process starts and must never become packet values, persistence keys, configuration, or plugin API. A future persistent world format will store canonical state identity and rebuild local IDs on load.

See the accepted architecture RFCs in `RFCs` for rationale and alternatives.

The bounded protocol-2193 pre-spawn state machine is documented in [`login-session.md`](login-session.md).
The independently composed FULL Token verifier is documented in [`authentication.md`](authentication.md).
The deterministic fixed-rate world and player domain is documented in [`simulation.md`](simulation.md).
The bounded transport-to-simulation composition loop is documented in [`runtime.md`](runtime.md).
The repository and class ownership map is documented in [`codebase-map.md`](codebase-map.md).
The packet path from UDP input to authoritative state and back is documented in [`packet-lifecycle.md`](packet-lifecycle.md).
The generated-world and chunk-streaming path is documented in [`world-chunk-pipeline.md`](world-chunk-pipeline.md).
Operational startup and shutdown ownership is documented in [`operations.md`](operations.md).
