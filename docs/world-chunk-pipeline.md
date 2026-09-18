# World and chunk pipeline

Bedriox generates authoritative world state independently from the Bedrock network palette, then translates it only while encoding an outgoing chunk.

## Generation and ownership

`WorldMetadata` identifies the logical level and seed. `WorldSpawnResolver` supplies the level's safe spawn unless all three configured spawn coordinates override it. `World` owns a `WorldGenerator`, bounded `ChunkRepository`, and bounded process-local override store. Immutable cell replacement updates the cached chunk and records the canonical override so regeneration after eviction preserves accepted gameplay changes. Overrides are not yet persisted across a server restart.

The current `FlatWorldGenerator` can generate any requested chunk coordinate deterministically. It resolves canonical states through `BlockStateRegistry` and stores dense process-local `InternalBlockStateId` values in `SubChunk` and `Chunk` objects. Those IDs have no stable meaning outside the process and must never be persisted, configured, logged as a public contract, or written directly to the wire.

The initial flat profile is bedrock at Y=60, dirt at Y=61 and Y=62, grass at Y=63, and air elsewhere. The world spawn places the player's feet above that surface.

## Cache and view scheduling

`ChunkRepository` generates on demand and retains a bounded shared cache. Active session views lease their chunks so an in-use column cannot be evicted. Configuration must reserve enough capacity for every maximum-size player view.

Each play session owns one `ChunkViewManager`. It clamps the requested radius, computes a nearest-first square, queues missing coordinates, and releases coordinates that leave the view. Crossing a 16-by-16 block boundary recenters the view and schedules only the new edge. Generation and send work use independent per-tick budgets, and the staging queue is bounded. It stores coordinates rather than serialized bytes, so delivery always serializes the latest authoritative chunk and cannot overwrite an intervening block update with stale terrain.

The configured spawn-radius square is delivered before `PlayerSpawn`. Remaining negotiated-view chunks continue afterward. Completed views remain idle until movement changes the center; they must not continually regenerate or resend their full set.

## Wire translation

`BlockNetworkTranslator` maps each internal canonical state to the active Data network runtime ID. `BedrockChunkPacketSerializer` uses that translation to encode complete columns for the current Protocol contract, including section palettes, air sections, biome data, height maps, coordinates, and bounded block entities.

Translation happens only at this boundary. The generator must not import network IDs, and the serializer must not invent world state. Full streamed columns currently do not require client SubChunk requests; unexpected requests are validated and rejected by policy.

## Required invariants

- Every generated coordinate returns the same canonical cells for the same world definition.
- Every visible column contains the correct absolute chunk coordinates.
- Every internal state resolves through the active network palette before sending.
- Every section and biome cell reconstructs semantically, not merely to an expected byte length.
- Generation, cache residency, queued packets, retained views, and per-tick work remain bounded.
- Movement releases departed columns and queues only newly visible ones.
- Failure and disconnect release every lease and pending payload.

When terrain is wrong, test generation, translation, serialization, scheduling, and delivery separately. Do not change registry values or transport framing to mask an unproven layer defect.
