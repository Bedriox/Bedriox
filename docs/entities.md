# Entities, controllers, equipment, and loot

Bedriox owns every live non-player entity. Plugins receive protocol-neutral entity views and submit bounded mutations through controllers; they do not own runtime actor IDs, packet metadata, persistence records, spatial indexes, or network visibility.

## Public entity and controller hierarchy

`Entity` exposes identity, type, category, world, position, rotation, grounded state, persistence, and `getController()`. `LivingEntity` adds health and fire state. `Mob` adds its activation state.

Controllers are the mutation side of the same hierarchy:

```text
EntityController
`-- LivingEntityController
    `-- MobController
        `-- BreedableAnimalController
            |-- SheepController
            |-- PigController
            |-- RabbitController
            |-- TameableAnimalController
            |   `-- WolfController
            `-- MountController
```

`EntityController` supports availability checks, teleportation, rotation, velocity, name tags, name-tag visibility, immobility, invisibility, glowing, scale, gravity, fire, extinguishing, mounting, dismounting, and despawning. `LivingEntityController` adds damage, healing, direct bounded health changes, and equipment. `MobController` adds AI enablement, movement toward or away from a position, stopping, looking, targeting, and clearing the current target intent.

Always retain the entity view rather than a controller indefinitely, and check `isAvailable()` before a delayed mutation. Once the entity leaves its world, the old controller cannot mutate it.

```php
use Bedriox\Api\Entity\Mob;
use Bedriox\Api\Entity\Controller\MobController;
use Bedriox\Api\World\Position;

