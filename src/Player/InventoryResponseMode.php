<?php

declare(strict_types=1);

namespace Bedriox\Server\Player;

/** Selects the acknowledgement required by the Bedrock inventory conversation which supplied an intent. */
enum InventoryResponseMode
{
    case ItemStackResponse;
    case LegacySlotSync;
}
