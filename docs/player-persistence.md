# Player persistence

Bedriox stores one bounded authoritative profile per authenticated player in
`player_data/<uuid>.dat`. The normalized authenticated UUID owns the file;
player names and XUIDs are metadata and never select a path.

Profiles use schema-versioned little-endian NBT and are limited to 64 KiB.
The current schema stores the authenticated identity metadata, first and last
played timestamps, world name, exact position and rotation, survival game
mode, the 36-slot main inventory, selected hotbar slot, and cursor stack.
Inventory entries contain canonical names such as `minecraft:grass_block` and
counts. Bedrock stack network IDs are never persisted and are allocated again
for each play session.

Authentication completes before a profile is loaded. A returning position is
restored exactly when its world is the active world; Bedriox does not move the
player because of collision, headroom, liquid, or terrain checks. A missing
profile starts at the world's calculated spawn. A saved unavailable world also
uses that spawn without discarding the saved inventory or identity history.

`PlayerLoginEvent` runs after restoration and before StartGame, inventory
bootstrap, or chunk scheduling. A plugin may cancel admission or choose a
bounded destination and orientation. The resulting state is used consistently
by the client bootstrap and the authoritative `Player`. After the client
acknowledges initialization, Bedriox sends one authoritative movement reset so
terrain loading cannot replace the restored camera direction. Movement bundled
with that acknowledgement remains pre-initialization input; later movement is
handled normally. `PlayerJoinEvent` continues to mean that the client completed
initialization and entered play.

Writes use a temporary sibling file, flush it, and atomically replace the
profile. Corrupt, oversized, unreadable, and unsupported profiles are kept in
place and only that login is rejected. They are never treated as a new player
and never overwritten with defaults. Failed saves remain dirty and are retried
through the bounded player save queue.

The relevant settings are:

- `players.autosave-interval-ticks=6000`
- `players.save-per-tick=8`

Graceful disconnect captures committed server-owned state before removing the
player. Graceful shutdown drains disconnect saves, retries failed snapshots,
then closes the world provider and transport.