function makeGuard(Mob $mob): void
{
    $controller = $mob->getController();
    if (!$controller instanceof MobController || !$controller->isAvailable()) {
        return;
    }

    $controller->setNameTag('Guard');
    $controller->setNameTagVisible(true);
    $controller->setScale(1.2);
    $controller->setAiEnabled(false);
    $controller->teleport(new Position(12.5, 65.0, -4.5));
}
```

Controller input is validated before it reaches world state. Positions, rotations, velocities, names, scale, health, fire duration, steering speed, and target worlds remain bounded. Damage and combustion use their typed causes and enter the ordinary cancellable entity event path. A cross-world teleport succeeds only when the target world is already available.

Movement helpers express bounded steering, not a persistent pathfinding script. `target()` uses the target entity's authoritative snapshot for that control operation. `clearTarget()` stops the current horizontal steering intent; enabled built-in AI may select another target on a later AI tick. Disable AI when a plugin needs exclusive movement control.

Capabilities such as `Ageable`, `Breedable`, `Shearable`, `Undead`, and `RangedMob` live under `Api\Entity\Capability`; mutation gateways live under `Api\Entity\Controller`; read-only built-in species contracts live under `Api\Entity\Vanilla`. Concrete built-in entity implementations remain internal under `Server\Entity\Vanilla`.

`Aquatic` exposes immutable water-survival state through
`canBreatheUnderwater()`, `requiresWater()`, `getAirSupplyTicks()`, and
`getMaximumAirSupplyTicks()`. Cod, salmon, tropical fish, pufferfish, squid,
glow squid, dolphins, turtles, axolotls, drowned, and guardians use that
capability. They retain ordinary entity controllers, damage events,
persistence, loot, spawn events, visibility, and collision rather than a
separate water-only runtime.

Aquatic navigation retains a bounded three-dimensional heading instead of
choosing a new direction every tick. Velocity and actor rotation are derived
from the same vector, prospective swim headings remain in loaded water, and
stranded water-only mobs do not glide across land. Drowned, turtles, and
axolotls use horizontal obstacle-aware movement when they are out of water.
Drowned and guardians retain idle swim motion even when no player is available
as a target. Ordinary land-mob steering treats nearby water as undesirable
terrain instead of walking into it as though it were dry ground. Water remains
physically enterable through external forces; submerged living entities without
underwater breathing lose their bounded air supply and then receive ordinary
cancellable drowning damage.

Supported fish and axolotls can be captured with a water bucket and released
from their filled bucket. Capture uses the existing cancellable interaction
event; release uses `SpawnCause::BUCKET`. Passive water mobs have a distinct
bounded `WATER` population cap, while drowned and guardians remain monsters.

## Common passive animals

Cow, sheep, pig, chicken, and rabbit share one bounded authoritative age and breeding lifecycle. Species-specific foods drive temptation, baby growth, and adult breeding. `EntityBreedEvent` can cancel the child or adjust bounded experience before spawn; `EntityBredEvent` observes the committed parents and child. Parents enter cooldown only after the child commits.

Cows support bucket milking. Pigs persist saddle state. Chickens fall slowly and lay eggs on a durable bounded timer. Rabbits persist a typed variant and use hopping ground movement. Every species has an exact spawn-egg definition, natural-spawn entry, durable state, multiplayer projection, and adult loot table; babies do not produce ordinary adult drops.

## Vehicles and passengers

Every world owns one transient mount registry. A passenger has at most one vehicle, each finite `MountSeat` can hold at most one passenger, cyclic relationships are rejected, and links are removed when either side dies, despawns, disconnects, teleports, or changes world. Mount relationships are intentionally not restored after a restart.

Players can inspect and control a live relationship through `Player::getVehicle()`, `isRiding()`, `mount()`, and `dismount()`. Entities expose `getVehicle()`, `isRiding()`, `getPassengers()`, and `hasPassengers()` without exposing packet actor-link values. `EntityMountEvent` and `EntityDismountEvent` run before ordinary player or plugin transitions; `EntityMountedEvent` and `EntityDismountedEvent` observe committed state. Lifecycle-forced dismounts cannot be cancelled.

Vehicle implementations own local three-dimensional seat attachment points.
The authoritative rider position and Bedrock seat metadata use that same
attachment, including the Happy Ghast's front pilot seat and three clockwise
passenger seats above its harness.

Boats, chest boats, bamboo rafts, and bamboo chest rafts are authoritative
vehicle entities. Every current wood variant is represented by `BoatVariant`.
A normal boat has a driver and passenger seat; a chest boat reserves its second
seat for a durable 27-slot inventory. Item use creates the matching entity and
only consumes the held item after the spawn commits. Boats float and steer from
validated player input while their hull remains locked to the top of the loaded
connected water column. Downward client motion cannot submerge a supported boat.
Their collision, structural hit feedback, destruction, drops, passengers, and
storage remain server-owned.

`VehicleControlEvent` runs before driver motion is accepted. Plugins may cancel
the input or replace its normalized forward, strafe, yaw, and paddle state.
`VehicleControlledEvent` observes the committed control input. The ordinary
spawn, interaction, damage, death, mount, dismount, and inventory events also
apply, so vehicle support does not introduce duplicate lifecycle hooks.

Adult saddled pigs, horses, donkeys, mules, camels, llamas, trader llamas,
skeleton horses, and zombie horses use the same authoritative passenger
registry. Horse-family mounts retain owner, temper, saddle, age, and breeding
state when applicable. Camels provide two finite seats. Llamas can be ridden
after taming but are not rider-steered and accept carpets rather than saddles.
Skeleton horses are intrinsically tamed and rider-controlled without saddles;
other steerable horse-family mounts require their supported saddle state.
Interact to mount and use the client's ordinary exit-vehicle control to
dismount. Vehicle physics, jumping, collision, seat ownership, actor links,
and late-join reconstruction remain server-owned.

## Tameable and neutral land animals

Wolf and cat ownership uses canonical player UUIDs and survives entity unload,
reload, and restart. Bones tame wolves; raw cod or salmon tame cats. A
successful tame passes through cancellable `EntityTameEvent` and committed
`EntityTamedEvent` and presents the current client's success hearts; failed
attempts present the ordinary failure response. Owners may toggle sitting with
an empty hand or an item that has no applicable feeding action. A seated pet
can always be released by its owner, including immediately after taming while
the taming item remains selected. Sitting stops decision and movement work.
Standing companions follow their online owner through the bounded AI view.
Wolves remember and attack a player who damages them, except their owner, and
expose that state through `Angerable` and `WolfController`.

The dedicated neutral and passive roster also includes ocelots, foxes, goats,
pandas, polar bears, armadillos, mooshrooms, and sniffers. Each has an exact
type, implementation, dimensions, health, variant or species state where
applicable, persistence, spawn-egg path, actor projection, and loot policy.
Eligible species participate in bounded natural spawning; village-, trader-,
structure-, and event-owned species remain explicit spawns until their owning
world systems exist. Goats and mooshrooms can be milked, and mooshrooms fill a
bowl with stew.

Species-specific advanced actions such as fox item carrying and pouncing, goat
ramming, panda activities, armadillo scute production, mooshroom shearing,
sniffer digging, and complete horse inventory and jump-charge screens remain
separate gameplay increments. Their typed state does not imply those actions
are already implemented.

## Common hostile mobs

The specialized hostile roster now includes husks, zombie villagers, strays, bogged, parched, wither skeletons, spiders, cave spiders, creepers, slimes, magma cubes, endermen, endermites, silverfish, and witches in addition to zombies and skeletons. Each admitted species has its own public `Vanilla` contract and internal implementation rather than sharing a generic catalog actor. Exact dimensions, health, daylight sensitivity, equipment, loot, persistence, spawn-egg identity, metadata, and natural-spawn eligibility remain definition-owned.

Spider movement uses the reusable `Climbing` capability and only climbing species project the climb actor flag. `SlimeSize` expresses the finite small, medium, and large forms used by slimes and magma cubes; size controls dimensions and health, survives unload and restart, and a non-small death may produce a bounded number of next-smaller children. Magma cubes are fire-immune. Endermen take authoritative water damage.

`CreeperController` extends ordinary mob control with bounded charged and ignited state changes while retaining the ordinary availability-checked, owner-attributed mutation boundary. Enderman carried-block mutation is intentionally withheld until the canonical block state can be projected exactly to the current client.

Hostile ranged behavior remains projectile-owned. Strays and bogged launch their supported tipped arrows, witches launch bounded splash potions, and cave-spider melee applies its difficulty-sensitive poison through the ordinary effect and damage paths. Creepers retain charged, ignition, and fuse state, cancel a proximity fuse when the target escapes, and commit a bounded server-owned explosion after `EntityExplosionPrimeEvent`. Explosion radius is capped at 16 blocks, the planner reads only loaded terrain, the result is capped at 4,096 unique blocks and 256 unique actors, bedrock is never destroyed, and `EntityExplodedEvent` follows commit.

The public hostile event foundation also includes cancellable `EntitySplitEvent`, adjustable `EntityTransformEvent`, and adjustable `EntityBlockChangeEvent`, with immutable `EntityTransformedEvent` and `EntityBlockChangedEvent` observations. Slime and magma-cube splitting is connected to `EntitySplitEvent`. Transformation and entity-owned block-change events define the bounded API boundary for mechanics that use them; the current common-hostile runtime does not claim zombie curing, enderman block pickup or placement, or other transformations merely because those event classes exist.

Natural hostile work remains inside the shared regional cap, local-density
limit, spawn cadence, elapsed-time budget, loaded-terrain checks, and
distance-despawn policy. Overworld and Nether selection use their qualified
environment-appropriate subsets. Infestation-created and other event-owned
species remain available through admitted explicit spawn paths until their
owning gameplay system provides the required context.

## Nether entities

Blazes, Ghasts, Happy Ghasts, Hoglins, Piglins, Piglin Brutes, Striders,
Zoglins, and Zombified Piglins have canonical public entity contracts and
dedicated internal implementations. They share the ordinary spawn, damage,
equipment, death, mount, visibility, persistence, and plugin-controller paths;
there is no separate Nether-only runtime.

Natural Nether spawning uses loaded dimension, biome, fluid, and support-block
context. Striders spawn in lava, fortress floors admit Blazes and Wither
Skeletons, bastion floors admit Piglin Brutes, and the remaining families use
their eligible Nether biomes. Blaze, Ghast, and Happy Ghast movement is
three-dimensional. Fire-immune families project the corresponding actor state
and do not receive combustion damage.

Piglins accept or collect gold ingots and complete bounded barters. Ordinary
Piglins remain neutral to players wearing gold armor until provoked. Nearby
members of the appropriate Piglin family share player provocation through the
normal target events. `EntityPickupItemEvent`, `EntityPickedUpItemEvent`,
`PiglinBarterEvent`, and `PiglinBarteredEvent` expose the authoritative pickup
and barter boundaries. A cancelled barter returns the admitted payment.

Hoglins breed with crimson fungus and convert into Zoglins outside the Nether.
Piglins and Piglin Brutes convert into Zombified Piglins outside the Nether.
The ordinary transform events run before and after replacement, and committed
transformations retain health proportion and equipment. Striders breed with
warped fungus, accept saddles, steer with warped fungus on a stick, float on
lava, and take water or exposed-rain damage. Harnessed adult Happy Ghasts use
the shared mount registry with four seats and retain persistent growth,
breeding, and harness state.

Blazes launch small fireballs; Ghasts launch explosive fireballs. Fireballs
use authoritative collision, damage, ignition, ownership, and lifetime. A
player attack may reflect one through `ProjectileReflectEvent`, followed by
`ProjectileReflectedEvent` after ownership and velocity commit.

## Transaction and lifecycle boundary

Controller mutations requested during a plugin listener or custom-mob lifecycle callback join that callback's owner-attributed action transaction. They commit only after the callback returns successfully. An exception discards staged work, disables the failing plugin through the normal isolation path, and leaves unrelated entities and players running. `MONITOR` listeners cannot stage controller actions.

Outside a plugin callback, controller methods execute on the authoritative simulation thread. They do not make off-thread entity access safe. Asynchronous plugin work must return its result through the scheduler completion boundary before touching an entity or controller.

Custom-mob `onTick()` and `onAiTick()` callbacks receive the same controller contract. Their work remains subject to entity availability, activation cadence, the AI budget, world bounds, and normal collision and projection rules.

## Living-entity equipment

`LivingEntityController::equipment()` returns `EntityEquipment`. Its finite slots are `HEAD`, `CHEST`, `LEGS`, `FEET`, `MAIN_HAND`, and `OFF_HAND`. Plugins may read or replace one slot, clear one or all slots, enumerate occupied contents, and read or set each slot's drop chance from `0.0` through `1.0`.

Every transition is checked against the active item catalog. Stack size, armor-slot compatibility, offhand admission, durability, auxiliary value, and bounded item NBT remain authoritative. A successful change is persisted with the entity and projected to current viewers. Armor reduces applicable incoming damage and takes durability wear; a zombie helmet can absorb daylight exposure until it breaks. A supported main-hand tool or weapon contributes its registered attack damage.

`EntityEquipmentChangeEvent` runs before an equipment transition. It may cancel the transition or replace both the proposed immutable `ItemStack` and drop chance. The replacement is validated again. `EntityEquipmentChangedEvent` is the immutable post-event for the committed state. Nested equipment transitions from the same transition callback are rejected.

Naturally spawned zombies may receive difficulty-aware armor or a held tool. Generated equipment uses an 8.5 percent per-slot drop chance. Command, spawn-egg, restored, and plugin-created entities are not assigned natural equipment by that rule.

## Death loot

Entity loot is evaluated exactly once after lethal health commits. The stable result is passed to the non-cancellable `EntityDeathEvent` before item actors are created. A listener may inspect `getDrops()`, replace the complete list with `setDrops()`, append one stack with `addDrop()`, or suppress loot with `clearDrops()`. Listener failure restores the previous drop list before dispatch continues.

The event accepts at most 128 immutable `ItemStack` values. The built-in resolver validates catalog membership, respects each item's maximum stack size, coalesces equivalent state, and caps its own output at 64 stacks and 4,096 items. Final item creation is also subject to the world's bounded item-entity capacity; an invalid or excess plugin-supplied stack is skipped without terminating the server tick.

Species-specific tables cover the common Overworld roster and supported Nether
families. Burning animals produce the applicable cooked meat. Pig saddles,
sheep wool, feathers, hides, blaze rods, Ghast drops, Hoglin meat and leather,
Strider string, and Zombified Piglin drops use the same normalized item-entity
path. Equipped items are evaluated independently using their stored per-slot
chance, preserving identifier, count, damage, auxiliary value, and NBT.

Custom entities and other admitted species currently have an empty base table. Plugins can supply their drops through `EntityDeathEvent`; a public loot-table registrar is not yet available. Fake-human entities and a general NPC controller are separate milestones.
