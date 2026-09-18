# Usage

Install the qualified archive for this machine from a local file. The archive
name and digest must exactly match `bedriox.lock.json`:

```shell
php tools/install-runtime.php path/to/bedriox-runtime-windows-x86_64.zip
```

Use the matching `.tar.gz` archive on Linux or macOS. The installer accepts no
URLs, extracts into a bounded same-volume staging directory, verifies the
archive and inner manifest hashes, probes the packaged PHP, and restores the
previous `bin/` if activation fails.

Inspect the packaged server with `bedriox.cmd --version` on Windows or
`./bedriox --version` on Linux and macOS. Start it with `bedriox.cmd serve` or
`./bedriox serve`. These launchers use only the adjacent `bin/php(.exe)` and
`bin/php.ini`; there is no `PATH` or system-PHP fallback. They preserve the
operator's working directory.

For source development only, inspect the current build with:

```shell
php bin/bedriox --version
```

Start the bounded protocol-2193 development server (Minecraft 1.26.50 wire authority; 1.26.51 qualified client) with FULL authentication:

```shell
php bin/bedriox serve
```

The first successful configuration load creates `bedriox.settings` in the current working directory. It contains documented server, network, level, chunk-streaming, persistence, runtime, and diagnostic defaults. Edit that local file and restart Bedriox to apply changes. The file is intentionally ignored by Git so each installation can keep its own operator configuration.

Configuration precedence is built-in defaults, then `bedriox.settings`, then command-line overrides. For example:

```shell
php bin/bedriox serve --port=19133 --name="Bedriox Test" --max-players=8 --view-distance=6
```

FULL performs trusted Minecraft JWK discovery before the UDP port is bound and fails closed if discovery is unavailable or invalid. For isolated development only, `--auth=SELF_SIGNED` enables legacy self-signed login and prints an explicit security warning; it is never a fallback from FULL.

Options use exact `--name=value` syntax. Bind addresses must be literal IPv4 values, ports are 1 through 65535, names are at most 128 UTF-8 bytes, and player limits are 1 through 1024. Duplicate, unknown, empty, ambiguous, and out-of-range options fail before startup. The existing `--bind`, `--port`, `--name`, `--max-players`, and `--auth` flags remain supported; level and chunk keys have matching flags such as `--level-name`, `--seed`, `--view-distance`, `--spawn-radius`, `--chunks-send-per-tick`, `--chunks-generate-per-tick`, `--level-autosave-interval-ticks`, and `--chunks-save-per-tick`.

The settings file uses one `key=value` entry per line. Blank lines and lines beginning with `#` are ignored. Unknown keys, duplicate keys, malformed lines, noncanonical numbers, invalid booleans, and files over 64 KiB fail closed before the UDP socket is bound. Supported settings are:

| Setting | Default | Accepted value |
| --- | --- | --- |
| `server.name` | `Bedriox Server` | 1–128 bytes of UTF-8 |
| `server.motd` | `Powered by Bedriox` | 1–128 bytes of UTF-8 without control characters |
| `server.max-players` | `20` | 1–1024 |
| `network.bind-address` | `0.0.0.0` | Literal IPv4 address |
| `network.port` | `19132` | 1–65535 |
| `network.authentication` | `FULL` | `FULL` or explicit development-only `SELF_SIGNED` |
| `level.name` | `world` | 1–64 bytes of UTF-8 without control characters |
| `level.generator` | `flat` | `flat` (the only implemented generator) |
| `level.seed` | `0` | Signed 32-bit decimal integer; flat terrain is currently seed-independent |
| `level.default-gamemode` | `survival` | `survival` (the only implemented game mode) |
| `level.difficulty` | `normal` | `peaceful`, `easy`, `normal`, or `hard` |
| `level.autosave-interval-ticks` | `6000` | 20 through 72000 ticks between autosave scheduling cycles |
| `chunks.view-distance` | `4` | 1–32 chunks |
| `chunks.spawn-radius` | `4` | 1 through the configured view distance |
| `chunks.send-per-tick` | `4` | 1–64 |
| `chunks.generate-per-tick` | `4` | 1–64 |
| `chunks.cache-limit` | `2048` | 16–65536 chunks and large enough to hold every configured player view |
| `chunks.save-per-tick` | `8` | 1 through 64 dirty chunks saved during each scheduled autosave tick |
| `runtime.ticks-per-second` | `20` | 1–100 |
| `console.enabled` | `true` | Exactly `true` or `false` |
| `plugins.enabled` | `true` | Exactly `true` or `false` |
| `plugins.maximum` | `64` | 0–256 total admitted plugins |
| `logging.level` | `INFO` | `DEBUG`, `INFO`, `NOTICE`, `WARNING`, `ERROR`, or `CRITICAL` |
| `logging.console` | `true` | Exactly `true` or `false` |
| `logging.console-colors` | `auto` | `auto`, `true`, or `false` |
| `logging.file` | `true` | Exactly `true` or `false` |
| `logging.file-max-size` | `16777216` | 65536–1073741824 bytes |
| `logging.file-history` | `10` | 0–100 archives |
| `logging.protocol-trace` | `false` | Exactly `true` or `false` |
| `crash-report.include-player-identifiers` | `true` | Exactly `true` or `false` |

Optional `level.spawn-x`, `level.spawn-y`, and `level.spawn-z` entries override the level spawn only when all three are populated. When all three are absent or empty, the world calculates its own safe default; the flat generator currently uses `(0, 64, 0)`. A partial override is rejected.

Settings for independent query ports, resource packs, whitelists, and other unfinished features are deliberately not accepted yet. This prevents apparently valid options from silently doing nothing.

`level.autosave-interval-ticks` controls how often the runtime schedules dirty-world work. At the default 20 ticks per second, `6000` ticks is five minutes. `chunks.save-per-tick` bounds each autosave step so a large dirty queue is drained over multiple ticks rather than written all at once. Dirty chunks are still saved before eviction, and graceful shutdown performs a complete durability flush rather than applying the per-tick limit.

When `console.enabled=true`, Bedriox reads commands without blocking the server loop. On Windows, a lifecycle-owned helper waits on the console handle while the server polls its bounded output; this keeps networking and simulation responsive between commands. Command lines, queued commands, arguments, and commands executed per poll are bounded. Set `console.enabled=false` for detached environments without an operator input stream. Plugin-owned console output uses the normal structured logger and therefore also appears in `logs/server.log` when file logging is enabled.

The qualified protocol family remains alpha software. Unknown commands fail with a bounded console response.

## Plugins

Production plugins are strongly signed `.phar` files placed directly in `plugins/`. Bedriox creates this directory on first start, validates every archive and dependency plan before executing entry points, and never scans source directories. Installing PluginTools adds its separate development source loader; PluginTools owns folder discovery and submits validated definitions through the public admission boundary. Plugin-owned writable data is stored separately under `plugin_data/<PluginName>/`.

Build discovered source plugins with `makeplugin <PluginName> [--overwrite]` from [Bedriox PluginTools](https://github.com/Bedriox/PluginTools). Loading a plugin does not require changing `phar.readonly`; PluginTools applies that setting only to its isolated packaging process. See the [plugin guide](plugins.md) for source development, standalone CLI usage, the manifest, lifecycle, events, commands, priorities, failure behavior, and public API.
