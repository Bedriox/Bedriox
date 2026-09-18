# Client journey contract

The client journey is Bedriox's cumulative user-visible regression baseline. A milestone may extend this contract but must not remove or weaken an earlier step. Automated tests prove deterministic boundaries; a qualified retail client confirms that the complete composition is accepted in practice.

## Permanent journey

1. Discovery returns the exact qualified Bedrock advertisement, including protocol, version, capacity, game-mode fields, and ports.
2. The client proceeds from discovery through RakNet offline and connected negotiation.
3. Authentication, encryption, resource-pack negotiation, and login complete without downgrade.
4. The client receives initialization and enters the world exactly once.
5. The spawn-radius terrain contains the expected grass, dirt, bedrock, air, biome, and height semantics.
6. The negotiated view continues loading nearest-first beyond the spawn column.
7. Walking across positive and negative chunk boundaries loads only the new edge without voids, stalls, excessive resend, or unbounded memory.
8. Standing, looking, walking, jumping, landing, sprinting, and crouching preserve authoritative position, gravity, collision, and breathing state.
9. Chat is attributed by the server and delivered in order.
10. Routine bounded actions appropriate to the implemented milestone, including authoritative grass breaking and placement, hotbar selection, inventory open or close, splitting a stack between hotbar slots, selecting the resulting stack, and placing from it, do not lose items, crash the process, or unexpectedly disconnect the session.
11. Disconnect releases player, session, chunk-view, queue, and cryptographic resources.
12. A clean reconnect repeats the journey without ghost state.
13. Once multiplayer is implemented, two clients see join, movement, chat, interaction results, and departure consistently.

Unsupported gameplay may return a documented bounded rejection or typed safe no-op, but it may not mutate state accidentally, retain unbounded data, crash the server, or produce a generic disconnect without a diagnostic reason.

Valid, bounded client notifications may cross nominal initialization boundaries because of platform timing and batching. Such notifications must be explicitly applied, deferred, corrected, or treated as safe no-ops; phase timing alone is not a disconnect condition.

## Automated evidence

Discovery uses independently parsed literal fields. RakNet and Protocol use independent golden vectors and malformed-input coverage. Runtime integration drives public component contracts. Terrain tests reconstruct cells, palettes, biome markers, and height maps. Simulation tests replay deterministic commands and resource limits. Regression tests pin every previously escaped failure.

Production encoder/decoder round trips alone are insufficient because both sides can share the same defect.

## Retail qualification record

For each gameplay-visible milestone, record:

- exact Bedriox and component commits;
- exact Minecraft client build and platform;
- non-secret effective settings and authentication mode;
- journey steps executed and observed results;
- duration for soak or movement tests;
- diagnostics summary and known limitations;
- tester and date without account identifiers.

Do not retain credentials, JWT chains, keys, GUIDs, raw authentication payloads, or personal packet captures. One successful join is not a completed qualification. Support claims remain narrower than the evidence.

## Milestone rule

All applicable automated steps must pass before retail testing. The milestone is complete only after the required retail steps pass and pushed CI is green. A failure stops progression, becomes a regression test, and is fixed in the owning layer before later work begins.
