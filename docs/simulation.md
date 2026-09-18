# Authoritative Simulation

Bedriox's initial world domain is a deterministic, in-memory flat-world model. It owns all mutable player state and advances at 20 ticks per second by default. Network callbacks cannot access player state: an adapter must first use `SimulationCommandFactory` to convert authenticated, decoded input into one of the immutable join, movement, chat, emote, or disconnect commands, then enqueue it on `WorldSimulation`.

This model does not implement Bedrock packet encoding, chunk serialization, combat, mobs, commands, or persistence. It does own player inventory, canonical fixed-flat block mutation, break timing, and placement validation; protocol adapters translate the resulting version-neutral events at a separate boundary.

## Ordering and limits

The simulation drains its queues only at tick boundaries. Normal non-movement input uses a FIFO with bounded command count and estimated retained bytes. Movement uses a bounded latest-input-per-session map: replacing an input does not grow the queue, and a one-shot jump edge remains latched until the tick consumes it. Joins retain admission priority when the per-tick budget is small. Disconnects use a separate bounded lifecycle queue, run first, cancel all queued work from that session, and prevent later input while cleanup is pending. This reserved path prevents ordinary player traffic from starving cleanup while keeping total work bounded by the per-tick command limit. `enqueue()` returns `false` for malformed, unsupported, or overloaded input; composition code must apply its documented disconnect or backpressure policy. Events are returned with the tick instead of accumulating in an unbounded internal output queue.

The fixed-rate loop uses an injected monotonic clock. Catch-up work is capped per poll without silently dropping simulation ticks. A backwards clock fails closed. Tests can call `WorldSimulation::tick()` directly for exact replay without consulting wall time.

The default limits are:

- 100 players, 4,096 normal queued commands with 4 MiB of estimated data, a reserved lifecycle queue of 256 disconnects with 64 KiB, and 2,048 total commands processed per tick;
- horizontal coordinates from -30,000,000 through 30,000,000, vertical coordinates from -64 through 1,024, and finite orientation values;
- 12 blocks of total validated client-predicted displacement per elapsed tick, with no more than five idle ticks of movement credit, plus a 0.001-block reconciliation tolerance; and
- 256 Unicode code points and 1,024 UTF-8 bytes per chat message, four initial chat tokens, and one replenished token per second at 20 TPS.

Emote IDs must use canonical lowercase UUID text. The simulation accepts at most one emote per player in any five-tick window, retains only one last-accepted tick per joined player, and publishes `EmotePerformed` only to peers. Client-supplied actor identity, duration, account identifiers, platform identifiers, and flags never enter the command or authoritative event.

Limits are explicit `SimulationLimits` configuration values and remain provisional until retail-client observation and abuse testing resolve RFC 0008's open questions.

## Player lifecycle and peer events

A join command carries the server-assigned session ID plus the authenticated identity and display name. The simulation rejects duplicate sessions, duplicate live identities, and joins beyond capacity. A successful `PlayerJoined` event contains the fixed spawn state, a deterministically ordered snapshot of existing peers, and all current recipient session IDs. Adapters use that single event to make the joining player and existing peers visible to each other.

Movement commands carry a server-local monotonically increasing sequence, bounded client-predicted feet position and delta, orientation, jump edge, and movement mode. They are applied only by the authoritative world tick. The simulation validates the whole requested displacement against tick-derived credit and world coordinates before performing any collision query, then resolves the bounded motion against current authoritative terrain and jump state. Stale, too-fast, and unannounced grounded-to-airborne frames produce a self-only `MovementCorrected` snapshot without changing position. Terrain-constrained frames atomically commit the resolved position, reset the owner's prediction, and publish the matching actor transform only to visible peers. Unconstrained frames, including rotation-only updates, are published only to peers.

The default flat generator places grass at Y=63, making its normal feet surface Y=64, but grounding is derived from collision beneath the player's actual position rather than that constant. A grounded jump edge authorizes the transition for a bounded two-tick window so an edge-only ground frame followed by the upward prediction is accepted; repeated airborne jump flags do not create another transition. Vertical resolution prevents floor and ceiling penetration. Successful block mutation immediately refreshes terrain support for every player. `PlayerSnapshot` exposes `VerticalState` and the validated predicted vertical velocity so adapters can project the authoritative on-ground bit and diagnostics can inspect motion.

This MVP follows the bounded client-predicted movement shape used by established Bedrock servers: it does not invent gravity steps in ticks where no movement frame arrives. Client collision/ground hints remain advisory and never determine authoritative state. Terrain collision is defined in feet coordinates with a 0.6-block-wide, 1.8-block-high player box and a 0.6-block step height. Authoritative block state supplies collision shapes; the old fixed Y=64 plane is only a compatibility fallback for isolated simulations constructed without a world.

## Block interaction authority

Block interaction follows a PMMP-aligned server-authority boundary. The network adapter validates and bounds the interaction shape, but client item descriptors, counts, auxiliary values, stack-network IDs, block runtime IDs, reported player position, and prediction fields never become inventory or world authority. The clicked block and face are bounded intent, not state claims. For a placement transaction, the bounded requested hotbar slot is applied first. The simulation then resolves the selected server-owned stack and current canonical world state, validates the face, clicked block, destination, reach, and player collisions, and either performs one atomic placement/decrement or emits authoritative block and slot correction.

Main-inventory and cursor moves follow the same boundary. A decoded Take, Place, or Swap request becomes one immutable simulation command whether it arrived in `ItemStackRequest` or embedded in `PlayerAuthInput`. The player inventory stages every action, validates authoritative slot contents, counts, capacity, and stack-network lineage, then commits the whole request or none of it. Successful changed stacks receive fresh positive server IDs and the response reports authoritative affected slots. Rejected, stale, unsupported-container, and oversized-count requests leave state untouched and trigger an error plus a bounded main-inventory and cursor repair. Requests in one packet remain ordered, but each request is its own atomic transaction, matching PocketMine-MP's authority model.

Break state is likewise server-owned. Start and continuation actions carry a validated target, while the retail abort sentinel is normalized into a targetless command with a neutral face before it reaches the simulation. Aborting clears the active break without interpreting sentinel coordinates as a world position. The advertised 18-tick grass rate drives crack progress. Following PocketMine-MP's retail-compatible path, predicted completion revalidates reach and current canonical block state and does not fail only because client and server tick boundaries observed different elapsed counts.

Disconnect removes both session and identity indexes before producing `PlayerDisconnected` for the remaining peers. Repeated disconnects cannot leave a ghost player.

## Chat security

Chat accepts non-empty valid UTF-8 without C0 controls or DEL, rejects messages beginning with `/` for the future command boundary, and enforces byte and character limits before enqueueing. Every sender has an independent tick-based token bucket and monotonically increasing chat sequence. Rejected rate-limited sequences cannot later be replayed.

`ChatBroadcast` attribution comes from the authenticated player state established at join, never from message text or a client-supplied sender field. Log consumers must still encode or escape structured fields for their output format; raw chat should not be interpolated into terminal control streams.
