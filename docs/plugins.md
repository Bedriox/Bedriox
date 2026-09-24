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
  "api": "^0.2",
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

Cancellable pre-events cover join, movement, teleportation, chat, player attacks, damage, block breaking, block placement, inventory changes, game-mode changes, item dropping and pickup, item use and consumption, nutrition, equipment, and durability. `PlayerTeleportEvent` may cancel a teleport or replace its bounded destination, yaw, and pitch; it intentionally applies no collision or headroom policy. `PlayerTeleportedEvent` describes the committed result. `PlayerAttackEvent` exposes immutable attacker and target snapshots and may cancel the intent or set its bounded damage; its `PlayerInteractionType::ATTACK` value is independent of Bedrock wire IDs. `PlayerDamageEvent` performs the same role for non-attack damage. `PlayerRespawnEvent` may select a bounded destination before respawn commits. `PlayerGameModeChangeEvent` may cancel or replace the requested mode. `PlayerDropItemEvent` may cancel a drop or bound its count before inventory mutation, while `PlayerPickupItemEvent` may cancel or bound the collected count. A cancelled client prediction receives the authoritative correction. Immutable post-events describe only committed changes. `MONITOR` observes the final result and cannot cancel, mutate, or stage server actions.

`PlayerItemUseEvent` runs after the held stack, game mode, cooldown, and active-use rules have been validated. Cancelling it prevents the use from starting or applying. `PlayerItemUsedEvent` reports a committed instant or completed timed use, while `PlayerItemUseCancelledEvent` records why an active use ended without completing. `PlayerItemConsumeEvent` may cancel consumption or replace its bounded `ConsumptionResult`, including nutrition restoration and residue stacks. `PlayerItemConsumedEvent` reports the committed stack, nutrition, and residue result. `PlayerFoodLevelChangeEvent` may cancel or replace a bounded `Nutrition` snapshot; `PlayerFoodLevelChangedEvent` reports the committed state. `FoodLevelChangeCause::EXHAUSTION` includes accepted survival and adventure sprint movement.

`PlayerEquipmentChangeEvent` covers armor and offhand transitions. A listener may cancel it or replace the proposed immutable stack, which is revalidated for the target `EquipmentSlot` before commit. `PlayerEquipmentChangedEvent` reports the committed result. `PlayerItemDamageEvent` may cancel or adjust bounded durability loss; `PlayerItemBreakEvent` reports an item removed after its durability was exhausted. These APIs use gameplay enums such as `EquipmentSlot`, `ItemUseKind`, and `ItemDamageCause`, never protocol ordinals.

Natural regeneration enters `PlayerRegainHealthEvent` before health changes. A listener may cancel it or set a bounded amount. `PlayerRegainedHealthEvent` observes the committed result, and `HealthRegainCause::SATURATION` identifies the nutrition-driven path without exposing an internal numeric cause.

`PlayerDeathEvent` runs after lethal health commits but before Bedriox presents the death. It carries immutable victim and optional killer snapshots, the cause and final incoming damage, plus independent chat and death-screen messages. A plugin may set either message to a bounded raw string, a `Bedriox\Api\TranslatableMessage`, or `null` to suppress that presentation. Screen suppression retains the empty protocol handshake required for respawning. The event does not cancel death. Listener failure restores both messages before later listeners run, and `MONITOR` remains read-only.

## Public server API

`PluginContext::server()` supplies immutable player, game-mode, inventory, world, position, block, item, health, and alive-state views. It supports bounded requests to send a player message, teleport with optional yaw and pitch, damage, change game mode, give a catalog item, read or change a canonical block, and change a supported inventory slot. Teleport, damage, and game-mode changes enter the same cancellable authoritative event paths as built-in causes. Mutations requested by an event listener are staged until that listener returns successfully, then enter the authoritative simulation queue for validation and synchronization.

Blocks and items use canonical identifiers such as `minecraft:grass_block`; process-local numeric IDs never enter the public API. The gameplay catalogs expose the admitted terrain blocks, their drops, and supported tools without exposing network runtime IDs. Inventory writes accept catalog items or an empty slot.

Item stacks may carry immutable, bounded custom NBT and a Bedrock auxiliary variant value from `0` through `32767`. `ItemNbt` supports every ordinary NBT value through typed `Tag` factories: byte, short, int, long, float, double, string, byte/int/long arrays, homogeneous lists, and nested compounds. Bedriox compares NBT and auxiliary values when stacking, preserves both across moves, drops, pickups, player saves, and rejoins, and writes them through their separate Bedrock item fields. The top-level `Damage` tag is reserved for Bedriox's authoritative durability field; plugins should use their own namespaced keys. Auxiliary values identify admitted variants and are not a substitute for implementing an item's unique gameplay mechanic.

