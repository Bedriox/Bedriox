<?php

declare(strict_types=1);

namespace Bedriox\Api\Inventory;

/** Why an authoritative active item-use session ended before completion. */
enum ItemUseCancellationReason: string
{
    case RELEASED_EARLY = 'released_early';
    case ITEM_CHANGED = 'item_changed';
    case SLOT_CHANGED = 'slot_changed';
    case PLAYER_DIED = 'player_died';
    case TELEPORTED = 'teleported';
    case GAME_MODE_CHANGED = 'game_mode_changed';
    case DISCONNECTED = 'disconnected';
    case INVALIDATED = 'invalidated';
    case PLUGIN = 'plugin';
}
