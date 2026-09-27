# Packet lifecycle

Packets cross several ownership boundaries. Each boundary validates and narrows data before it can progress; no socket callback or decoded packet directly mutates authoritative state.

## Inbound path

```text
UDP datagram
  -> RakNet framing, reliability, ordering and session state
  -> ConnectedTransport application payload
  -> login or play channel batch decryption/decompression
  -> Protocol typed packet decode
  -> session-phase, actor, sequence and policy validation
  -> immutable simulation command or bounded session-local action
  -> authoritative world tick
```

RakNet owns datagrams and delivery semantics but does not interpret Bedrock game packets. Protocol owns bounded wire decoding but does not decide whether a player may act. Bedriox owns phase, identity and gameplay policy.

During login, `BedrockLoginChannel` and `LoginSession` produce explicit effects. Cipher ownership transfers exactly once when login becomes ready. The signed client-data `DeviceOS` claim is parsed into a bounded private value, but it is never treated as verified hardware identity or disclosed in peer actor packets. During play, `BedrockPlayChannel` admits only registered, typed packets appropriate to the current phase. Movement, chat, initialized own-actor emote UUIDs, missed-swing input, validated fixed-flat block intents, selected-slot changes, stack requests, grass placement, and respawn readiness become simulation commands; initialization, radius negotiation, terrain requests, and inventory-window state remain bounded session-local orchestration. Respawn coordinates and runtime IDs supplied by the client are ignored because the authenticated session and authoritative spawn state select the player and destination. Both the player-action and client-ready respawn forms are bounded, idempotent inputs. Emote duration, external identity, platform, and flag claims are dropped at this boundary. The simulation owns break timing, arm-swing publication, health, inventory, and canonical mutation. It emits health/death/respawn, arm-swing, crack, block-change, placement/correction, inventory reconciliation, and held-item events, while the runtime filters world and actor recipients against delivered chunk and actor views before encoding network runtime IDs. A standalone inbound animation packet is accepted only as an advisory no-op; it cannot create or duplicate an authoritative swing. Retail `StopDestroyBlock` is targetless; the adapter normalizes its sentinel into a targetless command with a neutral face so the simulation clears the stored target without inventing coordinates. An initialized inventory-open request opens at most one main-inventory window; a matching close is acknowledged before the window may reopen. The serialized target actor and client item descriptor are never trusted. A placement transaction applies its bounded selected-slot intent before placement; the selected server inventory stack and canonical world state alone authorize the mutation. Client item, count, stack-network, runtime-ID, reported-player-position, and prediction fields remain reconciliation hints and cannot create inventory or veto an otherwise valid server-authorized action. The clicked block and face remain bounded intent that the simulation validates against authoritative world state. Unrelated typed transactions remain explicit notification-only no-ops.

Dedicated and PlayerAuthInput-embedded item-stack requests share one command path. The adapter admits only bounded main-inventory, hotbar, and cursor slot intent; Take, Place, and Swap actions are applied by the authoritative player aggregate at a world tick. Success is acknowledged with authoritative affected slots. A stale stack ID, unsupported action or container, invalid count, or failed merge returns an error and a complete bounded main-inventory and cursor correction without disconnecting the healthy session.

Current `ItemUseOnEntity` traffic first applies its bounded hotbar selection and then becomes a version-independent attack or typed interaction command in the same ordered simulation batch. Reported positions, click vectors and item data are discarded; the authenticated player, selected server-owned stack and server-resolved target remain authoritative. Accepted health, hurt, knockback, death, interaction and attack-state effects return through typed world events and visibility-filtered packet projection.

Non-player living actors cross the wire boundary only through typed simulation events whose recipient lists have already passed runtime chunk-visibility policy. The packet projector emits the complete current actor spawn snapshot with canonical identifier, bounded living attributes, initialized metadata, and empty dynamic properties and links unless authoritative gameplay supplies them. Later events preserve movement-before-motion, health-before-hurt, death-before-removal ordering. Movement for each recipient is accumulated into bounded ordered batches, and motion is included only when authoritative velocity changed; this keeps packet compression and protocol tracing proportional to batches instead of multiplying both costs by every moving actor. The projector does not discover viewers, allocate entities, run AI, or infer recipients from live sessions.

The current game mode is authoritative survival. Explicit start/stop-flight input, legacy flight actions, and ability requests cannot change that authority: the channel re-sends the survival ability layer without discarding movement or jumping carried by the same input frame. Both the jump edge and its held state survive play-channel translation and movement coalescing. Ordinary walking and jumping continue through the simulation command path. An empty `ServerSettingsRequest` is a typed notification-only no-op because Bedriox does not advertise a custom settings form.

