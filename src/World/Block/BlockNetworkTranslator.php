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

namespace Bedriox\Server\World\Block;

use Bedriox\Data\NetworkBlockStateRegistry;
use Bedriox\Protocol\Value\BlockNetworkId;
use InvalidArgumentException;

/** The sole translation boundary between Bedriox-owned state IDs and versioned Bedrock network IDs. */
final readonly class BlockNetworkTranslator
{
    /** @var list<BlockNetworkId> */
    private array $networkIdsByInternalId;

    /** @var array<int, int> */
    private array $internalIdsByNetworkId;

    public function __construct(
        private BlockStateRegistry $internal,
        NetworkBlockStateRegistry $network,
    ) {
        $networkIdsByInternalId = [];
        $internalIdsByNetworkId = [];
        foreach ($internal->states() as $state) {
            $internalId = count($networkIdsByInternalId);
            $networkId = BlockNetworkId::fromSigned($network->networkRuntimeId($state));
            if (isset($internalIdsByNetworkId[$networkId->signed()])) {
                throw new InvalidArgumentException('Bedrock network block-state mapping is not one-to-one.');
            }
            $networkIdsByInternalId[] = $networkId;
            $internalIdsByNetworkId[$networkId->signed()] = $internalId;
        }
        $this->networkIdsByInternalId = $networkIdsByInternalId;
        $this->internalIdsByNetworkId = $internalIdsByNetworkId;
    }

    public function toNetwork(InternalBlockStateId $internalId): BlockNetworkId
    {
        return $this->networkIdsByInternalId[$internalId->value]
            ?? throw new InvalidArgumentException('Internal block-state ID has no Bedrock network mapping.');
    }

    public function fromNetwork(BlockNetworkId $networkId): InternalBlockStateId
    {
        $internalId = $this->internalIdsByNetworkId[$networkId->signed()] ?? null;
        if (!is_int($internalId)) {
            throw new InvalidArgumentException('Bedrock network block-state ID has no internal mapping.');
        }
        return new InternalBlockStateId($internalId);
    }

    public function internalRegistry(): BlockStateRegistry
    {
        return $this->internal;
    }
}
