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

namespace Bedriox\Server\Entity\Persistence;

use Bedriox\Api\World\WorldDimension;

final readonly class EntityOwnershipTransferCompletion
{
    public function __construct(
        public EntityOwnershipTransfer $transfer,
        public bool $successful,
        public ?string $failureCode = null,
        public ?string $failureDetail = null,
        public ?EntityOwnershipTransferResult $result = null,
        public WorldDimension $dimension = WorldDimension::OVERWORLD,
    ) {
        if ($successful === ($failureCode !== null)
            || ($successful && $failureDetail !== null)
            || $successful !== ($result instanceof EntityOwnershipTransferResult)) {
            throw new \InvalidArgumentException('Entity ownership transfer completion result is inconsistent.');
        }
    }
}
