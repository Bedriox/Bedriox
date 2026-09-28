# Storage containers

Bedriox owns chest, trapped-chest, barrel, shulker-box, and Ender Chest sessions as server-authoritative inventories. A client may describe a move, split, merge, swap, or drop, but the currently open server window, canonical inventory contents, stack lineage, and item rules decide whether the complete transaction commits. A successful request returns every affected slot using the client's current full-container name and authoritative stack identity; a rejected or stale request changes neither side and receives an authoritative correction.

## Durable storage

Chest, trapped-chest, barrel, and shulker-box contents are immutable block-entity state inside the owning chunk and are saved through the world provider. A paired chest presents one deterministic 54-slot inventory while retaining two independently persisted 27-slot halves. Both halves are validated before either replacement is installed.

Ender Chest contents belong to the authenticated player, not to the physical block. The 27 slots travel with that player's versioned profile and every Ender Chest block opens the same private inventory. Other players never share it.

Shulker boxes retain their bounded contents and custom block name in portable item NBT when broken in survival. Placing that server-owned item restores its inventory while deriving the new facing from the placement. Shulker items stack to one and cannot be nested inside an open shulker box through a player transaction.

World storage closes when its backing block disappears or the viewer moves out of range, teleports, dies, disconnects, opens another window, or the server closes it. First-viewer and last-viewer transitions drive chest-style animations and the barrel's canonical `open_bit`; one viewer leaving cannot close the presentation for another viewer.

## Plugin API

`PluginContext::containers()` returns a plugin-scoped `ContainerManager`. World-container lookup requires the owning loaded-world handle plus a canonical block position; virtual creation remains world-independent:

```php
use Bedriox\Api\Inventory\ContainerLayout;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\World\BlockPosition;

$containers = $this->context()->containers();
$worldChest = $containers->at($world, new BlockPosition(10, 65, -4));
$menu = $containers->create(ContainerLayout::HOPPER, 'Travel menu');

$menu->setItem(0, new ItemStack('minecraft:apple', 4));
$menu->open($player);
```

Supported virtual layouts are single chest, double chest, hopper, dispenser, and dropper. Their sizes come from `ContainerLayout`; plugins cannot supply arbitrary slot counts. Virtual inventories are memory-only, owned by the creating plugin, closed and released when that plugin disables, and never masquerade as world storage. The optional title is retained as API metadata; current clients use the layout's standard screen title until a dedicated virtual-title presentation is introduced.

Every handle resolves current state and uses its opaque revision for mutation. `setItem()`, `addItem()`, `removeItem()`, and `clear()` are bounded intent which is revalidated by the simulation. Cached managers and handles reject use after their owner disables. Plugins receive immutable `ContainerView` and `InventoryView` values, never window IDs, stack-network IDs, packets, mutable block entities, or internal inventory objects.

Container lifecycle and transactions expose these typed events:

- `InventoryOpenEvent` is cancellable before a window is established.
- `InventoryOpenedEvent` observes a completed open.
- `InventoryCloseEvent` observes non-cancellable teardown and its typed reason.
- `InventoryTransactionEvent` is cancellable after authoritative validation but before mutation.
- `InventoryTransactionCommittedEvent` observes only a fully committed transaction.
- `ChestPairEvent` may cancel automatic pairing during placement.
- `ChestPairedEvent` observes the committed pair.

Cancelling a transaction does not partially mutate the player or storage inventory. Event actions are staged, and a changed player state, window, or container revision invalidates the pending commit instead of overwriting newer state.

## Network boundary

Loaded chunks include bounded client-visible block-actor NBT. Live storage placement and chest pair or unpair changes publish fresh block-actor snapshots to current viewers; item contents remain on inventory packets and are not exposed in block-actor NBT. Dynamic windows use typed container-open, content, slot-response, block-event, and close packets while the simulation remains independent of their numeric protocol values.
