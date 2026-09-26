<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Spawn\Natural;

enum NaturalDespawnDecision: string
{
    case KEEP_EXEMPT = 'keep_exempt';
    case KEEP_YOUNG = 'keep_young';
    case KEEP_NEAR_PLAYER = 'keep_near_player';
    case KEEP_NO_PLAYERS = 'keep_no_players';
    case DESPAWN_DISTANCE = 'despawn_distance';
    case DESPAWN_SOFT_DISTANCE = 'despawn_soft_distance';
}
