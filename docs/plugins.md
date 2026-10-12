# Plugins

Bedriox API `0.4` is an experimental, in-process PHP plugin API. Plugins are trusted PHP programs. Stable gameplay APIs avoid transport, registry, queue, and mutable simulation objects, while an explicit typed packet escape hatch is available for current-version protocol features. Plugins never receive raw sockets, encryption state, or transport ownership, and the runtime is not an operating-system sandbox.

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
  "api": "^0.4",
  "main": "Bedriox\\ExamplePlugin\\Main",
  "namespace": "Bedriox\\ExamplePlugin",
  "authors": ["Bedriox Team"],
  "dependencies": [],
  "softDependencies": [],
  "load": "WORLD_READY"
}
```

Plugin data belongs under the canonical `plugin_data/<PluginName>/` folder exposed by `PluginContext::data()`. A plugin must not write into its PHAR. Packaged plugins and PluginTools source projects may ship defaults under `resources/`; both use the same API:

```php
$data = $this->context()->data();
$data->saveResource('config.yml');
$config = $data->config();
$enabled = $config->getBool('enabled', true);
$config->set('enabled', false);
$config->save();
```

`saveResource()` never overwrites an existing administrator-edited file unless `replace: true` is explicit. `saveResources()` copies all admitted defaults. `config()` supports `.yml`, `.yaml`, and `.json`, dot-separated keys, typed getters, mutation, saving, and reloading. `path()` is available for plugins that need to manage another bounded format themselves. Resource paths and configuration structures are validated and kept inside the plugin's own data directory.

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
    $event->setJoinMessage($event->player->name . ' joined this server');
}
```

`PlayerJoinEvent` fires after admission commits. It is not cancellable, but its bounded join announcement may be replaced with a raw string or translated message, or set to `null` to suppress it. World-to-world transfers do not produce another join announcement.

`#[EventHandler]` defaults to `EventPriority::NORMAL`. Dispatch order is `LOWEST`, `LOW`, `NORMAL`, `HIGH`, `HIGHEST`, then `MONITOR`, with registration order as the tie-breaker. Programmatic registration is available through `PluginContext::events()`.

Cancellable pre-events cover join, movement, teleportation, chat, missed arm swings, player attacks, damage, block breaking, block placement, inventory changes, game-mode changes, item dropping and pickup, item use and consumption, nutrition, equipment, and durability. `PlayerMissSwingEvent` may cancel a left-click-air swing before it is published; authoritative mining and accepted combat swings are consequences of their corresponding gameplay events. `PlayerTeleportEvent` may cancel a teleport or replace its bounded destination, yaw, and pitch; it intentionally applies no collision or headroom policy. `PlayerTeleportedEvent` describes the committed result. `PlayerAttackEvent` exposes immutable attacker and target snapshots and may cancel the intent or set its bounded damage; its `PlayerInteractionType::ATTACK` value is independent of Bedrock wire IDs. `PlayerDamageEvent` performs the same role for non-attack damage. `PlayerRespawnEvent` may select a bounded destination before respawn commits. `PlayerGameModeChangeEvent` may cancel or replace the requested mode. `PlayerDropItemEvent` may cancel a drop or bound its count before inventory mutation, while `PlayerPickupItemEvent` may cancel or bound the collected count. A cancelled client prediction receives the authoritative correction. Immutable post-events describe only committed changes. `MONITOR` observes the final result and cannot cancel, mutate, or stage server actions.

`PlayerBlockPickEvent` runs after the target block, reach, loaded world state, and item mapping have been validated but before inventory mutation. It exposes the player, canonical block, proposed item, and whether block user data was requested. Plugins may cancel the pick or replace its item; the resulting mutation also passes through the ordinary cancellable inventory-change event and committed inventory notification.

Combat and item systems add typed boundaries for one-pass actor knockback, mutable block drops, Mending, projectile launch, and projectile impact. Launch listeners may replace bounded projectile motion; impact listeners receive the resolved player, living entity, or block target. Cancelling knockback cancels motion without undoing accepted damage. See [enchantments and projectiles](enchantments-and-projectiles.md).

