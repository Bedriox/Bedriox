# Usage

Release installations use this layout:

```text
server/
|-- Bedriox.phar
|-- bedriox.cmd
|-- bedriox
`-- bin/
```

Download the PHAR, checksum, and launcher from the matching Bedriox release.
Verify the SHA-256 checksum, then extract the qualified Runtime archive for the
machine as the adjacent `bin/` directory. Composer and a source checkout are
not required. Run `bedriox.cmd` on Windows or `./bedriox` on Linux and macOS
from the directory where server data should be stored.

`Bedriox.phar` deliberately keeps the same filename for every release. Routine
server upgrades replace that file without requiring changes to either launcher.
Replace `bin/` only when the release requires a different Runtime build.

## Public PHAR installation

Normal server installations use the released `Bedriox.phar`; they do not need
the source tree or Composer. On Linux and macOS, run:

```shell
curl -fsSL https://bedriox.com/install.sh | sh
```

On Windows PowerShell, run:

```powershell
irm https://bedriox.com/install.ps1 | iex
```

The installer detects the platform, downloads the released PHAR and matching
qualified PHP Runtime, verifies both SHA-256 values, performs a startup probe in
a staging directory, and then starts the server. A Unix installation piped to
`sh` reconnects the first-run setup wizard to the controlling terminal; when no
interactive terminal exists, installation completes without starting and
prints the exact manual start command. Pass `--no-start` to the Unix script or
`-NoStart` to the downloaded PowerShell script to prepare the server without
starting it. The installer refuses to replace an existing directory.

The default destination is a new `bedriox-server` directory beneath the
directory where the installer was invoked. This keeps the PHAR, Runtime,
configuration, worlds, plugins, and logs together without overwriting unrelated
files in the current directory. The first-run server process runs from inside
that destination, so its data is created there.

Composer is used to build the release PHAR, not to run it. Source-development
instructions below remain separate from the public PHAR installation.

Source installations can install a qualified Runtime archive from a local
file. Its name and digest must exactly match `bedriox.lock.json`:

```shell
php tools/install-runtime.php path/to/bedriox-runtime-windows-x86_64.zip
```

Use the matching `.tar.gz` archive on Linux or macOS. The installer accepts no
URLs, extracts into a bounded same-volume staging directory, verifies the
archive and inner manifest hashes, probes the packaged PHP, and restores the
previous `bin/` if activation fails.

Inspect either packaged layout with `bedriox.cmd --version` on Windows or
`./bedriox --version` on Linux and macOS. Start it with `bedriox.cmd` on
Windows or `./bedriox` on Linux and macOS. The explicit `serve` command remains
supported for scripts. These launchers use only the adjacent `bin/php(.exe)` and
`bin/php.ini`; there is no `PATH` or system-PHP fallback. They preserve the
operator's working directory.

On the first interactive start, Bedriox displays its identity banner and opens
the setup wizard. The wizard explains the GPL license, notes that Enter accepts
each displayed default, validates the common server and world choices, shows a
summary, and atomically publishes `server.properties`. Use
`bedriox.cmd --skip-wizard` (or
`./bedriox --skip-wizard`) for unattended installation with defaults. Existing
configuration is never replaced by either path.

For source development only, inspect the current build with:

```shell
php bin/bedriox --version
```

Start the bounded protocol-2193 development server (Minecraft 1.26.50 wire authority; 1.26.51 qualified client) with FULL authentication:

```shell
php bin/bedriox
```

The first successful configuration load creates two local files in the current working directory. `server.properties` contains the common identity, network, authentication, world, and feature switches most operators need. `bedriox.settings` contains advanced runtime, worker, chunk-orchestration, persistence, plugin-limit, logging, and crash-report controls. Both files are ignored by Git. Existing files are parsed as written; Bedriox does not migrate or rewrite values between them.

Configuration precedence is built-in defaults, then `server.properties`, then `bedriox.settings`, then command-line overrides. Each file has its own strict key allowlist. For example:

```shell
php bin/bedriox serve --port=19133 --name="Bedriox Test" --max-players=8 --view-distance=6
```

`xbox-auth=true` performs trusted Minecraft JWK discovery before the UDP port is bound and fails closed if discovery is unavailable or invalid. For isolated development only, `xbox-auth=false` or `--auth=SELF_SIGNED` enables legacy self-signed login and prints an explicit security warning; it is never a fallback from FULL.

Options use exact `--name=value` syntax. Bind addresses must be literal IPv4 values, ports are 1 through 65535, names are at most 128 UTF-8 bytes, and player limits are 1 through 1024. Duplicate, unknown, empty, ambiguous, and out-of-range options fail before startup. Existing CLI flags remain supported; `--memory-limit`, `--chunk-generation-queue-size`, and `--chunk-loading-prefetch-radius` override the corresponding file values.

Both files use one `key=value` entry per line. Blank lines and lines beginning with `#` are ignored. Unknown keys, duplicate keys, malformed lines, noncanonical numbers, invalid booleans, and files over 64 KiB fail closed before the UDP socket is bound.

