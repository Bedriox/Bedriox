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

namespace Bedriox\Server\Entity\Vehicle\Minecart;

use Bedriox\Api\Entity\Vanilla\ChestMinecart;
use Bedriox\Api\Inventory\ContainerType;
use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Vehicle\VehicleEntityDefinitions;
use Bedriox\Server\Entity\Vehicle\VehicleInventoryOwner;
use Bedriox\Server\Inventory\SimpleContainerInventory;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

final class ChestMinecartEntity extends AbstractMinecartEntity implements ChestMinecart, VehicleInventoryOwner
{
    private readonly SimpleContainerInventory $inventory;

    public function __construct(
        string $uniqueId,
        int $runtimeId,
        string $worldName,
        Position $position,
        EntityMotion $motion = new EntityMotion(),
        float $yaw = 0.0,
        float $pitch = 0.0,
        ?float $health = null,
    ) {
        parent::__construct(
            $uniqueId,
            $runtimeId,
            VehicleEntityDefinitions::chestMinecart(),
            $worldName,
            $position,
            $motion,
            $yaw,
            $pitch,
            $health,
        );
        $this->inventory = new SimpleContainerInventory('entity:minecart:' . $uniqueId, 27);
    }

    public function getContainerType(): ContainerType
    {
        return ContainerType::CHEST_MINECART;
    }

    public function getSeatCapacity(): int
    {
        return 0;
    }

    public function vehicleContainerType(): ContainerType
    {
        return ContainerType::CHEST_MINECART;
    }

    public function vehicleInventory(): SimpleContainerInventory
    {
        return $this->inventory;
    }

    public function markVehicleInventoryChanged(): void
    {
        $this->markChanged();
    }

    /** @return array{items: list<array{identifier: string, count: int, damage: int, auxValue: int, nbt: ?string}|null>} */
    protected function persistenceState(): array
    {
        return [
            'items' => array_map(
                static fn(?ItemStack $stack): ?array => $stack === null ? null : [
                    'identifier' => $stack->identifier,
                    'count' => $stack->count,
                    'damage' => $stack->damage,
                    'auxValue' => $stack->auxValue,
                    'nbt' => $stack->nbt === null ? null : base64_encode($stack->nbt->toBinary()),
                ],
                $this->inventory->contents(),
            ),
        ];
    }

    protected function restorePersistenceData(array $state): void
    {
        if (array_keys($state) !== ['items'] || !is_array($state['items'])
            || !array_is_list($state['items']) || count($state['items']) !== 27) {
            throw new InvalidArgumentException('Persisted chest-minecart inventory is malformed.');
        }
        $items = [];
        foreach ($state['items'] as $encoded) {
            if ($encoded === null) {
                $items[] = null;
                continue;
            }
            if (!is_array($encoded) || array_keys($encoded) !== ['identifier', 'count', 'damage', 'auxValue', 'nbt']
                || !is_string($encoded['identifier']) || !is_int($encoded['count'])
                || !is_int($encoded['damage']) || !is_int($encoded['auxValue'])
                || ($encoded['nbt'] !== null && !is_string($encoded['nbt']))) {
                throw new InvalidArgumentException('Persisted chest-minecart item is malformed.');
            }
            $binary = $encoded['nbt'] === null ? null : base64_decode($encoded['nbt'], true);
            if ($encoded['nbt'] !== null && $binary === false) {
                throw new InvalidArgumentException('Persisted chest-minecart item NBT is malformed.');
            }
            $items[] = new ItemStack(
                $encoded['identifier'],
                $encoded['count'],
                $encoded['damage'],
                $binary === null ? null : ItemNbt::fromBinary($binary),
                $encoded['auxValue'],
            );
        }
        $this->inventory->replaceContents($items);
    }
}
