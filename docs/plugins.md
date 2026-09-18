# Plugins

Bedriox API `0.1` is an experimental, in-process PHP plugin API. Plugins are trusted PHP programs: the API avoids handing them transport, packet, registry, queue, or mutable simulation objects, but it is not an operating-system sandbox.

## Installation and packaging

Install one strongly signed PHAR per plugin directly under `plugins/`:

```text
plugins/
`-- ExamplePlugin.phar
```

Source directories are not production plugins. Use [PluginTools](https://github.com/Bedriox/PluginTools) to validate and package a project. Bedriox bounds archive size, entry count, individual and expanded entry sizes, rejects links and serialized metadata, checks the embedded strong signature, validates all manifests and dependencies, and only then executes accepted entry points.

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

Cancellable pre-events cover join, movement, chat, block breaking, block placement, and inventory changes. A cancelled client prediction receives the authoritative correction. Immutable post-events describe committed changes. `MONITOR` observes the final result and cannot cancel, mutate, or stage server actions.

## Public server API

`PluginContext::server()` supplies immutable player, inventory, world, position, block, and item views. It supports bounded requests to send a player message, teleport a player, read or change a canonical block, and change a supported inventory slot. Mutations requested by an event listener are staged until that listener returns successfully, then enter the authoritative simulation queue for validation and synchronization.

Blocks and items use canonical identifiers such as `minecraft:grass_block`; process-local numeric IDs never enter the public API. The current flat-world preview exposes `minecraft:air`, `minecraft:bedrock`, `minecraft:dirt`, and `minecraft:grass_block`. Inventory writes currently support `minecraft:grass_block` or an empty slot.

The [ExamplePlugin repository](https://github.com/Bedriox/ExamplePlugin) contains a complete minimal project. API `0.1` is a preview contract and may make documented breaking changes before `1.0`.