`server.properties` supports:

| Setting | Default | Accepted value |
| --- | --- | --- |
| `server-name` | `Bedriox Server` | 1–128 bytes of UTF-8 |
| `motd` | `Powered by Bedriox` | 1–128 bytes of UTF-8 without control characters |
| `server-ip` | `0.0.0.0` | Literal IPv4 address |
| `server-port` | `19132` | 1–65535 |
| `max-players` | `20` | 1–1024 |
| `memory-limit` | `500MB` | `0` for unlimited, or a canonical integer followed case-insensitively by `MB`, `GB`, `MiB`, or `GiB`; bounded from 128 MB through 64 GiB |
| `xbox-auth` | `true` | Exactly `true` for FULL authentication or `false` for explicit development-only self-signed authentication |
| `enable-console` | `true` | Exactly `true` or `false` |
| `enable-plugins` | `true` | Exactly `true` or `false` |
| `white-list` | `false` | Exactly `true` or `false`; operators bypass whitelist admission |
| `level-name` | `world` | 1–64 bytes of UTF-8 without control characters |
| `level-type` | `default` | `default` for seeded terrain or `flat` for the fixed classic profile |
| `level-seed` | `0` | Signed 32-bit decimal integer |
| `gamemode` | `survival` | `survival`, `creative`, `adventure`, or `spectator` |
| `difficulty` | `normal` | `peaceful`, `easy`, `normal`, or `hard` |
| `pvp` | `true` | Exactly `true` or `false`; controls player-versus-player damage |
| `view-distance` | `4` | 1–32 chunks |

`bedriox.settings` supports:

| Setting | Default | Accepted value |
| --- | --- | --- |
| `runtime.ticks-per-second` | `20` | 1–100 |
| `workers.core-count` | `auto` | `auto` or 0–32 |
| `chunk-sending.spawn-radius` | `4` | 1 through the configured view distance |
| `chunk-sending.per-tick` | `8` | 1–64 |
| `chunk-generation.per-tick` | `4` | 1–64 |
| `chunk-generation.queue-size` | `1024` | 1–65536 |
| `chunk-loading.prefetch-radius` | `1` | 0–8 chunks; visible plus prefetched radius must not exceed 32 |
| `chunk-cache.limit` | `auto` | `auto` or 16–65536 chunks; `auto` resolves to every configured player view, the retained world spawn, and four transition columns (2425 at the defaults) |
| `chunk-saving.per-tick` | `8` | 1–64 dirty chunks per scheduled autosave tick |
| `chunk-unloading.grace-ticks` | `600` | 0–6000 ticks before an unretained chunk becomes eligible for safe unload |
| `chunk-unloading.per-tick` | `96` | 1–1024 eligible chunks examined per tick, additionally bounded by elapsed time |
| `memory-management.enabled` | `true` | Exactly `true` or `false` |
| `memory-management.soft-threshold` | `70` | 1–98 percent; must be below the high threshold |
| `memory-management.high-threshold` | `85` | 2–99 percent; must be between the soft and critical thresholds |
| `memory-management.critical-threshold` | `92` | 3–100 percent; must be above the high threshold |
| `level.autosave-interval-ticks` | `6000` | 20 through 72000 ticks between autosave scheduling cycles |
| `players.autosave-interval-ticks` | `6000` | 20 through 72000 ticks between player autosave scheduling cycles |
| `players.save-per-tick` | `8` | 1 through 64 dirty player profiles saved during each autosave tick |
| `movement.rewind-history-size` | `40` | 1 through 1200 processed input ticks retained by the client for authoritative movement correction |
| `updates.enabled` | `true` | Exactly `true` or `false`; controls asynchronous release checks |
| `updates.notify-operators` | `true` | Exactly `true` or `false`; controls in-game notices for players with `bedriox.update.notify` |
| `plugins.maximum` | `64` | 0–256 total admitted plugins |
| `logging.level` | `INFO` | `DEBUG`, `INFO`, `NOTICE`, `WARNING`, `ERROR`, or `CRITICAL` |
| `logging.console` | `true` | Exactly `true` or `false` |
| `logging.console-colors` | `auto` | `auto`, `true`, or `false` |
| `logging.file` | `true` | Exactly `true` or `false` |
| `logging.file-max-size` | `16777216` | 65536–1073741824 bytes |
| `logging.file-history` | `10` | 0–100 archives |
| `logging.protocol-trace` | `false` | Exactly `true` or `false` |
| `crash-report.include-player-identifiers` | `true` | Exactly `true` or `false` |

