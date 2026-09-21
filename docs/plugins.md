# Plugins

Bedriox API `0.1` is an experimental, in-process PHP plugin API. Plugins are trusted PHP programs. Stable gameplay APIs avoid transport, registry, queue, and mutable simulation objects, while an explicit typed packet escape hatch is available for current-version protocol features. Plugins never receive raw sockets, encryption state, or transport ownership, and the runtime is not an operating-system sandbox.

## Installation and packaging

Install one strongly signed PHAR per plugin directly under `plugins/`:

```text
plugins/
`-- ExamplePlugin.phar
```

Source directories are not native Bedriox plugins. Installing [PluginTools](https://github.com/Bedriox/PluginTools) adds the development workflow: PluginTools discovers and validates direct source-project children under `plugins/`, supplies their bounded definitions through the public admission API, and owns their namespace-restricted loading. Bedriox itself continues to scan only PHAR files. Removing PluginTools restores PHAR-only startup.

With `PluginTools.phar` installed, a development project may use this layout:

```text
plugins/
|-- PluginTools.phar
`-- MyPlugin/
    |-- plugin.json
    |-- src/
    `-- resources/
```

Restart after changing PHP source because loaded classes cannot be replaced safely. Package a discovered project from the server console with `makeplugin MyPlugin`; add `--overwrite` only when replacing an existing build. PluginTools writes the archive and checksum under `plugin_data/PluginTools/`. Its standalone CLI remains available for offline builds and automation.

For PHARs, Bedriox bounds archive size, entry count, individual and expanded entry sizes, rejects links and serialized metadata, checks the embedded strong signature, validates all manifests and dependencies, and only then executes accepted entry points.

Every PHAR contains `plugin.json` at its root and namespaced code under `src/`. Schema 1 uses this shape:

```json
{
  "schema": 1,
  "name": "ExamplePlugin",
  "version": "1.0.0",
  "api": "^0.1",
  "main": "Bedriox\\ExamplePlugin\\Main",
  "namespace": "Bedriox\\ExamplePlugin",
  "authors": ["Bedriox Team"],
  "dependencies": [],
  "softDependencies": [],
  "load": "WORLD_READY"
}
```

Plugin data belongs under the canonical `plugin_data/<PluginName>/` folder supplied by `PluginContext::dataFolder()`. A plugin must not write into its PHAR.

## Lifecycle

An entry point extends `Bedriox\Api\Plugin\Plugin` and may implement `onLoad()`, `onEnable()`, and `onDisable()`. Required dependencies load first. A missing, incompatible, cyclic, or failed dependency prevents only affected plugins from enabling.

If ordinary plugin code throws, Bedriox attributes the active plugin and operation, restores controlled event state, discards that listener's staged API work, disables the plugin and required dependants, releases listeners, and continues serving healthy players. PHP code cannot be unloaded safely; installing changed code requires a restart.

## Typed events

Register a public subscriber during enablement:

```php
use Bedriox\Api\Event\EventHandler;
use Bedriox\Api\Event\Player\PlayerJoinEvent;

#[EventHandler]
public function onJoin(PlayerJoinEvent $event): void
{
    $this->logger()->info($event->player->name . ' joined');
}
```

`#[EventHandler]` defaults to `EventPriority::NORMAL`. Dispatch order is `LOWEST`, `LOW`, `NORMAL`, `HIGH`, `HIGHEST`, then `MONITOR`, with registration order as the tie-breaker. Programmatic registration is available through `PluginContext::events()`.

Cancellable pre-events cover join, movement, chat, player attacks, damage, block breaking, block placement, and inventory changes. `PlayerAttackEvent` exposes immutable attacker and target snapshots and may cancel the intent or set its bounded damage; its `PlayerInteractionType::ATTACK` value is independent of Bedrock wire IDs. `PlayerDamageEvent` performs the same role for non-attack damage. `PlayerRespawnEvent` may select a bounded destination before respawn commits. A cancelled client prediction receives the authoritative correction. Immutable post-events, including `PlayerAttackedEvent`, `PlayerDamagedEvent`, and `PlayerRespawnedEvent`, describe committed changes. `MONITOR` observes the final result and cannot cancel, mutate, or stage server actions.

`PlayerDeathEvent` runs after lethal health commits but before Bedriox presents the death. It carries immutable victim and optional killer snapshots, the cause and final incoming damage, plus independent chat and death-screen messages. A plugin may set either message to a bounded raw string, a `Bedriox\Api\TranslatableMessage`, or `null` to suppress that presentation. Screen suppression retains the empty protocol handshake required for respawning. The event does not cancel death. Listener failure restores both messages before later listeners run, and `MONITOR` remains read-only.