`EntityCombustEvent` runs before an entity is ignited. It exposes a typed `EntityCombustionCause`, allows the bounded duration in ticks to be adjusted, and may cancel ignition. Burning damage then uses `EntityDamageEvent` with `EntityDamageCause::FIRE_TICK`, so cancellation and damage adjustment retain the same authoritative event boundary as other living-entity damage. `LivingEntity::isOnFire()` and `getFireTicks()` expose the committed read-only state.

Live non-player entities expose a typed controller rather than mutable runtime state. The `EntityController`, `LivingEntityController`, and `MobController` hierarchy covers transforms, presentation state, health, fire, equipment, AI, and bounded movement control. Controller work requested by a plugin callback is staged with that callback and discarded if it fails. Entity equipment has cancellable adjustable pre-events and immutable committed post-events. `EntityDeathEvent` cannot cancel death, but it receives the once-evaluated stable drop list before item actors spawn and may replace, append, or clear that list. See [entities, controllers, equipment, and loot](entities.md) for the complete contract and current limitations.

Boat steering enters cancellable `VehicleControlEvent` before authoritative
motion and immutable `VehicleControlledEvent` after commit. Plugins receive the
typed driver and boat plus normalized movement, yaw, and paddle values. Mount,
dismount, interaction, damage, death, and inventory behavior continues through
the shared entity and container events.

Minecarts expose a typed `MinecartController` for bounded track speed, stop,
and reverse requests. `VehicleMoveEvent`, `MinecartRailChangeEvent`, and
`VehicleCollisionEvent` are the cancellable pre-commit boundaries;
`VehicleMovedEvent`, `MinecartRailChangedEvent`, and `VehicleCollidedEvent`
observe committed results. Rail geometry is exposed as immutable
`RailSegment`, `RailShape`, and `RailEndpoint` values and never as protocol
metadata or network block IDs.

`PlayerItemUseEvent` runs after the held stack, game mode, cooldown, and active-use rules have been validated. Cancelling it prevents the use from starting or applying. `PlayerItemUsedEvent` reports a committed instant or completed timed use, while `PlayerItemUseCancelledEvent` records why an active use ended without completing. `PlayerItemConsumeEvent` may cancel consumption or replace its bounded `ConsumptionResult`, including nutrition restoration and residue stacks. `PlayerItemConsumedEvent` reports the committed stack, nutrition, and residue result. `PlayerFoodLevelChangeEvent` may cancel or replace a bounded `Nutrition` snapshot; `PlayerFoodLevelChangedEvent` reports the committed state. `FoodLevelChangeCause::EXHAUSTION` includes accepted survival and adventure sprint movement.

`PlayerEquipmentChangeEvent` covers armor and offhand transitions. A listener may cancel it or replace the proposed immutable stack, which is revalidated for the target `EquipmentSlot` before commit. `PlayerEquipmentChangedEvent` reports the committed result. `PlayerItemDamageEvent` may cancel or adjust bounded durability loss; `PlayerItemBreakEvent` reports an item removed after its durability was exhausted. These APIs use gameplay enums such as `EquipmentSlot`, `ItemUseKind`, and `ItemDamageCause`, never protocol ordinals.

Natural regeneration enters `PlayerRegainHealthEvent` before health changes. A listener may cancel it or set a bounded amount. `PlayerRegainedHealthEvent` observes the committed result, and `HealthRegainCause::SATURATION` identifies the nutrition-driven path without exposing an internal numeric cause.

`PlayerDeathEvent` runs after lethal health commits but before Bedriox presents the death or commits inventory drops. It carries immutable victim and optional killer snapshots, the cause and final incoming damage, plus independent chat and death-screen messages. A plugin may set either message to a bounded raw string, a `Bedriox\Api\TranslatableMessage`, or `null` to suppress that presentation. Screen suppression retains the empty protocol handshake required for respawning. Death cannot be cancelled, but `setKeepInventory(true)` may retain the player's carried inventory and prevent death drops. Listener failure restores the inventory decision and both messages before later listeners run, and `MONITOR` remains read-only.