The protected `--spawn-x`, `--spawn-y`, and `--spawn-z` CLI overrides remain available only when all three are populated. Without them, the selected generator calculates a safe default. `default` performs a bounded dry-land search over generated terrain; `flat` uses `(0, 64, 0)`.

The generator, generator algorithm version, and seed recorded in an existing world's `level.dat` remain authoritative when it is reopened. Changing `level-type` or `level-seed` does not silently convert stored chunks. Unsupported future generator versions fail before missing terrain can be created. New worlds use the configured values and create the native `level.dat`, `levelname.txt`, and `db/` layout. `default` currently selects the version-three Overworld, Nether, and End generator family; `flat` retains its fixed bedrock, dirt, and grass profile. Delete development worlds created by an older default-generator version before testing this build rather than mixing old and new chunks.

Settings for independent query ports, resource packs, and other unfinished features are deliberately not accepted yet. This prevents apparently valid options from silently doing nothing.

Update checks begin only after the server is listening and run through the bounded core worker pool, never the simulation thread. Bedriox sends no server, player, plugin, or installation identity to the update service. A failed or malformed response is isolated and retried later; it never prevents startup or stops a running server. The current version selects the stable or beta channel automatically. Console notices appear once per discovered version, eligible operators receive one notice per connection, and `version` includes the known update until the server is upgraded.

`level.autosave-interval-ticks` controls how often the runtime schedules dirty-world work. At the default 20 ticks per second, `6000` ticks is five minutes. `chunk-saving.per-tick` bounds each autosave step so a large dirty queue is drained over multiple ticks rather than written all at once. Dirty chunks are still saved before eviction, and graceful shutdown performs a complete durability flush rather than applying the per-tick limit.

`players.autosave-interval-ticks` and `players.save-per-tick` independently bound UUID-keyed player profile work. See [player persistence](player-persistence.md) for the stored fields, exact restore behavior, and recovery rules.

When `enable-console=true`, Bedriox reads commands without blocking the server loop. On Windows, a lifecycle-owned helper waits on the console handle while the server polls its bounded output; this keeps networking and simulation responsive between commands. Command lines, queued commands, arguments, and commands executed per poll are bounded. Set `enable-console=false` for detached environments without an operator input stream. Plugin-owned console output uses the normal structured logger and therefore also appears in `logs/server.log` when file logging is enabled.

The qualified protocol family remains alpha software. Unknown commands fail with a bounded console response.

## Commands and permissions

