# Mob spawners and trial spawners

Bedriox treats spawners as persistent world state. Their configuration and
progress are stored with the owning chunk, while `WorldSimulation` remains the
only authority allowed to activate a spawner, admit an entity, advance a trial,
or grant a reward. Loading a chunk restores the same delay, encounter phase,
participants, active mobs, and cooldown instead of starting the block again.

## Ordinary mob spawners

An ordinary spawner activates only while an eligible player is within its
configured range. Each attempt revalidates the configured canonical entity
identifier, loaded terrain, the spawn position, collision, light and other
species rules, and the nearby-entity limit before submitting an authoritative
spawn. A blocked or cancelled attempt does not create a partial actor.

`SpawnerConfiguration` defines the bounded entity identifier, minimum and
maximum delay, spawn count, nearby-entity limit, required-player range, and
spawn range. Generated dungeons, silverfish rooms, and Nether-fortress spawners
install their configuration as block-entity state rather than relying on a
global list or a hard-coded runtime coordinate.

## Trial spawners

Trial spawners use the explicit `TrialSpawnerPhase` lifecycle:

```text
INACTIVE -> WAITING_FOR_PLAYERS -> ACTIVE -> EJECTING_REWARDS -> COOLDOWN
```

The participant set is bounded and stable for one encounter. Its size controls
the total admitted mob count through `TrialSpawnerConfiguration`, while the
simultaneous-mob limit prevents one trial from flooding the entity budget.
Completion is recognized only after the required number of mobs has spawned
and no encounter-owned mob remains alive. Rewards are then admitted by the
server before the persisted cooldown begins.

Native trial-spawner data which Bedriox does not yet understand is preserved
when its chunk is saved. Bedriox-created trial state uses its own validated
representation and never treats untrusted block-entity data as live runtime
configuration without decoding and bounds checks. The live encounter runtime
currently applies only to trial spawners created or configured by Bedriox;
imported native trial-spawner records remain preserved but inactive until their
versioned data contract is supported explicitly.

## Plugin events

Plugins can observe and influence the authoritative boundaries without owning
the scheduler or a spawner's mutable state:

- `SpawnerActivateEvent` can cancel ordinary or trial activation.
- `SpawnerSpawnEvent` can cancel one admitted spawn after core checks and
  before entity creation.
- `TrialSpawnerStateChangeEvent` can cancel a proposed trial phase change.
- `SpawnerCompleteEvent` observes a committed trial completion and its bounded
  participant identities.

Cancellation affects only that transition or spawn attempt. It does not bypass
range checks, collision, loaded-terrain requirements, entity capacity, or the
normal entity spawn events. Post-events are observational and `MONITOR`
listeners cannot mutate them.

## Performance boundary

Spawner work is driven by loaded block entities and finite per-tick budgets.
The runtime does not scan every block in every loaded chunk, generate terrain
to find a spawn position, or run an unbounded number of catch-up attempts after
a pause. Spawner-created actors enter the same activation, spatial indexing,
persistence, visibility, and despawn systems as other entities.