## Public server API

`PluginContext::server()` supplies immutable player, inventory, world, position, block, item, health, and alive-state views. It supports bounded requests to send a player message, teleport or damage a player, read or change a canonical block, and change a supported inventory slot. Damage enters the same cancellable authoritative event path as built-in causes. Mutations requested by an event listener are staged until that listener returns successfully, then enter the authoritative simulation queue for validation and synchronization.

Blocks and items use canonical identifiers such as `minecraft:grass_block`; process-local numeric IDs never enter the public API. The current flat-world preview exposes `minecraft:air`, `minecraft:bedrock`, `minecraft:dirt`, and `minecraft:grass_block`. Inventory writes currently support `minecraft:grass_block` or an empty slot.

The [ExamplePlugin repository](https://github.com/Bedriox/ExamplePlugin) contains a complete minimal project. API `0.1` is a preview contract and may make documented breaking changes before `1.0`.

### Player display and packet API

A connected `Player` exposes high-level presentation methods for ordinary messages, translated messages, popups, jukebox popups, tips, titles, subtitles, action bars, title timing, title clearing/resetting, and toast notifications. Title timing uses `TitleTimes` in ticks. `sendTitle()` follows the retail-compatible order: timing, optional subtitle, then title. Ordinary strings use Bedrock's raw-text presentation, matching PocketMine-MP; `TranslatableMessage` uses the translation variant.

```php
use Bedriox\Api\Player\TitleTimes;

$player->sendMessage('Welcome to Bedriox');
$player->sendPopup('Now entering spawn');
$player->sendTip('Use /help for commands');
$player->sendTitle('Welcome', 'Have fun', new TitleTimes(10, 70, 20));
$player->sendActionBar('Ready');
$player->sendToast('Achievement', 'You found the server');
```

For protocol features without a high-level method, trusted plugins may send any typed packet supported by the installed `bedriox/protocol` version:

```php
use Bedriox\Protocol\Packet\TextPacket;

$player->connection()->sendPacket(TextPacket::announcement('Server restart soon'));
$player->connection()->sendPacket(TextPacket::tip('Sent now'), immediate: true);
```

Normal sends join the connection's bounded output queue and flush during the next runtime poll. `immediate: true` flushes the current connection queue before returning; it does not bypass packet encoding, encryption, queue limits, or connection checks. Both forms return `false` when the player is no longer connected or the packet cannot be queued.

Direct packet access is deliberately version-specific. A plugin using it is responsible for sending a packet valid for the current client phase and for supporting every normal reply or follow-up packet its custom conversation can trigger. Prefer the high-level `Player` and server APIs for gameplay state: a clientbound packet does not mutate the authoritative world, inventory, health, position, or permissions.

## Commands

Plugins register typed commands through `PluginContext::commands()`. A `CommandDefinition` declares the lowercase name, description, usage, aliases, optional permission, and whether console, players, or either sender type may invoke it. Bedriox resolves names and aliases case-insensitively, provides the deterministic `<plugin>:<command>` fallback, checks sender and permission policy centrally, and removes every command when its owner disables.

Player-facing messages may use the complete current Bedrock set in `Bedriox\Api\TextFormat`: the classic colors, Minecoin Gold, Quartz through Resin material colors, obfuscated, bold, italic, and reset. Concatenate constants such as `TextFormat::GREEN`, `TextFormat::MATERIAL_DIAMOND`, `TextFormat::BOLD`, and `TextFormat::RESET` with message text; do not embed raw section-sign formatting codes in plugin source. Bedrock assigns `§m` and `§n` to the Redstone and Copper material colors, so Bedriox deliberately does not expose the conflicting Java strikethrough or underline meanings. Console output should remain plain text.

Handlers receive a `CommandContext` and return `CommandResult::SUCCESS`, `FAILURE`, or `USAGE`. Use `ConsoleCommandSender` and `PlayerCommandSender` type checks when behavior depends on the caller; a player sender exposes only the immutable public player view. Console senders have console authority. Player commands use the same dispatcher and centrally enforce UUID-based operators, explicit permission grants, sender restrictions, and command events before plugin code runs.

`CommandPreDispatchEvent` is cancellable after command resolution, sender policy, and permission validation. `CommandDispatchedEvent` observes successful handler completion. A throwing handler is attributed to its owning plugin, its staged API work is discarded, and that plugin's commands and listeners are released without stopping the server.

Long-running external work must never block a handler or simulation poll. A plugin may submit a cooperative `CommandJob` through its registrar; Bedriox bounds live jobs and polls per server iteration, while the plugin owns task-specific process, timeout, and output limits. Job resources are cancelled automatically when their plugin disables.
