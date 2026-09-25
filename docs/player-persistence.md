# Player persistence

Bedriox stores one bounded authoritative profile per authenticated player in
`player_data/<uuid>.dat`. The normalized authenticated UUID owns the file;
player names and XUIDs are metadata and never select a path.

Profiles use schema-versioned little-endian NBT and are limited to 128 KiB.
The current schema stores the authenticated identity metadata, first and last
played timestamps, world name, exact position and rotation, game mode, the
36-slot main inventory, selected hotbar slot, cursor stack, four armor slots,
offhand slot, nutrition state, and the player's private 27-slot Ender Chest.
Current health is stored as a bounded float from 0 through 20.
Inventory entries contain canonical names such as `minecraft:grass_block` and
counts, bounded durability damage, and up to 100 KB of typed custom item NBT per stack. Bedrock stack network IDs are never persisted and are allocated again
for each play session.

Authentication completes before a profile is loaded. A returning position is
restored exactly when its world is the active world; Bedriox does not move the
player because of collision, headroom, liquid, or terrain checks. A missing
profile starts at the world's calculated spawn. A saved unavailable world also
uses that spawn without discarding the saved inventory or identity history.
A profile saved at zero health is restored alive at full health at the active
world spawn rather than reopening an incomplete death conversation. Schema-one
through schema-six profiles migrate in memory with compatible defaults and are
written as schema seven on the next successful save.

`PlayerLoginEvent` runs after restoration and before StartGame, inventory
bootstrap, or chunk scheduling. A plugin may cancel admission or choose a
bounded destination and orientation. The resulting state is used consistently
by the client bootstrap and the authoritative `Player`. Rotation is stored as
the standard NBT float list `[yaw, pitch]`. Returning orientation is supplied by
`StartGame`; the initialization acknowledgement does not send a second camera
or movement reset. Client movement yaw is normalized to `[0, 360)` and pitch is
reduced modulo 360 before entering the simulation, matching established server
behavior. `PlayerJoinEvent` continues to mean that the client completed
initialization and entered play.

The production player-storage process exclusively owns the profile directory. Login performs a bounded read before admission. Routine saves send immutable profile snapshots to its ordered bounded queue; only a successful acknowledgement for the exact player revision marks that state saved. The storage owner writes a temporary sibling file, flushes it, and atomically replaces the profile. Corrupt, oversized, unreadable, and unsupported profiles are kept in
place and only that login is rejected. They are never treated as a new player
and never overwritten with defaults. Failed saves remain dirty and are retried
through the bounded player save queue.

The relevant settings are:

- `players.autosave-interval-ticks=6000`
- `players.save-per-tick=8`

Graceful disconnect captures committed server-owned state before removing the
player. Graceful shutdown drains disconnect saves, applies exact revision
acknowledgements, closes the player store and world provider, and then closes
the transport.
