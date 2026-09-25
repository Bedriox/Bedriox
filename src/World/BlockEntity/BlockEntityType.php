<?php

declare(strict_types=1);

namespace Bedriox\Server\World\BlockEntity;

/** Canonical server-side identities for the storage block entities currently admitted by Bedriox. */
enum BlockEntityType: string
{
    case Chest = 'minecraft:chest';
    case Barrel = 'minecraft:barrel';
    case ShulkerBox = 'minecraft:shulker_box';
    case EnderChest = 'minecraft:ender_chest';

    public function ownsPersistentInventory(): bool
    {
        return $this !== self::EnderChest;
    }
}
