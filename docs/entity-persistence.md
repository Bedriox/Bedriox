# Entity persistence

Non-player persistent entities belong to the chunk containing their authoritative position. The simulation captures immutable `EntityChunkSnapshot` values; the world provider validates and stores them without accepting process-local runtime IDs, viewer state, pathfinding state, or protocol metadata.

## Durable records

`EntityPersistenceCodec` stores canonical type, UUID, world and chunk ownership, position, rotation, motion, health, age, equipment, variant, persistence state, spawn origin, automatic-despawn policy, exact entity revision, schema version, and bounded custom data. Natural-distance ownership therefore survives chunk unload and server restart, while command, spawn-egg, and plugin entities remain outside that policy. Legacy records without ownership metadata load as explicit-only rather than being inferred as natural. Unknown plugin-owned records remain `DormantEntityRecord` values and retain their encoded record bytes until the owning definition is available again. Unknown built-in identities and malformed or oversized state fail closed.

The LevelDB implementation uses a private versioned key namespace rather than writing Bedriox's binary document into Mojang's entity-NBT record. Entity corruption is therefore isolated from terrain and block-entity records. A missing key means no snapshot has ever been persisted; an invalid key value raises `CorruptEntityPersistenceException` and is never treated as an empty chunk.

## Revisions and ownership

`saveEntityChunk()` accepts a newer snapshot revision or an exact byte-identical retry. A stale revision, or different content at an existing revision, raises `EntityPersistenceConflictException` so the caller can rebuild the snapshot from current authoritative state.

When an entity crosses a chunk boundary, `transferEntityOwnership()` validates its exact old entity revision, verifies that the source no longer contains it and the destination contains an advanced revision, retains every unrelated durable entity, and writes both after-snapshots in one LevelDB batch. The entity therefore cannot become durable in both chunks or neither chunk.

Production calls use `ProcessWorldProvider`, which implements the same `EntityPersistenceStore` contract. Its authenticated world-storage child remains the only process that opens the LevelDB handle. Entity load, save, and transfer requests are bounded typed operations; a corrupt entity snapshot reports its owning coordinate without terminating the storage owner or preventing healthy chunks from loading.

The authoritative world integration must load a chunk's entity snapshot before activating its non-player entities, save the matching immutable snapshot during autosave and clean shutdown, and use an ownership transfer whenever a persistent entity changes chunk. Entity snapshot acknowledgements apply only to the exact captured revisions; mutations that occur after capture remain dirty.

Routine autosave captures a finite generation of dirty entity chunks and drains each captured chunk at most once. A chunk changed after its generation write remains dirty for the next generation instead of extending the current pass forever. Entity age is checkpointed when an autosave generation begins, on unload, and during clean shutdown; advancing age alone does not recreate persistence work every simulation tick. Cross-chunk ownership transfers retain their atomic source-and-destination write, but normal synchronization admits only a bounded number per tick and fairly defers the remainder.

Chunk activation and unload participate in the same observable entity lifecycle as explicit runtime spawns and removals. A restored custom mob first restores state, then completes its owner-attributed `onSpawn` callback, and only then publishes the general spawned post-event. Chunk unload saves the last authoritative state before removal, invokes the matching custom `onDespawn` callback, and publishes the general despawned post-event with the `chunk_unload` reason. Callback failure remains isolated to the owning plugin and cannot leave a failed activation visible or prevent the chunk from unloading.

Living-entity records retain bounded remaining fire ticks. A restored burning entity therefore projects its on-fire flag immediately and continues authoritative fire damage; a restored daylight-sensitive entity that is not already burning is independently evaluated against the current time, sky exposure, and water state.

Equipment persistence stores each occupied typed slot with its canonical item identifier, count, damage, auxiliary value, bounded NBT, and per-slot drop chance. Restoration validates the complete item before the entity becomes observable, does not emit plugin transition events for hydration, and projects the restored armor and hands with the actor's initial visible state. Later equipment changes advance entity persistence and presentation revisions through the ordinary authoritative transition path.
