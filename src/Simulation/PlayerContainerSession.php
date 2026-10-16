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

namespace Bedriox\Server\Simulation;

use Bedriox\Api\Entity\Trade\VillagerTradeList;
use Bedriox\Api\Entity\Trade\VillagerTradeOffer;
use Bedriox\Api\Inventory\ContainerLayout;
use Bedriox\Api\Inventory\ContainerType;
use Bedriox\Api\Processing\EnchantingOption;
use Bedriox\Server\Entity\Mount\AnimalEquipmentSlotDeclaration;
use Bedriox\Server\Inventory\ContainerInventory;
use Bedriox\Server\Inventory\ResolvedWorldContainer;
use Bedriox\Server\Player\OpenedContainerInventory;
use Bedriox\Server\Simulation\Command\WorkstationRequest;
use Bedriox\Server\World\BlockPosition;

/** Authoritative open-window state; protocol identity is isolated to this per-player session. */
final class PlayerContainerSession
{
    /** @var array<int, EnchantingOption> Current authoritative options keyed by client-visible network ID. */
    public array $enchantingOptions = [];

    public int $nextEnchantingOptionNetworkId = 1;

    /** Offer network ID staged by a successful authoritative result extraction. */
    public ?int $completedTradeNetworkId = null;

    /** Plugin-adjusted offer validated before the staged inventory transaction commits. */
    public ?VillagerTradeOffer $completedTradeOffer = null;

    /** Number of uses staged by the current atomic trade request. */
    public int $completedTradeCount = 0;

    /** Internal rejection detail retained for protocol tracing and targeted client correction. */
    public ?string $workstationFailureReason = null;

    /** Offer-list generation captured when this trade window was opened. */
    public ?int $tradeGeneration = null;

    /** @var array<int, string> Client-visible recipe network ID to canonical offer key. */
    private array $tradeOfferKeysByNetworkId = [];

    public function __construct(
        public readonly int $windowId,
        public readonly ContainerType $type,
        public readonly ContainerInventory $inventory,
        public OpenedContainerInventory $projection,
        public string $canonicalRevision,
        public readonly ?BlockPosition $position = null,
        public readonly ?BlockPosition $pairedPosition = null,
        public readonly ?string $title = null,
        public readonly ?ContainerLayout $layout = null,
        public readonly ?ResolvedWorldContainer $worldContainer = null,
        public readonly bool $playerOwnedEnderChest = false,
        public readonly ?string $owningPlugin = null,
        public readonly ?int $entityRuntimeId = null,
        /** @var list<AnimalEquipmentSlotDeclaration> */
        public readonly array $animalEquipmentSlots = [],
        public ?WorkstationRequest $workstationRequest = null,
    ) {}

    public function replaceTradeOfferNetworkMap(VillagerTradeList $trades): void
    {
        $this->tradeOfferKeysByNetworkId = [];
        foreach ($trades->offers as $index => $offer) {
            $this->tradeOfferKeysByNetworkId[$index + 1] = $offer->key;
        }
    }

    public function tradeOfferKey(int $networkId): ?string
    {
        return $this->tradeOfferKeysByNetworkId[$networkId] ?? null;
    }

    public function tradeOfferNetworkId(string $key): ?int
    {
        $networkId = array_search($key, $this->tradeOfferKeysByNetworkId, true);

        return is_int($networkId) ? $networkId : null;
    }
}