## Public server API

`PluginContext::server()` is the global discovery surface: `broadcastMessage()`, `getWorldManager()`, `getWhitelist()`, `getOnlinePlayers()`, `getPlayerByUuid()`, and exact case-insensitive `getPlayerByName()`. `broadcastMessage()` accepts raw or translated text and returns the number of connected recipients. Authoritative behavior belongs to the object it affects. `Player` sends messages, teleports, takes damage, changes game mode, exposes its authoritative inventory views, and owns a generation-bound effect manager. `World` reads and changes canonical blocks, exposes immutable weather through `getWeather()`, requests typed weather changes through `setWeather()`, and emits bounded typed particles to eligible chunk viewers. These operations enter the same authoritative paths as built-in causes. Mutations requested by an event listener are staged until that listener returns successfully, then enter the authoritative simulation queue for validation and synchronization. Stale player sessions and unloaded-world generations fail closed. See [particles](particles.md) for particle types, audiences, variables, and delivery limits, and [effects, potions, and brewing](effects-and-potions.md) for effect managers and lifecycle events.

### Worlds and dimensions

A public `World` always represents one exact loaded dimension. Use
`World::getDimensionType()` to inspect it and `World::getDimension()` to resolve
a sibling of the same named world and load generation. `WorldManager::get()`
and `getDefault()` accept an optional `DimensionType` and default to the
Overworld. `getLoaded()` returns one Overworld handle per loaded named world;
resolve another exact dimension from that handle or request it directly:

```php
use Bedriox\Api\World\DimensionType;

$worlds = $this->context()->server()->getWorldManager();
$overworld = $worlds->getDefault();
$nether = $overworld->getDimension(DimensionType::NETHER);
$customEnd = $worlds->get('adventure', DimensionType::END);
```

`Position` contains coordinates, optional rotation, and one optional exact
`World`. Omitted world and rotation values resolve against the affected entity;
once resolved, no separate dimension value is needed. `BlockPosition` remains
coordinate-only because the `World` receiving the block operation supplies the
dimension.

New named worlds may override generation independently for each dimension.
Overrides are keyed by the stable `DimensionType` string value and are persisted
with their generator, seed, bounded options, and optional spawn:

```php
use Bedriox\Api\World\DimensionCreationOptions;
use Bedriox\Api\World\DimensionType;
use Bedriox\Api\World\WorldCreationOptions;

$operation = $worlds->create('adventure', new WorldCreationOptions(
    generator: 'default',
    seed: 4815162342,
    dimensions: [
        DimensionType::NETHER->value => new DimensionCreationOptions(
            generator: 'myplugin:nether',
            seed: 108,
        ),
    ],
));
```

`PlayerPortalTravelEvent` exposes a resolved destination `Position`. A listener
may replace it with an exact world from another named world when its
`DimensionType` is compatible with that portal. Bedriox retains authority for
destination preparation, the client dimension transition, and rollback; the
plugin supplies only the destination.

The plugin-bound logger prefixes every record with the plugin name and supports `debug()`, `info()`, `notice()`, `warning()`, `error()`, and `critical()`.

`PluginContext::updates()` exposes the automatically selected update channel and the latest validated `UpdateInfo`, when a newer release is known. `UpdateAvailableEvent` is emitted once when a newly discovered version becomes available. Plugins may use this metadata for presentation, but Bedriox does not download or install updates automatically.

The whitelist API exposes enabled state, membership checks, atomic add/remove operations, entries, and reload. `WhitelistChangedEvent` is a post-change notification with `ENABLED`, `DISABLED`, `ENTRY_ADDED`, `ENTRY_REMOVED`, or `RELOADED`; admission still runs before player persistence and simulation startup.

