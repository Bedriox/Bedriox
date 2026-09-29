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

namespace Bedriox\Server\Simulation\Command;

use Bedriox\Server\Player\InventorySlotReference;
use InvalidArgumentException;

/** Bounded semantic selection accompanying an atomic workstation stack request. */
final readonly class WorkstationRequest
{
    /** @var list<InventorySlotReference> */
    public array $responseSlots;

    /** @param array<mixed> $responseSlots */
    public function __construct(
        public WorkstationRequestType $type,
        public ?int $recipeNetworkId = null,
        public ?string $filteredText = null,
        public ?string $patternId = null,
        public int $requestedCrafts = 1,
        public int $reportedCost = 0,
        array $responseSlots = [],
    ) {
        if (($recipeNetworkId !== null && ($recipeNetworkId < -0x80000000 || $recipeNetworkId > 0xffffffff))
            || ($filteredText !== null && (strlen($filteredText) > 4_096 || preg_match('//u', $filteredText) !== 1))
            || ($patternId !== null && preg_match('/^[a-z0-9_.:-]{1,128}$/D', $patternId) !== 1)
            || $requestedCrafts < 0 || $requestedCrafts > 64
            || $reportedCost < -0x80000000 || $reportedCost > 0x7fffffff) {
            throw new InvalidArgumentException('Workstation request is outside its supported bounds.');
        }
        $validatedResponseSlots = [];
        foreach ($responseSlots as $slot) {
            if (!$slot instanceof InventorySlotReference) {
                throw new InvalidArgumentException('Workstation response slots contain an invalid value.');
            }
            $validatedResponseSlots[] = $slot;
        }
        $this->responseSlots = $validatedResponseSlots;
    }
}