```php
use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Nbt\Tag;
use Bedriox\Api\Nbt\TagType;

$nbt = ItemNbt::empty()
    ->withString('example:kind', 'reward')
    ->withTag('example:levels', Tag::list(TagType::INT, [Tag::int(1), Tag::int(2)]))
    ->withTag('example:display', Tag::compound(['enabled' => Tag::byte(1)]));
$this->context()->server()->giveItem($player, new ItemStack('minecraft:diamond', 1, nbt: $nbt));
```

`PluginContext::items()` registers or explicitly replaces bounded definitions for item identifiers present in the active Bedrock data set. A definition controls maximum stack size and creative visibility. Replacing an admitted vanilla definition preserves its block-placement and tool behavior. Registrations are live for authoritative inventory rules; players already online keep the creative list sent at login, while later joins receive the current list. New client-side item identifiers and textures require a resource-pack/custom-item milestone and are not invented by this API.

```php
use Bedriox\Api\Inventory\ItemDefinition;

$this->context()->items()->register(
    new ItemDefinition('minecraft:diamond', maximumStackSize: 16),
    replace: true,
);
```

The same owner-scoped registrar adds bounded gameplay behavior to an admitted item. Behavior registration is data-only: plugin event listeners implement custom effects through the normal staged server API. A consumable definition sets its authoritative use duration, cooldown, food and saturation restoration, hunger requirement, and residue. An armor definition selects one typed armor slot and sets defense, durability, and optional knockback resistance. Offhand admission is an explicit capability. With `replace: true`, a plugin may override built-in behavior or its own earlier definition while enabled, but never another plugin's definition. Disablement removes the override and restores the built-in behavior when one exists.

```php
use Bedriox\Api\Inventory\ArmorDefinition;
use Bedriox\Api\Inventory\ConsumableDefinition;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemBehaviorDefinition;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Inventory\ItemUseKind;

$items = $this->context()->items();
$items->registerBehavior(
    'minecraft:beetroot_soup',
    new ItemBehaviorDefinition(
        ItemUseKind::CONSUME,
        useDurationTicks: 32,
        consumable: new ConsumableDefinition(
            foodRestore: 6,
            saturationRestore: 7.2,
            residue: [new ItemStack('minecraft:bowl', 1)],
        ),
    ),
    replace: true,
);
$items->registerBehavior(
    'minecraft:iron_helmet',
    new ItemBehaviorDefinition(
        ItemUseKind::EQUIP,
        armor: new ArmorDefinition(EquipmentSlot::HEAD, 2, 165),
    ),
    replace: true,
);
```

