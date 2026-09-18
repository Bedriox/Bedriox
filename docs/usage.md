# Usage

Inspect the current build with:

```shell
php bin/bedriox --version
```

Start the bounded protocol-2193 development server (Minecraft 1.26.50 wire authority; 1.26.51 qualified client) with FULL authentication:

```shell
php bin/bedriox serve
```

The first successful configuration load creates `bedriox.settings` in the current working directory. It contains documented server, network, level, chunk-streaming, runtime, and diagnostic defaults. Edit that local file and restart Bedriox to apply changes. The file is intentionally ignored by Git so each installation can keep its own operator configuration.

Configuration precedence is built-in defaults, then `bedriox.settings`, then command-line overrides. For example:

```shell
php bin/bedriox serve --port=19133 --name="Bedriox Test" --max-players=8 --view-distance=6
```

FULL performs trusted Minecraft JWK discovery before the UDP port is bound and fails closed if discovery is unavailable or invalid. For isolated development only, `--auth=SELF_SIGNED` enables legacy self-signed login and prints an explicit security warning; it is never a fallback from FULL.

Options use exact `--name=value` syntax. Bind addresses must be literal IPv4 values, ports are 1 through 65535, names are at most 128 UTF-8 bytes, and player limits are 1 through 1024. Duplicate, unknown, empty, ambiguous, and out-of-range options fail before startup. The existing `--bind`, `--port`, `--name`, `--max-players`, and `--auth` flags remain supported; level and chunk keys have matching flags such as `--level-name`, `--seed`, `--view-distance`, `--spawn-radius`, `--chunks-send-per-tick`, and `--chunks-generate-per-tick`.

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
| `chunks.view-distance` | `4` | 1–32 chunks |
| `chunks.spawn-radius` | `4` | 1 through the configured view distance |
| `chunks.send-per-tick` | `4` | 1–64 |
| `chunks.generate-per-tick` | `4` | 1–64 |
| `chunks.cache-limit` | `2048` | 16–65536 chunks and large enough to hold every configured player view |
| `runtime.ticks-per-second` | `20` | 1–100 |
| `plugins.enabled` | `true` | Exactly `true` or `false` |
| `plugins.maximum` | `64` | 0–256 PHAR plugins |
| `logging.level` | `INFO` | `DEBUG`, `INFO`, `NOTICE`, `WARNING`, `ERROR`, or `CRITICAL` |
| `logging.console` | `true` | Exactly `true` or `false` |
| `logging.console-colors` | `auto` | `auto`, `true`, or `false` |
| `logging.file` | `true` | Exactly `true` or `false` |
| `logging.file-max-size` | `16777216` | 65536–1073741824 bytes |
| `logging.file-history` | `10` | 0–100 archives |
| `logging.protocol-trace` | `false` | Exactly `true` or `false` |
| `crash-report.include-player-identifiers` | `true` | Exactly `true` or `false` |

Optional `level.spawn-x`, `level.spawn-y`, and `level.spawn-z` entries override the level spawn only when all three are populated. When all three are absent or empty, the world calculates its own safe default; the flat generator currently uses `(0, 64, 0)`. A partial override is rejected.

Settings for independent query ports, world persistence, resource packs, whitelists, and other unfinished features are deliberately not accepted yet. This prevents apparently valid options from silently doing nothing.

The qualified protocol family remains alpha software. Unsupported commands intentionally fail.

## Plugins

Production plugins are strongly signed `.phar` files placed directly in `plugins/`. Bedriox creates this directory on first start, validates every archive and dependency plan before executing entry points, and ignores source directories. Plugin-owned writable data is stored separately under `plugin_data/<PluginName>/`.

Build plugin PHARs with [Bedriox PluginTools](https://github.com/Bedriox/PluginTools). Loading a plugin does not require changing `phar.readonly`; that PHP setting is needed only by the packaging process. See the [plugin guide](plugins.md) for the manifest, lifecycle, events, priorities, failure behavior, and public API.