Malformed, unknown, out-of-phase, wrong-actor, oversized, or queue-exhausting input fails deterministically and closes only the affected session unless the transport itself cannot continue safely. Unsupported gameplay must be explicitly rejected or represented as a narrow typed no-op; it must not be registered as unbounded opaque input.

The command path is a complete current-client conversation: initialization advertises enabled commands, typed member/operator abilities, and bounded raw-text overloads; spawned clients submit typed command requests; and Bedriox returns typed command output with the authenticated UUID and original actor-correlation ID. Network origin identity is correlation data only; authorization always uses the authenticated session UUID and server-owned operator/permission state. Effective operator changes refresh abilities before the available command list, while explicit grant/revoke changes refresh command visibility only. Plugins receive the version-independent command sender and event APIs, never command packet IDs or wire enums.

## Authoritative processing

`ServerRuntime` drains only configured amounts of transport, session, command, streaming, and tick work per poll. `WorldSimulation` is the sole owner of mutable player state. Commands are revalidated at that boundary and produce immutable events such as join, movement, correction, chat, damage, death, respawn, emote, and disconnect.

Client position, collision, actor, attribution, timing, inventory, and block-state claims are hints until validated. Stale or invalid predictions cannot modify state, and item or block claims cannot replace server-owned inventory or world authority. A routine movement disagreement preserves the complete unsigned input tick and returns `CorrectPlayerMovePrediction` with authoritative position, grounded state, and zero player delta. `MovePlayer` remains limited to teleport and respawn lifecycle projection. Harmless stale input is a bounded no-op rather than another correction. The current serverbound `MovementPredictionSync` notification is consumed as a bounded advisory no-op even when it arrives early or repeatedly; its reported actor, flags, dimensions, speeds, vitals, and flying state never become server authority. Network input never receives a mutable reference to players, worlds, queues, or cryptographic state. The advertised rewind history defaults to 40 ticks and is bounded by `movement.rewind-history-size`.

The current client may also send an eating `ActorEvent` while an authoritative timed food use is active. Bedriox accepts it as an advisory animation signal only; the selected server stack, registered use duration, and simulation completion determine consumption. Mining predictions embedded in `PlayerAuthInput` are ordered after the corresponding block action, then reconciled against the post-break authoritative held stack. The response contains only the affected hotbar slot and its durability correction, avoiding a complete inventory repair for a valid tool-use prediction.

## Outbound path

```text
authoritative event or session bootstrap work
  -> BedrockWorldEventPacketEncoder or play-channel encoder
  -> reusable Protocol packet/frame projection and bounded batch
  -> shared clear-envelope compression when compatible
  -> per-session encryption and delivery ordering
  -> ConnectedTransport payload
  -> RakNet reliable delivery
  -> UDP datagram
```

World block IDs translate to network IDs only at serialization. Simulation positions translate to Bedrock eye-position coordinates only in the protocol adapter. Peer events are projected per recipient and cannot expose private login data. Identical chat, combat, posture, and non-player movement fan-out reuses one immutable packet or frame projection across eligible recipients. Clear compressed envelopes may be cached only across sessions with the same protocol and compression contract; encryption state, ordering counters, and transport state are always applied per session.

Player-list publication occurs at authoritative join. Actor add, including the
complete initialized metadata baseline, is emitted only after the recipient has
been sent the actor's chunk.
Leaving that sent view removes the actor while retaining list membership;
returning republishes the actor with that baseline. Accepted missed attacks,
mining, combat, and plugin-requested arm swings are likewise sent only to peers
that currently own the actor view. Accepted visible-peer movement
uses absolute actor movement and emits posture metadata only when sneaking or
sprinting changes. Authoritative corrections use owner-only player reset
packets. Departure sends actor removal before player-list removal. Encoding or
bounded fan-out failure is attributed to the event's causal session so healthy
recipients remain connected; an ownerless event failure closes the runtime as
an invariant violation.

Fatal damage sends the authoritative zero-health attribute and actor death
animation, then begins the searching-for-spawn/death-info conversation. A
client-ready reply receives server-ready and a complete authoritative respawn
projection. Valid duplicate or reordered respawn notifications are harmless;
malformed framing, impossible enum values, and exhausted command limits retain
the normal per-session failure policy.

## Diagnosing failures

Identify the last completed boundary before changing code. A discovery-only trace does not justify a connected-session change; a decoded packet failure does not justify a RakNet change; correct world cells with incorrect serialized palettes point at translation or serialization rather than generation.

Diagnostics may record an allowlisted event, phase, packet ID, reason code, and exception class. They must not record JWTs, keys, raw encrypted payloads, client GUIDs, account identifiers, or unbounded packet bodies. Every reproduced failure receives a focused regression at that boundary.
