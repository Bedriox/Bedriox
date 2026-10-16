# Villages, villagers, and trading

Generated villages are functional settlements rather than decorative building
groups. A deterministic village plan supplies roads, homes, beds, a bell, and
profession workstations. When its chunks become available, bounded population
work turns the plan's durable markers into villagers with biome-appropriate
types and professions. Population claims prevent repeated chunk loads from
duplicating the same resident.

## Villagers and points of interest

`Villager` extends the public ageable entity contract with immutable profession,
biome type, level, experience, and trade-list views. The finite public values
are `VillagerProfession`, `VillagerType`, `VillagerLevel`, and
`VillagerWorkstation`; plugins do not compare protocol metadata numbers.

Points of interest are indexed per exact world and dimension. A bed or
workstation can have one current villager claim, while each villager may own
one of each without one claim displacing the other. Claims are released when
their owner or block is no longer valid. Villagers use loaded points of
interest for profession and schedule behavior without synchronously loading
terrain. Village bells, beds, and work sites remain ordinary canonical blocks,
so world mutations and persistence continue through the existing block
authority.

## Daily life and hostile pressure

Villagers follow the world's clock. They work and restock during the work
period, return to an owned bed after dusk, open nearby wooden doors while
pathing home, close those doors after passing, sleep at the bed, and wake when
the rest period ends. Bed ownership, sleeping state, and work ownership are
durable. Sleep is projected with the current Bedrock sleeping flag and bed
position rather than a client-only animation shortcut.

Zombie-family actors search a bounded nearby-entity window for villagers when
they do not have a higher-priority player target. Pursuit and attacks use the
shared navigation, target, damage, immunity, and plugin event boundaries.
Villagers wake and enter their panic activity when threatened. A fatal zombie
attack never converts a villager on easy difficulty, has a fifty-percent
conversion chance on normal, and always converts on hard. Conversion uses the
standard cancellable transform event, produces a zombie villager without
ordinary death drops, and preserves the villager's profession, biome type,
age, and complete trade state for future curing.

Villagers share the standard entity lifecycle: authoritative spawning,
collision, damage, death, visibility, persistence, activation cadence, and
plugin event isolation. Iron golems created by village population use that same
entity foundation rather than a separate settlement-only actor system.

## Authoritative trades

Interacting with an eligible adult employed villager opens the current Bedrock
trade screen. `VillagerTradeList` contains at most 64 immutable,
uniquely-keyed `VillagerTradeOffer` values. Each offer names its required
villager level, one or two canonical input stacks, result stack, use limit,
villager and player experience behavior, and bounded demand and price values.

The client selects an offer, but it does not authorize the exchange. Bedriox
rechecks the live villager, offer generation, level, remaining uses, input
stacks, item limits, player inventory capacity, and open window before an
atomic transaction commits. A stale, exhausted, malformed, unaffordable, or
cancelled request consumes nothing and receives authoritative inventory and
offer correction. A successful trade consumes both inputs together, grants
the exact result, updates uses and experience, and refreshes the visible offer
state.

Trades and villager progression persist with the entity. Qualified villagers
restock at their claimed workstation on a bounded daily schedule. Restocking
creates a new offer generation so an already-open screen cannot commit against
stale prices or use counts.

## Plugin API and events

Plugins inspect current state through `Villager::getProfession()`,
`getVillagerType()`, `getVillagerLevel()`, `getVillagerExperience()`, and
`getTrades()`. `getActivity()`, `isSleeping()`, `getBedPosition()`, and
`getWorkstationPosition()` expose the authoritative schedule and ownership
state. Trade values are immutable; plugins replace an offer or list at the
event boundary rather than mutating server-owned state in place.

The villager event sequence is:

- `VillagerProfessionChangeEvent` may cancel or replace a proposed profession;
  `VillagerProfessionChangedEvent` observes the committed transition.
- `VillagerOffersPrepareEvent` may replace a bounded generated offer list;
  `VillagerOffersPreparedEvent` observes the list which became authoritative.
- `VillagerTradeEvent` may cancel a trade or replace the selected offer while
  preserving its stable key; `VillagerTradedEvent` reports the committed offer
  and resulting experience.
- `VillagerRestockEvent` may cancel or replace a restock list;
  `VillagerRestockedEvent` observes the committed daily restock.
- `EntityTargetEvent`, `EntityDamageByEntityEvent`, `EntityTransformEvent`, and
  their post-events cover hostile acquisition, attacks, and zombification.

All replacement stacks and economy values are validated again before commit.
Listeners cannot use these events to bypass item admission, inventory capacity,
offer limits, workstation ownership, entity availability, or plugin failure
isolation.

## Network and container boundary

The trade window is an entity-backed transient container. Its ingredient and
result slots participate in the same ordered stack-request pipeline as other
inventories, but they are not persistent general-purpose storage. Closing the
window, losing the villager, changing world, dying, or disconnecting tears down
the session and returns any remaining authoritative inputs through the normal
inventory path.

Runtime code alone translates canonical trade values into current packet NBT,
villager metadata, container slots, completion feedback, and corrections.
Each open trade window retains its own bounded mapping from the client-visible
recipe network IDs to stable offer keys, so list generations and internal order
never become gameplay authority.
Gameplay and plugin code never use packet IDs, network runtime IDs, window IDs,
or mutable wire NBT as trade authority.
