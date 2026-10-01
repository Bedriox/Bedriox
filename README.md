<p align="center">
  <a href="https://bedriox.com">
    <img src=".github/assets/bedriox_main_transparent.png" alt="Bedriox" width="420">
  </a>
</p>

<h1 align="center">Bedriox</h1>

<p align="center">
  A powerful, open-source Minecraft: Bedrock Edition server written in modern PHP.<br>
  Build familiar survival worlds, custom multiplayer experiences, minigames, hubs, and more.
</p>

<p align="center">
  <a href="https://github.com/Bedriox/Bedriox/actions/workflows/ci.yml"><img alt="CI" src="https://github.com/Bedriox/Bedriox/actions/workflows/ci.yml/badge.svg"></a>
  <a href="LICENSE"><img alt="License: GPL-3.0" src="https://img.shields.io/badge/license-GPL--3.0-blue.svg"></a>
  <img alt="Bedriox Beta" src="https://img.shields.io/badge/status-beta-orange.svg">
  <img alt="PHP 8.4+" src="https://img.shields.io/badge/PHP-8.4%2B-777BB4.svg">
</p>

<p align="center">
  <a href="https://bedriox.com">Website</a> &bull;
  <a href="https://github.com/Bedriox/Docs">Documentation</a> &bull;
  <a href="https://marketplace.bedriox.com">Marketplace</a> &bull;
  <a href="https://github.com/Bedriox/Bedriox/releases">Downloads</a>
</p>

Bedriox gives server owners a modern foundation for Minecraft: Bedrock Edition. It is built to deliver the complete vanilla experience while giving communities the freedom to go far beyond it through plugins, custom worlds, and original game modes.

> [!IMPORTANT]
> Bedriox is currently in beta. Core multiplayer gameplay is available, but vanilla coverage is still expanding and APIs may change before the first stable release. Back up important worlds before updating.

## Install Bedriox

The official installer downloads the latest `Bedriox.phar`, selects the bundled PHP Runtime for your platform, verifies every downloaded component, creates a `bedriox-server` directory, and starts the first-run setup wizard. Composer and a system PHP installation are not required.

### Linux and macOS

```sh
curl -fsSL https://bedriox.com/install.sh | sh
```

### Windows PowerShell

```powershell
irm https://bedriox.com/install.ps1 | iex
```

The installers support Windows x86-64, Linux x86-64 and ARM64, and macOS x86-64 and ARM64. Manual packages are also available from [GitHub Releases](https://github.com/Bedriox/Bedriox/releases). See the [usage and configuration guide](docs/usage.md) for manual installation, startup options, and server configuration.

## Create your server

- **Vanilla-style gameplay** — build survival or creative communities with persistent worlds, inventories, crafting, containers, processing stations, experience, enchantments, effects, combat, weather, fluids, mobs, breeding, taming, mounts, and more.
- **Multiplayer from the start** — synchronized players, movement, chat, commands, permissions, operators, whitelists, player-versus-player combat, death, respawning, and world transfers are server-owned and multiplayer-aware.
- **Worlds worth exploring** — generate seeded continents, mountains, valleys, rivers, forests, deserts, oceans, caves, ores, and snowy highlands, or choose a classic flat world.
- **Durable worlds and players** — Mojang-compatible LevelDB world storage preserves terrain, block changes, containers, entities, and world state, while player profiles retain their authoritative progress.
- **Modern Bedrock support** — Bedriox focuses on one qualified current protocol family instead of carrying years of legacy protocol behavior.
- **Built for customization** — typed events, commands, schedulers, custom entities, custom items and recipes, player and world APIs, source development, and PHAR plugins give creators a structured way to build new experiences.
- **Performance-conscious architecture** — bounded workloads, asynchronous world work, prepared chunk delivery, controlled entity AI, and runtime diagnostics keep the main simulation responsive as servers grow.

Bedriox is continuing toward broader vanilla coverage, richer entities and NPCs, automation, deeper world generation, and an increasingly capable plugin ecosystem.

## Plugins and Marketplace

Discover plugins for your server through the [Bedriox Marketplace](https://marketplace.bedriox.com). Plugin developers can also submit their work to the Marketplace so server owners can find and install it.

Bedriox loads validated PHAR plugins. [PluginTools](https://github.com/Bedriox/PluginTools) adds source-plugin loading for development and packages finished plugins into PHAR files. Start with the [plugin documentation](docs/plugins.md) or the [ExamplePlugin](https://github.com/Bedriox/ExamplePlugin).

## Source development

Composer 2 and PHP 8.4 are needed only when developing Bedriox from source. Clone the component repositories beside one another:

```text
workspace/
|-- Bedriox/
|-- Protocol/
|-- RakNet/
`-- Data/
```

Install the Composer dependencies and the matching qualified Runtime described in the [development guide](docs/development.md):

```shell
composer install
composer check
```

Then verify and start the checkout with the platform launcher.

Windows:

```powershell
.\bedriox.cmd --version
.\bedriox.cmd
```

Linux and macOS:

```shell
./bedriox --version
./bedriox
```

The launchers use the adjacent qualified Runtime rather than whichever PHP happens to be available through `PATH`. Exact component and Runtime revisions are recorded in [`bedriox.lock.json`](bedriox.lock.json). See the [architecture](docs/architecture.md) and [testing guide](docs/testing.md) before changing the server.

## Reporting problems

Found a reproducible bug? [Open a bug report](https://github.com/Bedriox/Bedriox/issues/new?template=bug_report.yml) and include:

- what you were trying to do;
- exact steps another person can follow;
- what you expected to happen;
- what actually happened;
- your Bedriox and Bedrock client versions, operating system, and installed plugins; and
- relevant logs or a crash report after reviewing and removing credentials or unrelated personal information.

Search existing reports before opening a duplicate. Security vulnerabilities must not be posted publicly; follow [SECURITY.md](SECURITY.md) instead.

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

Bedriox welcomes focused improvements as the beta evolves. Read [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request.

## License

Bedriox is free and open-source software licensed under the [GNU General Public License v3.0](LICENSE).

Bedriox is an independent project and is not affiliated with or endorsed by Mojang Studios or Microsoft. Minecraft is a trademark of Microsoft Corporation.
