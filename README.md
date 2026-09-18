<p align="center">
  <a href="https://bedriox.com">
    <img src=".github/assets/bedriox_main_transparent.png" alt="Bedriox" width="420">
  </a>
</p>

<h1 align="center">Bedriox</h1>

<p align="center">
  A powerful, open-source Minecraft: Bedrock Edition server written in modern PHP.<br>
  Built with performance, customization, and community servers in mind.
</p>

<p align="center">
  <a href="https://github.com/Bedriox/Bedriox/actions/workflows/ci.yml"><img alt="CI" src="https://github.com/Bedriox/Bedriox/actions/workflows/ci.yml/badge.svg"></a>
  <a href="LICENSE"><img alt="License: GPL-3.0" src="https://img.shields.io/badge/license-GPL--3.0-blue.svg"></a>
  <img alt="PHP 8.4+" src="https://img.shields.io/badge/PHP-8.4%2B-777BB4.svg">
</p>

Bedriox gives server owners a performance-focused foundation for creating Minecraft: Bedrock Edition experiences. The current playable foundation supports core multiplayer survival interactions and an experimental PHP plugin API for building custom experiences.

## What Bedriox offers today

- **Playable multiplayer foundations** — synchronized players, movement, chat, inventories, block interaction, and a streamed flat world.
- **Responsive worlds** — bounded chunk generation and streaming keep nearby terrain moving smoothly as players explore.
- **Focused Bedrock support** — one qualified modern protocol family instead of years of legacy protocol code.
- **Modern PHP development** — strict types, Composer packages, automated tests, static analysis, and clear component boundaries.
- **Plugin development support** — run validated PHAR plugins, develop from source with PluginTools, and extend the server through typed events, commands, lifecycle hooks, and safe player/world APIs.

## Where Bedriox is headed

Bedriox is being designed for vanilla-style survival, custom game modes, minigames, hubs, and modded experiences. The experimental plugin API already provides lifecycle hooks, typed gameplay events, player/world snapshots, messages, teleports, block changes, and bounded inventory changes. Commands, permissions, economies, richer mechanics, mobs, and NPCs remain planned expansions.

Bedriox is under active development. These expansion features are roadmap direction, not finished APIs, and behavior may change before the first stable release.

## Supported platforms

Bedriox publishes its own qualified PHP 8.4 runtime with LevelDB, SQLite,
OpenSSL, cURL, ZIP, sodium, GMP, and the other required extensions. Qualified
targets are Windows x86-64, Linux x86-64 and ARM64, and macOS x86-64 and
ARM64. Production startup never falls back to a system PHP installation.

Composer 2 and a local PHP 8.4 installation are needed only for source
development.

## Development quick start

During private alpha development, clone the component repositories beside this repository:

```text
workspace/
|-- Bedriox/
|-- Protocol/
|-- RakNet/
`-- Data/
```

Then install and verify the server:

```shell
composer install
composer check
php bin/bedriox --version
php bin/bedriox serve
```

The commands above are the explicit development path. For a packaged server,
install the exact local Runtime archive recorded in
[`bedriox.lock.json`](bedriox.lock.json), then run `bedriox.cmd` on Windows or
`./bedriox` on Linux and macOS. See the [usage guide](docs/usage.md).

The exact component revisions are recorded in [`bedriox.lock.json`](bedriox.lock.json). See the [development guide](docs/development.md), [usage and configuration guide](docs/usage.md), [plugin guide](docs/plugins.md), [architecture](docs/architecture.md), and [testing guide](docs/testing.md) for contributor details.

## Project family

- [Bedriox](https://github.com/Bedriox/Bedriox) — executable Minecraft: Bedrock Edition server
- [RakNet](https://github.com/Bedriox/RakNet) — UDP and RakNet transport
- [Protocol](https://github.com/Bedriox/Protocol) — Bedrock packet codecs and protocol behavior
- [Data](https://github.com/Bedriox/Data) — versioned Bedrock registries and generated data
- [Runtime](https://github.com/Bedriox/Runtime) — qualified PHP binaries and native dependencies
- [Docs](https://github.com/Bedriox/Docs) — installation, administration, and plugin documentation
- [ExamplePlugin](https://github.com/Bedriox/ExamplePlugin) — minimal plugin project and API examples
- [PluginTools](https://github.com/Bedriox/PluginTools) — source-plugin loading and secure PHAR packaging tools

## Contributing

Bedriox is currently developed in private while its foundations settle. Contribution guidance is available in [CONTRIBUTING.md](CONTRIBUTING.md).

## License

Bedriox is free and open-source software licensed under the [GNU General Public License v3.0](LICENSE).

Bedriox is an independent project and is not affiliated with or endorsed by Mojang Studios or Microsoft. Minecraft is a trademark of Microsoft Corporation.