`WeatherChangeEvent` runs before a natural, command, or plugin transition and may cancel it or replace the proposed bounded `WeatherState`. `WeatherChangedEvent` observes the committed state. Both expose `WeatherChangeCause`; plugins work with `WeatherType::CLEAR`, `RAIN`, and `THUNDER` rather than protocol event numbers.

`PluginContext::containers()` supplies plugin-scoped, world-explicit storage lookup and typed virtual inventories. Containers expose immutable snapshots plus bounded set, add, remove, clear, open, and close operations. Virtual sizes come from `ContainerLayout`, remain memory-only, and are released with their owning plugin. Container open, close, transaction, and chest-pair events use protocol-neutral values and preserve the same server-authoritative commit boundary. See [storage containers](containers.md) for the complete API and lifecycle contract.

`PluginContext::bossBars()` supplies an owner-scoped `BossBarManager`. A plugin creates a bar under a bounded lowercase ID, then adds connected `Player` viewers and changes its title, progress, color, style, or visibility. Colors and styles are stable API enums rather than Protocol values. Repeating an unchanged setter or viewer operation emits no packets. Removing a bar, disabling its owner, or isolating a failed owner removes the presentation and its invisible backing actor from every live viewer.

```php
use Bedriox\Api\BossBar\BossBarColor;
use Bedriox\Api\BossBar\BossBarStyle;

$bar = $this->context()->bossBars()->create(
    'raid',
    'Raid in progress',
    1.0,
    BossBarColor::RED,
    BossBarStyle::SEGMENTED_10,
);
$bar->addViewer($player);
$bar->setProgress(0.5);
```

`PluginContext::displays()` supplies an owner-scoped `DisplayManager` for
floating text. `createText()` accepts a world-explicit position, bounded text,
an optional stable lowercase key, and an optional initial `VisibilityPolicy`.
Applying the policy during creation prevents private displays from being sent
to an unintended player even briefly. A display has an immutable generated
UUID, can be moved or updated in place, supports tags and a bounded view
distance, and uses the same `getVisibility()` contract as entities. The
manager can find the owner's displays by key, UUID, or tag and can remove one
or all of them.

```php
$displays = $this->context()->displays();
$welcome = $displays->createText($spawnPosition, 'Welcome to the server', 'spawn-welcome');
$welcome->addTag('spawn');
$welcome->setViewDistance(64);
```

Use `showOnlyTo()` for a private audience, `hideFrom()` to exclude one player
from automatic visibility, `showToAll()` to restore automatic tracking, and
`hideFromAll()` to hide the display globally. The controller stores bounded
authenticated player UUID sets rather than retaining player objects.

Call `enableInteraction()` to attach an invisible, non-colliding interaction
region. Its default width and height are suitable for short floating text and
may be replaced with bounded dimensions. `DisplayInteractEvent` is the
cancellable pre-event for an admitted player interaction or attack;
`DisplayInteractedEvent` observes the committed result. Both identify the
display, interaction region, player, typed action, hand when applicable, and
optional hit position. Text replacement similarly uses
`TextDisplayChangeEvent` and `TextDisplayChangedEvent`.
An interaction region inherits its display's visibility policy, so a player
who cannot observe the display cannot trigger its interaction events.

Displays and their interaction regions are runtime-scoped plugin resources.
They are removed from current viewers when the owner disables or fails, and
they are not restored automatically after restart. Persist a plugin-owned
definition and recreate it during enablement when a display must be durable.

