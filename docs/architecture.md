# Architecture

Bedriox is the composition root for independently versioned RakNet, Bedrock protocol, and Bedrock data packages. External packets never mutate game state directly. Validated input becomes an immutable command consumed by the authoritative 20 TPS simulation; resulting events are encoded and sent by the network boundary.

The intended flow is:

```text
UDP → RakNet → Bedrock decode → session validation → command queue
    → simulation → event queue → Bedrock encode → RakNet → UDP
```

Mutable world and player state has one authoritative owner. Queues and decoded sizes are bounded. Managed worker processes receive immutable, versioned task payloads for admitted CPU-heavy work; completions return to the authoritative server loop before they can affect gameplay. Ordered storage and log services own their file handles exclusively. Workers never receive live players, worlds, sessions, sockets, packets, or mutable registries.

Chunk streaming has a deliberately later worker boundary than world authority. The server installs or loads one immutable canonical chunk revision, snapshots its canonical palettes and validated packed X-Z-Y words into a bounded projection transfer, and asks a core worker to translate each palette once, write those words, frame, and compress the packet. A shared revision- and wire-identity cache deduplicates the result across players. The main process revalidates the current revision, applies the session cipher, and hands the payload to RakNet. Unfinished chunk preparation is never inserted into a session's ordered output, so chat and control traffic can continue while workers are busy.

Block state follows the same ownership rule. `Data` supplies canonical immutable states such as `minecraft:grass_block` and the ordered network palette. The server assigns dense process-local `InternalBlockStateId` values independently of that palette, uses those IDs inside world logic, and translates only the states needed by an outgoing palette through `BlockNetworkTranslator`. Internal IDs are intentionally unstable across process starts and must never become packet values, persistence keys, configuration, or plugin API. LevelDB persistence stores canonical state identity and rebuilds local IDs on load.

See the accepted architecture RFCs in `RFCs` for rationale and alternatives.

The bounded protocol-2193 pre-spawn state machine is documented in [`login-session.md`](login-session.md).
The independently composed FULL Token verifier is documented in [`authentication.md`](authentication.md).
The deterministic fixed-rate world and player domain is documented in [`simulation.md`](simulation.md).
The bounded transport-to-simulation composition loop is documented in [`runtime.md`](runtime.md).
The PHAR plugin lifecycle, typed event boundary, and public API are documented in [`plugins.md`](plugins.md).
The repository and class ownership map is documented in [`codebase-map.md`](codebase-map.md).
The packet path from UDP input to authoritative state and back is documented in [`packet-lifecycle.md`](packet-lifecycle.md).
The generated-world and chunk-streaming path is documented in [`world-chunk-pipeline.md`](world-chunk-pipeline.md).
Operational startup and shutdown ownership is documented in [`operations.md`](operations.md).