The console and Bedrock slash-command input share one bounded dispatcher. Built-in commands include `version`, `help`, `list`, `stop`, `op`, `deop`, `permission`, `whitelist`, `kick`, `ban`, `ban-ip`, `banlist`, `pardon`, `pardon-ip`, `gamemode`, `defaultgamemode`, `difficulty`, `give`, `clear`, `enchant`, `effect`, `particle`, `title`, `tell`, `say`, `me`, `plugins`, `seed`, `setblock`, `setworldspawn`, `spawnpoint`, `save-all`, `save-on`, `save-off`, `gc`, `kill` (alias `suicide`), `summon`, `time`, `weather`, and `tp` (alias `teleport`). The console always has administrative authority. Players receive only the commands currently available to their UUID when joining. Successful changes are green, informational results are gray, unchanged-state warnings are yellow, and failures or denied input are red. Successful operator actions are also recorded by the console and shown in muted text to other online operators. Affected players receive direct notice when their game mode or operator status changes, while private and query output remains scoped to its sender.

`ban <player> [reason]`, `ban-ip <address|online-player> [reason]`, `banlist [players|ips]`, `pardon <player>`, and `pardon-ip <address>` manage the atomic `bans.json` store. Player bans match the authenticated name and retained UUID when available; address bans match the transport IPv4 address. Bans are enforced before play initialization, and banning a currently connected player or address uses the normal cancellable kick lifecycle with a visible reason. Plugins may observe or cancel `BanListChangeEvent` and observe committed `BanListChangedEvent`.

Every admitted departure announces `<player> left the game` once by default. `PlayerQuitEvent` may replace or suppress that public message, while the console separately records whether the player disconnected, timed out, lost transport, was kicked or banned, who performed an attributed action, and the private reason.

`difficulty [peaceful|easy|normal|hard]`, `setworldspawn [position]`, and `spawnpoint [player] [position]` change authoritative state rather than only client presentation. Difficulty and world spawn are written to the native world metadata and synchronized to players in that world. Personal spawn points are stored in the player profile and pass through the normal cancellable respawn event. Typed pre/post events cover default-game-mode, difficulty, world-spawn, and personal-spawn changes.

`save-all` queues saves for every loaded world. `save-off` pauses routine world, entity, and player autosaves; `save-on` resumes them. Explicit saves, disconnect persistence, world unloads, and graceful shutdown remain durable even while routine autosave is disabled.

`whitelist status|on|off|list|add <player>|remove <player>|reload` manages admission through the atomic `whitelist.json` store. Enabling or reloading an enabled whitelist removes connected non-operators who are no longer admitted. Name-only entries are upgraded to the authenticated UUID on a successful login. The base permission is `bedriox.command.whitelist`; each action also requires its matching `bedriox.command.whitelist.<action>` node. Plugins may use `Server::getWhitelist()` and observe `WhitelistChangedEvent`.

`gamemode <mode> [player]` accepts the canonical names and numeric aliases for survival, creative, adventure, and spectator. `give <player> <item> [amount]` resolves canonical `minecraft:*` identifiers through the active gameplay catalog. Both commands enqueue normal authoritative simulation work; they do not mutate network sessions directly.

`tp <player>`, `tp <x> <y> <z> [yaw pitch]`, and their explicit-target forms enqueue the same authoritative teleport used by plugins. Coordinates accept `~` relative values. `bedriox.command.teleport` permits self teleportation and `bedriox.command.teleport.other` permits selecting another subject. Destination collision is deliberately not treated as command policy; the operator or plugin choosing the coordinate owns that decision.

`time set <day|noon|sunset|night|midnight|sunrise|ticks>` changes the current world's bounded daylight-cycle position. `time add <ticks>` advances it, `time query` reports total time, day, and time-of-day, and `time stop` or `time start` controls progression for the running server. Time belongs to the world, advances once per simulation tick, is saved in `level.dat`, and is synchronized immediately after a command, periodically during play, and from the current value when a player joins. Stopping the cycle is a runtime control and intentionally resumes after a server restart. The command requires `bedriox.command.time`.

`weather <clear|rain|thunder> [durationSeconds]` changes weather in the executing player's world, or the default world when run from the console. `weather query` reports that world's current state and remaining duration. Omitting the duration selects a deterministic value from 300 through 900 seconds. Weather survives restart and is synchronized on join and world transfer. The command requires `bedriox.command.weather`.

