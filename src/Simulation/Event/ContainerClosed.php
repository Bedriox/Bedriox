<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Api\Inventory\ContainerLayout;
use Bedriox\Api\Inventory\ContainerType;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;

/** Authoritative teardown of one player's storage window and its world presentation. */
final readonly class ContainerClosed implements WorldEvent
{
    /** @param list<string> $blockEventRecipientSessionIds */
    public function __construct(
        public string $ownerSessionId,
        public int $windowId,
        public ContainerType $containerType,
        public ?BlockPosition $position,
        public array $blockEventRecipientSessionIds,
        public ?BlockPosition $pairedPosition = null,
        public bool $serverInitiated = true,
        public ?ContainerLayout $layout = null,
    ) {
        $double = in_array($containerType, [ContainerType::DOUBLE_CHEST, ContainerType::DOUBLE_TRAPPED_CHEST], true);
        if ($windowId < 2 || $windowId > 99
            || ($containerType === ContainerType::VIRTUAL) !== ($position === null)
            || ($containerType === ContainerType::VIRTUAL) !== ($layout !== null)
            || $double !== ($pairedPosition !== null)) {
            throw new InvalidArgumentException('Storage-container close projection is invalid.');
        }
    }

    public function recipients(): array
    {
        return array_values(array_unique([$this->ownerSessionId, ...$this->blockEventRecipientSessionIds]));
    }
}