Player-shaped NPCs use `PluginContext::entities()->spawnHuman()`. They can
reuse a connected player's validated `getSkin()` value and share ordinary
entity events and controllers rather than introducing a separate NPC event
system. For example, listen to `EntityDamageByEntityEvent`, compare
`$event->entity->getUniqueId()` with the spawned human's ID, verify that
`$event->damager` is a `Player`, and cancel the event before sending a reply.
The [entity guide](entities.md#player-shaped-human-entities) covers human
control, navigation, lifecycle, and skin behavior.

`PlayerSkinChangeEvent` is a cancellable pre-event for a connected real
player's requested skin replacement and may supply another validated `Skin`.
`PlayerSkinChangedEvent` reports the committed result. These events are never
emitted for a sessionless human entity.

Blocks and items use canonical identifiers such as `minecraft:grass_block`; process-local numeric IDs never enter the public API. `VanillaBlocks` and `VanillaItems` expose every exact identifier admitted by the active Bedrock data set as a generated constant. `getIdentifier()` returns that canonical string, while `is()` accepts either an exact identifier or a semantic tag:

```php
use Bedriox\Api\Inventory\ItemTag;
use Bedriox\Api\Inventory\VanillaItems;
use Bedriox\Api\World\BlockTag;
use Bedriox\Api\World\VanillaBlocks;

if ($block->is(VanillaBlocks::OAK_DOOR)) {
    // This exact block is an oak door.
}

if ($block->getIdentifier() === VanillaBlocks::OAK_DOOR) {
    // Direct canonical-string comparison is also supported.
}

if ($block->is(BlockTag::DOOR)) {
    // This is any admitted door, but not a trapdoor.
}

if ($item->is(VanillaItems::DIAMOND_SWORD)) {
    // This exact stack contains a diamond sword.
}

if ($item->is(ItemTag::SWORD)) {
    // This stack contains any admitted sword.
}
```

Exact identity and semantic family are separate from state. A door's canonical identifier says which door it is, `BlockTag::DOOR` says which behavior family it belongs to, and its immutable `properties` describe the particular placed state, such as whether it is open and which direction it faces. Plugins should use exact constants or tags to classify a block instead of inferring its family from state-property names. Numeric internal state IDs, legacy IDs, and network runtime IDs remain private. The gameplay catalogs expose admitted terrain blocks, their drops, and supported tools without exposing those internal identities. Inventory writes accept catalog items or an empty slot.

`BlockTagRegistry::tagsFor()` and `ItemTagRegistry::tagsFor()` return the built-in families for a canonical identifier. Their `members()` methods return the canonical identifiers in one tag, and `contains()` performs the same direct membership test used by `is()`. These immutable registries describe built-in vanilla behavior; they are not a mutable plugin registration surface.

For example, a plugin can prevent players from operating any door without
depending on the door's material or current state:

```php
use Bedriox\Api\Event\Block\BlockInteractionType;
use Bedriox\Api\Event\Block\PlayerInteractBlockEvent;
use Bedriox\Api\Event\EventHandler;
use Bedriox\Api\World\BlockTag;

#[EventHandler]
public function onDoorUse(PlayerInteractBlockEvent $event): void
{
    if (
        $event->interaction === BlockInteractionType::USE
        && $event->block->is(BlockTag::DOOR)
    ) {
        $event->cancel();
    }
}
```

The generated vanilla catalogs are refreshed after an active Data update with:

```shell
php tools/generate-vanilla-catalogs.php
```

Added identifiers create constants, removed identifiers remove constants, and a constant-name collision stops generation rather than choosing an ambiguous name. Use `php tools/generate-vanilla-catalogs.php --check` to verify that the committed catalogs exactly match the active data set without rewriting them. Semantic tag membership is maintained separately because a tag describes Bedriox gameplay behavior rather than a wire or palette identity.

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
$player->giveItem(new ItemStack('minecraft:diamond', 1, nbt: $nbt));
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

The [ExamplePlugin repository](https://github.com/Bedriox/ExamplePlugin) contains a complete minimal project. API `0.4` is a preview contract and may make documented breaking changes before `1.0`.

### Player display and packet API

A connected `Player` exposes high-level presentation methods for ordinary messages, translated messages, popups, jukebox popups, tips, titles, subtitles, action bars, title timing, title clearing/resetting, toast notifications, and `swingArm()` for a visibility-scoped arm animation. Title timing uses `TitleTimes` in ticks. `sendTitle()` follows the retail-compatible order: timing, optional subtitle, then title. Ordinary strings use Bedrock's raw-text presentation, matching PocketMine-MP; `TranslatableMessage` uses the translation variant.

`Player::kick($reason, $quitMessage, $disconnectScreenMessage, $cause, $actor)` requests a server-initiated disconnect. A `PlayerKickEvent` listener may cancel it or change those messages; its immutable cause distinguishes plugin, operator, ban, whitelist, and server-policy actions, while `actor` identifies the responsible command sender when available. The method returns `false` for an offline player, cancellation, or an invalid/failed send. Transport timeouts, initial whitelist rejection, and ordinary client quits do not fire the kick event.

Every admitted player departure fires `PlayerQuitEvent` after authoritative removal. It exposes the immutable player, typed cause, detailed reason, and optional actor. Plugins may replace its bounded public quit message or set it to `null` to suppress the announcement; they cannot cancel cleanup. Bedriox logs the private reason and actor separately, so changing the public message does not conceal operational attribution.

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

The example generates `/playerinfo show <player> [detail:summary|full]` and `/playerinfo self [detail:summary|full]`. Invalid input is rejected before `execute()`, and Bedriox sends the binding error followed by every generated usage form. A message carried by `CommandResult::success()`, `information()`, `warning()`, or `failure()` is sent to the command sender with consistent success, informational, warning, or error formatting. `CommandResult::administrativeSuccess()` additionally publishes a muted audit message to the console and online players with `bedriox.command.broadcast.admin`; use it only for operator-visible state changes, never for private or sensitive command output.

For a command with one form, use `CommandArguments::create()->addArgument(...)` directly. Available parameter factories are `string`, `integer`, `float`, `boolean`, `onlinePlayer`, `players`, `entity`, `entities`, `choice`, backed `enum`, registered `softEnum`, `position`, `blockPosition`, `message`, `json`, `rawText`, and `literal`. Numeric parameters support `minimum()` and `maximum()`; non-literal parameters support `optional()` with an optional typed default. Required parameters cannot follow optional parameters, and greedy `message`, `json`, or `rawText` parameters must be last.

`CommandValues` provides matching named accessors such as `string()`, `integer()`, `float()`, `boolean()`, `player()`, `players()`, `entity()`, `entities()`, `choice()`, `enum()`, `position()`, `blockPosition()`, `message()`, `json()`, and `rawText()`. `onlinePlayer()` advertises a live soft enum of connected player names to the Bedrock command UI and resolves exactly one connected `Player` case insensitively. Join and disconnect updates refresh those suggestions. Because a player can disconnect after binding, command code may recheck `Player::isConnected()` before acting.

`entity()` and `entities()` advertise Bedrock target arguments and resolve immutable player snapshots or public `Entity` views. They accept exact player names, entity UUIDs, and `@a`, `@e`, `@n`, `@p`, `@r`, or `@s`. The optional `name`, `type`, `distance`, `limit`, and `sort` filters are validated before command code runs; selector input, filter count, and results are bounded. Spatial selectors use the executing player's position or the default-world spawn for console commands.

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

Player experience is exposed through `Player::getExperience()`. The returned manager provides an immutable snapshot with total points, derived level, and progress, plus bounded `setTotalPoints()`, `addPoints()`, and `removePoints()` requests. Mutations enter the authoritative queue and dispatch cancellable `PlayerExperienceChangeEvent` and observational `PlayerExperienceChangedEvent` instances with a typed cause.

Movement security exposes three focused events. `PlayerFlightToggleEvent` is cancellable after the server has already authorized flight. `PlayerMovementViolationEvent` reports a rejected high-confidence movement claim and its current score but cannot bypass authority. `PlayerMovementCorrectedEvent` is emitted after the authoritative correction is committed. Plugins must not treat any client movement field as permission to fly or bypass collision.