Water and lava use authoritative scheduled updates that operate only in loaded chunks. Both flow downward and outward with distinct update speeds; water renews from valid adjacent sources, unsupported flow decays, and contact with lava forms the appropriate solid block. Buckets atomically change the world and held inventory. Flow work is deduplicated and bounded per tick so a cascade cannot monopolize movement, chat, or inventory processing.

`effect <player> <effect> [seconds|infinite] [amplifier] [hideParticles]` adds a typed current-version effect through the same cancellable authoritative path available to plugins. `effect <player> clear [effect]` removes one or every active effect. Effect names omit the `minecraft:` prefix in command input. The command requires `bedriox.command.effect`.

`particle <particle> [x y z]` is player-only because its world is the executing player's current loaded world. Omitting coordinates uses the player's position, and relative `~` coordinates are accepted. The request uses ordinary world visibility and particle budgets rather than broadcasting a raw packet. The command requires `bedriox.command.particle`.

Potion items use their current creative-catalog variants. Drinkable potions complete through the ordinary held-item use action, splash and lingering potions launch from ordinary item use, and tipped arrows retain their potion variant when fired. Brewing stands use three bottle slots, one ingredient slot, blaze-powder fuel, and a 400-tick operation. See [effects, potions, and brewing](effects-and-potions.md) for authority, persistence, events, and qualification boundaries.

`kill` targets the executing player, while `kill <targets>` accepts an exact player name, an entity UUID, or the bounded `@a`, `@e`, `@n`, `@p`, `@r`, and `@s` selectors. Selectors support `name`, `type`, `distance`, `limit`, and `sort` filters, with at most 128 results. Self-targeting requires `bedriox.command.kill.self`; every selection containing another entity requires `bedriox.command.kill.other`. Accepted targets enter the normal authoritative damage and death lifecycle, so plugins can observe or cancel the damage instead of the command deleting actors directly.

The retail player inventory uses a server-synchronized dynamic window. Moving, splitting, placing, dropping, and picking up admitted items are authoritative: invalid or stale client predictions are corrected to the server-held slots without changing unrelated inventory state.

`op <online-player>` and `deop <online-player>` change operator authority. `permission list <online-player>`, `permission grant <online-player> <node>`, and `permission revoke <online-player> <node>` manage explicit grants. A grant such as `example.*` covers descendants such as `example.build`; operators satisfy every permission. Targets must be online so Bedriox resolves the authenticated UUID instead of trusting a mutable name. Effective changes are projected to the connected client immediately: operator changes refresh abilities and command visibility, while grant/revoke refreshes the available command list. Repeating an already-effective assignment produces no redundant network update.

Assignments are atomically stored in the ignored local `permissions.json` file. Back up that file with other server data. Invalid, oversized, linked, or corrupt permission data fails startup instead of silently discarding authority rules.

`gc` and `gc status` report process memory, pressure, adaptive cyclic-collector state, and authoritative chunk residency. `gc run` forces PHP cycle collection plus allocator-cache cleanup. `gc chunks` immediately runs one bounded safe-unload pass. These operations require `bedriox.command.gc`; they never discard retained or dirty authoritative chunks.

## Plugins

Production plugins are strongly signed `.phar` files placed directly in `plugins/`. Bedriox creates this directory on first start, validates every archive and dependency plan before executing entry points, and never scans source directories. Installing PluginTools adds its separate development source loader; PluginTools owns folder discovery and submits validated definitions through the public admission boundary. Plugin-owned writable data is stored separately under `plugin_data/<PluginName>/`.

Build discovered source plugins with `makeplugin <PluginName> [--overwrite]` from [Bedriox PluginTools](https://github.com/Bedriox/PluginTools). Loading a plugin does not require changing `phar.readonly`; PluginTools applies that setting only to its isolated packaging process. See the [plugin guide](plugins.md) for source development, standalone CLI usage, the manifest, lifecycle, events, commands, priorities, failure behavior, and public API.
