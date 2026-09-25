# Authoritative crafting

Bedriox owns personal two-by-two and crafting-table three-by-three crafting as atomic inventory transactions. The client selects an advertised recipe and describes its predicted inventory actions, but the server resolves the active recipe, matches the current authoritative inputs, derives every output, and commits consumption and output movement together.

## Code ownership

- `Gameplay/Crafting/CraftingCatalog.php` loads the admitted Data catalog, assigns session-facing network identities, projects current Protocol recipe values, and publishes revisioned plugin overlays.
- `CraftingRecipeRegistry.php`, `ShapedRecipe.php`, and `ShapelessRecipe.php` own bounded deterministic lookup and matching.
- `ComplexCraftingRecipeEvaluator.php` owns input-derived grid recipes such as repair, maps, books, fireworks, banners, shields, and decorated pots.
- `Player/PlayerInventory.php` owns the transient crafting slots and stages complete stack-request mutations before commit.
- `Simulation/WorldSimulation.php` validates the grid, consumption, outputs, plugin events, and inventory capacity before mutating the live player.
- `Runtime/BedrockPlayChannel.php` translates bounded current-protocol crafting actions into simulation intent. It never trusts client-reported result stacks.

Protocol owns recipe and stack-request wire codecs. Data owns immutable recipe records and source coverage. Neither component mutates inventories or runs plugin code.

## Session lifecycle

Every player has a transient personal grid. A three-by-three grid exists only while a validated crafting-table session is active. Closing the screen, disconnecting, dying, teleporting, or invalidating the table returns inputs to the authoritative main inventory; normal dropped-item overflow handles any remaining stacks. Crafting inputs are never persisted as an open container.

Connected clients receive one clean complete catalog when plugin recipes change. Recipe network IDs are runtime reconciliation values and must not be stored or exposed as plugin identities.

## Plugin API

Plugins register immutable shaped or shapeless recipes through their owned recipe registrar. Registrations may occur while the plugin is enabled, are validated and projected before publication, and are removed automatically when ownership ends. A plugin cannot replace a built-in recipe or a recipe owned by another plugin.

`PlayerCraftItemEvent` runs after authoritative validation and before mutation. It may cancel the transaction or replace outputs within the validated item-count budget. `PlayerCraftedItemEvent` runs only after a successful commit. Both expose public recipe, grid, and item value objects without packet or mutable inventory internals.

## Change safety

Crafting changes must preserve atomic rollback, stack-network lineage, grid cleanup, full catalog projection, and server-derived outputs. Add focused coverage for the affected recipe family and run the complete crafting, inventory, play-channel, initialization, event projection, and runtime tests before retail qualification.
