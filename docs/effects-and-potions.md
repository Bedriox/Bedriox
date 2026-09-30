# Authoritative effects, potions, and brewing

Bedriox owns status effects, potion delivery, and brewing as gameplay state. Protocol translates typed effect and container updates to the active Bedrock wire format, Data supplies the admitted brewing transition graph, and clients submit bounded intent rather than effect, projectile, or inventory authority.

## Effect ownership

Every player and living entity has one authoritative effect collection. `EffectType` identifies the current semantic effect, while immutable `EffectInstance` values carry duration in ticks, zero-based amplifier, particle visibility, ambient presentation, and infinite duration. The current Bedrock packet does not provide an independent effect-icon visibility bit. Stronger temporary effects retain eligible weaker effects as hidden fallbacks and promote them when the active instance ends.

Plugins access a generation-bound `EffectManager` through `Player::getEffects()` or `LivingEntity::getEffects()`. Snapshot reads use `all()`, `get()`, and `has()`; `add()`, `remove()`, and `clear()` enqueue bounded simulation work. A manager retained past disconnect, entity removal, or generation replacement cannot mutate the replacement object.

`EntityEffectAddEvent` and `EntityEffectRemoveEvent` run after core validation and before mutation. The add event may replace duration, amplifier, and presentation without changing the effect type; explicit removal may be cancelled. `EntityEffectAddedEvent` and `EntityEffectRemovedEvent` report committed state. Expiration, death, milk, food, potion, command, and plugin causes remain distinguishable without exposing wire IDs.

Item consumption has one authoritative cancellable item-use boundary. After that boundary accepts, inventory, nutrition, residue, and cooldown commit exactly once. Individual effect pre-events remain independent: cancelling one dose skips only that dose and never restores the consumed item or rolls back nutrition. Milk likewise consumes normally while retaining only effects whose individual removal events were cancelled.

## Potion delivery

`PotionType` covers the active release's admitted potion metadata domain, and `PotionContainer` distinguishes drinkable, splash, and lingering items. The internal potion catalog resolves an authoritative item identifier and auxiliary value once at the gameplay boundary. Numeric effect IDs remain owned by Protocol.

Drinkable potions complete through the ordinary item-use state machine. Survival consumption removes exactly one selected item, returns a glass bottle, and offers every resolved effect through its own cancellable pre-event. A rejected dose is skipped without rolling back accepted consumption, nutrition, cooldown, or residue. Milk independently offers each removable effect for removal and returns a bucket; cancelled removals retain only those effects. Golden-apple effects commit with their authoritative food result under the same per-effect rule.

Splash and lingering items spawn bounded server-owned projectiles using the player's accepted position and rotation. Collision, direct hits, four-block splash falloff, non-instant duration scaling, cloud creation, cloud radius, lifetime, victim cooldowns, and effect application are calculated in the simulation. Tipped arrows resolve their carried potion type from the authoritative arrow stack and apply reduced-duration effects only after an entity hit.

Projectile and cloud events are projected only to eligible viewers. A client does not choose the victims, dose, motion, lifetime, or item result.

## Brewing stands

`BrewingRecipeCatalog` indexes immutable container and potion mixes from Bedriox Data. `BrewingStandBlockEntity` owns a five-slot inventory, fuel amount, fuel capacity, brew timer, and persistence state. `BrewingStandProcessor` performs constant-time transition lookup and produces an immutable candidate state; `WorldSimulation` validates plugin events and installs the accepted state.

The slot layout is:

1. left bottle;
2. middle bottle;
3. right bottle;
4. ingredient; and
5. blaze-powder fuel.

A valid operation takes 400 ticks. One blaze powder provides 20 fuel uses. Starting a brew consumes one fuel use, and committing it consumes one ingredient while atomically replacing every still-valid bottle result. A changed or invalid recipe cancels progress without partial inventory mutation.

The block entity persists its inventory, fuel, capacity, and timer through the world provider. Active viewers receive authoritative slot, fuel, and progress updates. Window reopen, chunk reload, world restart, block removal, range checks, and disconnect use the same container lifecycle as other persistent storage.

Hopper automation is outside the current brewing boundary because Bedriox does not yet implement hoppers. A future hopper system must use the same authoritative container transaction path rather than mutating brewing state directly.

Brewing exposes four public events:

- `BrewingFuelConsumeEvent` is cancellable and may replace the bounded fuel-use value.
- `BrewingFuelConsumedEvent` observes committed fuel consumption.
- `BrewingEvent` is cancellable and may replace the three proposed bottle results.
- `BrewedEvent` observes the committed bottle results.

Pre-event cancellation leaves fuel, ingredients, bottles, and progress consistent. Event values are public block positions and immutable item stacks; plugins do not receive mutable block entities, internal timers, window IDs, stack-network IDs, or recipe tables.

## Runtime projection

`BedrockEffectTranslator` owns semantic-to-wire effect mapping. Player and living-actor effect changes become typed mob-effect packets, and active player effects are re-synchronized after admission when required. `BedrockWorldEventPacketEncoder` projects projectile actors, potion clouds, and particles without allowing simulation code to write packet IDs or raw metadata integers.

Brewing windows use the ordinary authoritative inventory request path plus typed container-property updates for fuel and progress. Crafting data includes the Data-owned potion and container mixes so the client can present the same transition graph, while the server still validates and commits every result.

## Change safety

Effect work must preserve replacement and hidden-fallback ordering, exact expiration, persistence bounds, death policy, client add/modify/remove projection, and plugin cancellation. Potion work must preserve selected-stack authority, residue, creative retention, projectile ownership, collision, falloff, clouds, and tipped-arrow scaling. Brewing work must preserve atomic five-slot mutation, recipe indexing, viewer synchronization, persistence, and correction of rejected client predictions.

Before retail qualification, test commands and plugin mutations, reconnect persistence, two-player visibility, every potion delivery form, milk and golden apples, brewing transitions, two viewers, cancellation, chunk unload, and restart during active progress. The public operator checklist is maintained in the Bedriox Docs repository.