The [ExamplePlugin repository](https://github.com/Bedriox/ExamplePlugin) contains a complete minimal project. API `0.1` is a preview contract and may make documented breaking changes before `1.0`.

### Player display and packet API

A connected `Player` exposes high-level presentation methods for ordinary messages, translated messages, popups, jukebox popups, tips, titles, subtitles, action bars, title timing, title clearing/resetting, and toast notifications. Title timing uses `TitleTimes` in ticks. `sendTitle()` follows the retail-compatible order: timing, optional subtitle, then title. Ordinary strings use Bedrock's raw-text presentation, matching PocketMine-MP; `TranslatableMessage` uses the translation variant.

`Player::kick($reason, $quitMessage, $disconnectScreenMessage)` requests a server-initiated disconnect. A `PlayerKickEvent` listener may cancel it or change those messages. The quit message, when supplied, is sent to other online players; the disconnect-screen message defaults to the reason. The method returns `false` for an offline player, cancellation, or an invalid/failed send. Transport timeouts and ordinary client quits do not fire the kick event.

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

The pre-alpha command API is class based. This is a breaking replacement: closure registration, handwritten usage strings, raw `CommandContext::arguments()`, and the old `CommandResult` constants have been removed without a compatibility adapter. Each command implements `Command` directly or extends `AbstractCommand`, declares an ordered argument schema, and receives validated values through `CommandContext::values()`.

`AbstractCommand` supplies the definition, a no-argument default, aliases, permission and sender-policy hooks, and `success()` and `failure()` result helpers. This command supports two forms, whose usage is generated from the same schema used for parsing and Bedrock autocomplete:

```php
use Bedriox\Api\Command\AbstractCommand;
use Bedriox\Api\Command\CommandArguments;
use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandOverload;
use Bedriox\Api\Command\CommandParameter;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\Command\PlayerCommandSender;

final class PlayerInfoCommand extends AbstractCommand
{
    public function __construct()
    {
        parent::__construct('playerinfo', 'Show information about a connected player');
    }

    protected function aliases(): array
    {
        return ['pinfo'];
    }

    protected function permission(): ?string
    {
        return 'example.command.playerinfo';
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()
            ->addOverload(
                CommandOverload::create()
                    ->addArgument(CommandParameter::literal('show'))
                    ->addArgument(CommandParameter::onlinePlayer('player'))
                    ->addArgument(CommandParameter::choice('detail', ['summary', 'full'])
                        ->optional(default: 'summary')),
            )
            ->addOverload(
                CommandOverload::create()
                    ->addArgument(CommandParameter::literal('self'))
                    ->addArgument(CommandParameter::choice('detail', ['summary', 'full'])
                        ->optional(default: 'summary')),
            );
    }

    public function execute(CommandContext $context): CommandResult
    {
        $values = $context->values();
        if ($values->has('self')) {
            $sender = $context->sender();
            if (!$sender instanceof PlayerCommandSender) {
                return $this->failure('The self form requires a player sender.');
            }
            $player = $sender->player();
        } else {
            $player = $values->player('player');
        }

        if (!$player->isConnected()) {
            return $this->failure('That player is no longer connected.');
        }

        $detail = $values->choice('detail');

        return $this->success("{$player->name}: {$detail}");
    }
}
```

Register the command from the plugin lifecycle:

```php
public function onEnable(): void
{
    $this->context()->commands()->register(new PlayerInfoCommand());
}
```

The example generates `/playerinfo show <player> [detail:summary|full]` and `/playerinfo self [detail:summary|full]`. Invalid input is rejected before `execute()`, and Bedriox sends the binding error followed by every generated usage form. A message carried by `CommandResult::success()` or `CommandResult::failure()` is sent to the command sender.

For a command with one form, use `CommandArguments::create()->addArgument(...)` directly. Available parameter factories are `string`, `integer`, `float`, `boolean`, `onlinePlayer`, `players`, `choice`, backed `enum`, registered `softEnum`, `position`, `blockPosition`, `message`, `json`, `rawText`, and `literal`. Numeric parameters support `minimum()` and `maximum()`; non-literal parameters support `optional()` with an optional typed default. Required parameters cannot follow optional parameters, and greedy `message`, `json`, or `rawText` parameters must be last.

`CommandValues` provides matching named accessors such as `string()`, `integer()`, `float()`, `boolean()`, `player()`, `players()`, `choice()`, `enum()`, `position()`, `blockPosition()`, `message()`, `json()`, and `rawText()`. `onlinePlayer()` advertises a live soft enum of connected player names to the Bedrock command UI and resolves exactly one connected `Player` case insensitively. Join and disconnect updates refresh those suggestions. Because a player can disconnect after binding, command code may recheck `Player::isConnected()` before acting.

Plugins can register a bounded named soft enum when suggestions may change while the server is running. The returned handle belongs to the plugin, is removed automatically when that plugin disables, and can be reused by any of that plugin's commands:

```php
$kits = $this->context()->commands()->registerSoftEnum(
    'kits',
    ['starter', 'builder'],
);

$arguments = CommandArguments::create()
    ->addArgument(CommandParameter::softEnum('kit', $kits));

$kits->add('vip');
$kits->remove('builder');
$kits->replace(['starter', 'vip', 'moderator']);
```

Soft-enum changes are deduplicated and sent to connected clients using the current Bedrock update packet. Parsing uses the same current value set and remains server-authoritative; client autocomplete never authorizes an unknown value. Names are automatically scoped to the registering plugin, values are unique ignoring case, and foreign plugins cannot borrow another plugin's enum handle.

Bedriox resolves command names and aliases case insensitively, provides the deterministic `<plugin>:<command>` fallback, checks sender and permission policy centrally, and removes every command when its owner disables. Plugins that implement `Command` directly return their own `CommandDefinition`; handwritten usage remains unnecessary because `defineArguments()` is authoritative.

Player-facing messages may use the complete current Bedrock set in `Bedriox\Api\TextFormat`: the classic colors, Minecoin Gold, Quartz through Resin material colors, obfuscated, bold, italic, and reset. Concatenate constants such as `TextFormat::GREEN`, `TextFormat::MATERIAL_DIAMOND`, `TextFormat::BOLD`, and `TextFormat::RESET` with message text; do not embed raw section-sign formatting codes in plugin source. Bedrock assigns `§m` and `§n` to the Redstone and Copper material colors, so Bedriox deliberately does not expose the conflicting Java strikethrough or underline meanings. Console output should remain plain text.

Use `ConsoleCommandSender` and `PlayerCommandSender` type checks when behavior depends on the caller; a player sender exposes only the immutable public player view. Console senders have console authority. Player commands use the same dispatcher and centrally enforce UUID-based operators, explicit permission grants, sender restrictions, and command events before plugin code runs.

`CommandPreDispatchEvent` is cancellable after command resolution, sender policy, and permission validation. `CommandDispatchedEvent` observes successful handler completion. A throwing handler is attributed to its owning plugin, its staged API work is discarded, and that plugin's commands and listeners are released without stopping the server.

Long-running external work must never block a handler or simulation poll. A plugin may submit a cooperative `CommandJob` through its registrar; Bedriox bounds live jobs and polls per server iteration, while the plugin owns task-specific process, timeout, and output limits. Job resources are cancelled automatically when their plugin disables.

## Scheduled and asynchronous work

`PluginContext::scheduler()` owns every task registered by that plugin. Main-thread callbacks may be scheduled for the next tick, after a positive delay, at a positive repeating period, or with separate initial-delay and repeating-period values:

```php
$scheduler = $this->context()->scheduler();

$scheduler->nextTick(function (): void {
    $this->logger()->info('The next server tick started');
});

$handle = $scheduler->delayedRepeating(20, 20, function (): void {
    $this->logger()->info('One second passed at 20 TPS');
});

$handle->cancel();
```

Due callbacks run in target-tick and registration order. A callback registered while tasks are being dispatched cannot run during that same dispatch. Repeating tasks use fixed-delay timing: the next run is measured from the tick that completed the current run, and missed intervals are not replayed in a burst. Handles expose their terminal state and cancellation is idempotent. Disabling a plugin cancels all of its outstanding work. An uncaught callback failure is attributed to its owner, discards staged API actions, disables that plugin, and does not stop healthy plugins or the server.

CPU-heavy calculations use the class-based async API instead of closures. An async entry point extends `Bedriox\Api\Scheduler\AsyncTask`, has no dependency on a live server object in `onRun()`, and exchanges only `AsyncTaskValue` instances. Transfer values admit bounded `null`, booleans, finite numbers, UTF-8 strings, lists, and string-keyed maps; resources, references, closures, arbitrary objects, packets, players, worlds, and mutable server state are rejected.

Process-isolated async tasks are available only to admitted PHAR plugins. Admission binds the plugin name and version to the archive's exact SHA-256 digest and embedded signature identity; the worker verifies that identity again before loading task code, so replacing the PHAR requires a normal server restart and fresh admission. Development source plugins may still use deterministic main-thread scheduling but cannot submit process-isolated tasks.

```php
use Bedriox\Api\Scheduler\AsyncTask;
use Bedriox\Api\Scheduler\AsyncTaskFailure;
use Bedriox\Api\Scheduler\AsyncTaskValue;
use Bedriox\Api\Plugin\PluginContext;

final class CalculateScore extends AsyncTask
{
    public function onRun(AsyncTaskValue $input): AsyncTaskValue
    {
        $value = $input->value();

        return new AsyncTaskValue(['score' => is_int($value) ? $value * 2 : 0]);
    }

    public function onCompletion(AsyncTaskValue $result, PluginContext $context): void
    {
        $context->logger()->info('Calculation completed');
    }

    public function onFailure(AsyncTaskFailure $failure, PluginContext $context): void
    {
        $context->logger()->warning('Calculation failed: ' . $failure->type);
    }
}

$this->context()->scheduler()->async(
    new CalculateScore(),
    new AsyncTaskValue(21),
);
```

Async execution is for brief CPU-bound calculations. Do not use the shared plugin worker capacity for filesystem, database, network, subprocess, or indefinite work. Async task classes cannot declare constructor arguments: all worker input must cross the bounded `AsyncTaskValue` boundary. The worker constructs a fresh task instance and invokes only `onRun()`; it does not serialize the submitted object or return worker-mutated instance state. `onCompletion()` and `onFailure()` run on the authoritative main thread with the owning `PluginContext`, and any gameplay change they request is validated normally. A worker failure invokes `onFailure()` and marks the handle failed; an exception from either main-thread callback is attributed to and isolates the owning plugin. Cancellation of running worker work is best effort, and stale results from cancelled, replaced-generation, or disabled owners are ignored.
