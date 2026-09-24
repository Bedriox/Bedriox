<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

/** Stable simulation reasons for ending an active use without committing its effect. */
enum ItemUseCancellationReason
{
    case RELEASED;
    case HELD_ITEM_CHANGED;
    case DEATH;
    case TELEPORT;
    case GAME_MODE_CHANGED;
    case DISCONNECTED;
    case TOO_EARLY;
    case TIMED_OUT;
    case PLUGIN;
}
