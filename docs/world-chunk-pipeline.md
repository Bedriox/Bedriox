# World and chunk pipeline

Bedriox generates authoritative world state independently from the Bedrock network palette, then translates it only while encoding an outgoing chunk.

## Generation and ownership

`WorldMetadata` identifies the logical level and seed. `WorldSpawnResolver` supplies the level's safe spawn unless all three configured spawn coordinates override it. A provider-backed `World` owns a `WorldGenerator`, bounded `ChunkRepository`, and `WorldProvider`. Immutable cell replacement creates a new authoritative chunk revision and marks it dirty. Providerless worlds retain the bounded process-local override store only as an explicit ephemeral compatibility path.

`WorldGeneratorFactory` resolves the persisted `default` or `flat` identity. Both generators can generate any requested coordinate deterministically. They resolve canonical states through `BlockStateRegistry` and store dense process-local `InternalBlockStateId` values in `SubChunk` and `Chunk` objects. Those IDs have no stable meaning outside the process and must never be persisted, configured, logged as a public contract, or written directly to the wire. The LevelDB provider persists canonical block-state name and property NBT and resolves fresh process-local IDs while loading.

The initial flat profile is bedrock at Y=60, dirt at Y=61 and Y=62, grass at Y=63, and air elsewhere. The world spawn places the player's feet above that surface.

The default profile combines deterministic smooth noise at continental, regional, and local scales to form coastlines, lowlands, mountain ranges, valleys, rivers, oceans, and climate regions across chunk boundaries. It applies slope- and biome-specific surfaces, source water through sea level, cold-region ice, cross-chunk caves, regional ore veins, boulders, and biome-specific forests. Source fluids occupy only the primary block layer and are non-solid for collision. Spawn selection searches a bounded area for dry, low-slope terrain with two air blocks of headroom. The algorithm is Bedriox-defined and does not promise Minecraft seed parity.

## Persistence

The provider is consulted before generation. An existing chunk is decoded and cached; only a definite missing result invokes the configured generator. Corrupt, unsupported, or unreadable data fails explicitly and is never treated as an empty coordinate that may be regenerated.

`LevelDbWorldProvider` stores Mojang-compatible `level.dat` metadata, a repairable `levelname.txt` display-name mirror, and Bedrock LevelDB chunk records. `level.dat` is authoritative if the mirror is absent or stale. Persistent palettes use canonical block-state NBT, while chunk keys, subchunk records, biome palettes, height maps, and finalization state follow the supported Bedrock storage contract. Disk XZY palette order is translated explicitly to the world's internal coordinate order.

Generated and changed chunks remain dirty until their exact immutable revision is acknowledged by a successful provider write. Autosave processes the oldest dirty chunks first with the configured `chunks.save-per-tick` bound whenever `level.autosave-interval-ticks` elapses. A dirty eviction candidate is saved before removal; a failed write leaves it resident and dirty. Graceful close flushes every remaining dirty chunk and world metadata before closing the provider.

## Cache and view scheduling

`ChunkRepository` loads or generates on demand and retains a bounded shared cache. Active session views lease their chunks so an in-use column cannot be evicted. Configuration must reserve enough capacity for every maximum-size player view.

Each play session owns one `ChunkViewManager`. It clamps the requested radius, computes a nearest-first square, queues missing coordinates, and releases coordinates that leave the view. Crossing a 16-by-16 block boundary recenters the view and schedules only the new edge. Generation and send work use independent per-tick budgets, and the staging queue is bounded. It stores coordinates rather than serialized bytes, so delivery always serializes the latest authoritative chunk and cannot overwrite an intervening block update with stale terrain.

The configured spawn-radius square is delivered before `PlayerSpawn`. Remaining negotiated-view chunks continue afterward. Completed views remain idle until movement changes the center; they must not continually regenerate or resend their full set.

## Wire translation

`BlockNetworkTranslator` maps each internal canonical state to the active Data network runtime ID. `BedrockChunkPacketSerializer` uses that translation to encode complete columns for the current Protocol contract, including section palettes, air sections, biome data, height maps, coordinates, and bounded block entities.

Translation happens only at this boundary. The generator must not import network IDs, and the serializer must not invent world state. Full streamed columns currently do not require client SubChunk requests; unexpected requests are validated and rejected by policy.

The version-one `default` pipeline derives independent seed channels for coordinate warping, continentalness, erosion, temperature, humidity, ridges, uplift, rivers, and local detail. Those fields determine elevation and biome before surface rules, cave carving, regional ore placement, boulders, and biome-specific tree decoration run. Regional coordinate sampling is cached under a fixed bound, while every feature anchor is derived from world coordinates so generation order cannot change a chunk.

`level.dat` stores the generator name and `BedrioxGeneratorVersion`. Missing version metadata is interpreted as version one for native compatibility. A stored version that the selected generator cannot reproduce is rejected before any missing chunk can be generated. Generator upgrades therefore require a new explicit version rather than silently creating seams beside established terrain.

## Required invariants

- Every generated coordinate returns the same canonical cells for the same world definition.
- Generator output is independent of chunk request order and sign of the coordinates.
- Every visible column contains the correct absolute chunk coordinates.
- Every internal state resolves through the active network palette before sending.
- Existing provider data is loaded before generation; storage failures never become implicit generation.
- Dirty chunks are acknowledged only after their authoritative revision is written successfully.
- Eviction and graceful shutdown cannot discard unsaved provider-backed changes.
- Every section and biome cell reconstructs semantically, not merely to an expected byte length.
- Generation, cache residency, queued packets, retained views, and per-tick work remain bounded.
- Movement releases departed columns and queues only newly visible ones.
- Failure and disconnect release every lease and pending payload.

When terrain is wrong, test generation, translation, serialization, scheduling, and delivery separately. Do not change registry values or transport framing to mask an unproven layer defect.
