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

namespace Bedriox\Server\Inventory;

use Bedriox\Api\Inventory\ContainerType;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Server\Gameplay\Potion\BrewingStandBlockEntity;
use Bedriox\Server\World\BlockEntity\BlockEntity;
use Bedriox\Server\World\BlockEntity\BlockEntityType;
use Bedriox\Server\World\BlockEntity\ContainerBlockEntity;
use Bedriox\Server\World\BlockEntity\ContainerInventory as PersistentContainerInventory;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\World;
use LogicException;

/**
 * Adapts immutable persisted block entities to canonical live container inventories.
 *
 * Viewer state stays live-only. Every successful mutation is explicitly installed back into the
 * owning immutable chunk before it is acknowledged to a player.
 */
final class WorldContainerStore
{
    /** @var array<string, SimpleContainerInventory> */
    private array $inventories = [];

    /** @var array<string, CombinedContainerInventory> */
    private array $combinedInventories = [];

    /** @var array<string, array{BlockPosition, BlockPosition}> */
    private array $combinedPositions = [];

    public function __construct(private readonly World $world) {}

    public function resolve(BlockPosition $position, string $blockIdentifier): ?ResolvedWorldContainer
    {
        $type = self::apiType($blockIdentifier, false);
        if ($type === null || $type === ContainerType::ENDER_CHEST) {
            return null;
        }
        $entity = $this->world->blockEntityAt($position);
        if ((!$entity instanceof ContainerBlockEntity && !$entity instanceof BrewingStandBlockEntity)
            || !self::entityMatchesBlock($entity, $blockIdentifier)) {
            return null;
        }
        $first = $this->inventory($entity);
        if ($entity instanceof BrewingStandBlockEntity) {
            return new ResolvedWorldContainer($type, $position, $first, customName: $entity->customName);
        }
        if ($entity->type !== BlockEntityType::Chest || $entity->pairedPosition === null) {
            return new ResolvedWorldContainer($type, $position, $first, customName: $entity->customName);
        }
        $pair = $this->world->blockEntityAt($entity->pairedPosition);
        if (!$pair instanceof ContainerBlockEntity
            || $pair->type !== BlockEntityType::Chest
            || $pair->pairedPosition?->equals($position) !== true) {
            return new ResolvedWorldContainer($type, $position, $first, customName: $entity->customName);
        }
        $second = $this->inventory($pair);
        [$lead, $other] = $entity->pairLead ? [$entity, $pair] : [$pair, $entity];
        $left = $lead === $entity ? $first : $second;
        $right = $lead === $entity ? $second : $first;
        $doubleType = self::apiType($blockIdentifier, true)
            ?? throw new LogicException('A paired chest has no public container type.');

        $combinedIdentifier = self::combinedIdentifier($lead->position, $other->position);
        $combined = $this->combinedInventories[$combinedIdentifier] ??= new CombinedContainerInventory(
            $combinedIdentifier,
            $left,
            $right,
        );
        $this->combinedPositions[$combinedIdentifier] = [$lead->position, $other->position];

        return new ResolvedWorldContainer(
            $doubleType,
            $lead->position,
            $combined,
            $other->position,
            $lead->customName ?? $other->customName,
        );
    }

    public function persist(ResolvedWorldContainer $resolved): void
    {
        $inventory = $resolved->inventory;
        if ($inventory instanceof CombinedContainerInventory) {
            if ($resolved->pairedPosition === null) {
                throw new LogicException('Combined storage is missing its paired position.');
            }
            $left = $this->replacementAt($resolved->position, $inventory->left());
            $right = $this->replacementAt($resolved->pairedPosition, $inventory->right());
            $this->world->setBlockEntities($left, $right);
            $inventory->left()->acknowledgePersistedRevision($inventory->left()->revision());
            $inventory->right()->acknowledgePersistedRevision($inventory->right()->revision());

            return;
        }
        $replacement = $this->replacementAt($resolved->position, $inventory);
        $this->world->setBlockEntity($replacement);
        $inventory->acknowledgePersistedRevision($inventory->revision());
    }

    /**
     * Commits a complete staged inventory to durable world state and then publishes it through
     * the shared live inventory. No half of a paired chest is installed before every replacement
     * and the expected live revision have been validated.
     *
     * @param list<ItemStack|null> $contents
     * @throws ContainerRevisionMismatchException
     */
    public function replaceAndPersist(
        ResolvedWorldContainer $resolved,
        array $contents,
        string $expectedRevision,
    ): bool {
        $inventory = $resolved->inventory;
        if (!hash_equals($inventory->revision(), $expectedRevision)) {
            throw new ContainerRevisionMismatchException();
        }
        self::validateContents($contents, $inventory->size());
        if (self::sameContents($inventory->contents(), $contents)) {
            return false;
        }

        if ($inventory instanceof CombinedContainerInventory) {
            if ($resolved->pairedPosition === null) {
                throw new LogicException('Combined storage is missing its paired position.');
            }
            $leftSize = $inventory->left()->size();
            $leftContents = array_slice($contents, 0, $leftSize);
            $rightContents = array_slice($contents, $leftSize);
            $left = $this->replacementAtContents($resolved->position, $leftContents);
            $right = $this->replacementAtContents($resolved->pairedPosition, $rightContents);
            $this->world->setBlockEntities($left, $right);
        } else {
            $this->world->setBlockEntities($this->replacementAtContents($resolved->position, $contents));
        }

        if (!$inventory->replaceContents($contents, $expectedRevision)) {
            throw new LogicException('A validated storage mutation unexpectedly made no live change.');
        }
        $inventory->acknowledgePersistedRevision($inventory->revision());

        return true;
    }

    public function forget(BlockPosition $position): void
    {
        unset($this->inventories[self::positionKey($position)]);
        foreach ($this->combinedPositions as $identifier => [$left, $right]) {
            if (!$left->equals($position) && !$right->equals($position)) {
                continue;
            }
            unset($this->combinedInventories[$identifier], $this->combinedPositions[$identifier]);
        }
    }

    public function synchronizeBrewingStand(BrewingStandBlockEntity $entity): ?SimpleContainerInventory
    {
        $inventory = $this->inventories[self::positionKey($entity->position)] ?? null;
        if (!$inventory instanceof SimpleContainerInventory) {
            return null;
        }
        $contents = array_map(
            static fn(?ContainerItemStack $stack): ?ItemStack => $stack === null ? null : self::apiStack($stack),
            $entity->inventory->slots(),
        );
        $revision = $inventory->revision();
        $inventory->replaceContents($contents, $revision);
        $inventory->acknowledgePersistedRevision($inventory->revision());

        return $inventory;
    }

    private function inventory(ContainerBlockEntity|BrewingStandBlockEntity $entity): SimpleContainerInventory
    {
        $key = self::positionKey($entity->position);
        $cached = $this->inventories[$key] ?? null;
        if ($cached instanceof SimpleContainerInventory) {
            return $cached;
        }
        $contents = array_fill(0, $entity->inventory->size, null);
        foreach ($entity->inventory->contents() as $slot => $stack) {
            $contents[$slot] = self::apiStack($stack);
        }

        return $this->inventories[$key] = new SimpleContainerInventory(
            'world/' . $key,
            $entity->inventory->size,
            $contents,
        );
    }

    private function replacementAt(BlockPosition $position, ContainerInventory $inventory): BlockEntity
    {
        return $this->replacementAtContents($position, $inventory->contents());
    }

    /** @param list<ItemStack|null> $contents */
    private function replacementAtContents(BlockPosition $position, array $contents): BlockEntity
    {
        $entity = $this->world->blockEntityAt($position);
        if (!$entity instanceof ContainerBlockEntity && !$entity instanceof BrewingStandBlockEntity) {
            throw new LogicException('The storage block entity disappeared before its inventory committed.');
        }
        $persistentContents = [];
        foreach ($contents as $slot => $stack) {
            if ($stack !== null) {
                $persistentContents[$slot] = self::persistentStack($stack);
            }
        }
        $inventory = new PersistentContainerInventory(count($contents), $persistentContents);

        return $entity instanceof BrewingStandBlockEntity
            ? $entity->withState($inventory, $entity->brewTime, $entity->fuelAmount, $entity->fuelTotal)
            : $entity->withInventory($inventory);
    }

    /** @param array<mixed> $contents */
    private static function validateContents(array $contents, int $size): void
    {
        if (!array_is_list($contents) || count($contents) !== $size) {
            throw new \InvalidArgumentException('Container contents must preserve the complete typed layout.');
        }
        foreach ($contents as $stack) {
            if ($stack !== null && !$stack instanceof ItemStack) {
                throw new \InvalidArgumentException('Container contents may only contain item stacks or null.');
            }
        }
    }

    /**
     * @param list<ItemStack|null> $left
     * @param list<ItemStack|null> $right
     */
    private static function sameContents(array $left, array $right): bool
    {
        foreach ($left as $slot => $stack) {
            $other = $right[$slot];
            if (($stack === null) !== ($other === null)) {
                return false;
            }
            if ($stack !== null && $other !== null && (
                $stack->identifier !== $other->identifier
                || $stack->count !== $other->count
                || $stack->damage !== $other->damage
                || $stack->auxValue !== $other->auxValue
                || ($stack->nbt?->toBinary() ?? '') !== ($other->nbt?->toBinary() ?? '')
            )) {
                return false;
            }
        }

        return true;
    }

    private static function apiStack(ContainerItemStack $stack): ItemStack
    {
        return new ItemStack($stack->identifier, $stack->count, $stack->damage, $stack->nbt, $stack->auxValue);
    }

    private static function persistentStack(ItemStack $stack): ContainerItemStack
    {
        return new ContainerItemStack($stack->identifier, $stack->count, $stack->damage, $stack->nbt, $stack->auxValue);
    }

    private static function entityMatchesBlock(
        ContainerBlockEntity|BrewingStandBlockEntity $entity,
        string $identifier,
    ): bool {
        return match ($entity->type) {
            BlockEntityType::Chest => $identifier === 'minecraft:chest' || $identifier === 'minecraft:trapped_chest',
            BlockEntityType::Barrel => $identifier === 'minecraft:barrel',
            BlockEntityType::ShulkerBox => self::isShulkerBox($identifier),
            BlockEntityType::EnderChest => false,
            BlockEntityType::BrewingStand => $identifier === 'minecraft:brewing_stand',
        };
    }

    private static function apiType(string $identifier, bool $paired): ?ContainerType
    {
        return match (true) {
            $identifier === 'minecraft:chest' => $paired ? ContainerType::DOUBLE_CHEST : ContainerType::CHEST,
            $identifier === 'minecraft:trapped_chest' => $paired
                ? ContainerType::DOUBLE_TRAPPED_CHEST
                : ContainerType::TRAPPED_CHEST,
            $identifier === 'minecraft:barrel' => ContainerType::BARREL,
            self::isShulkerBox($identifier) => ContainerType::SHULKER_BOX,
            $identifier === 'minecraft:ender_chest' => ContainerType::ENDER_CHEST,
            $identifier === 'minecraft:brewing_stand' => ContainerType::BREWING_STAND,
            default => null,
        };
    }

    public static function isStorageBlock(string $identifier): bool
    {
        return self::apiType($identifier, false) !== null;
    }

    public static function blockEntityType(string $identifier): ?BlockEntityType
    {
        return match (true) {
            $identifier === 'minecraft:chest', $identifier === 'minecraft:trapped_chest' => BlockEntityType::Chest,
            $identifier === 'minecraft:barrel' => BlockEntityType::Barrel,
            self::isShulkerBox($identifier) => BlockEntityType::ShulkerBox,
            $identifier === 'minecraft:ender_chest' => BlockEntityType::EnderChest,
            $identifier === 'minecraft:brewing_stand' => BlockEntityType::BrewingStand,
            default => null,
        };
    }

    public static function containerType(string $identifier, bool $paired = false): ?ContainerType
    {
        return self::apiType($identifier, $paired);
    }

    private static function isShulkerBox(string $identifier): bool
    {
        return $identifier === 'minecraft:shulker_box' || str_ends_with($identifier, '_shulker_box');
    }

    public static function isShulkerBoxIdentifier(string $identifier): bool
    {
        return self::isShulkerBox($identifier);
    }

    private static function combinedIdentifier(BlockPosition $left, BlockPosition $right): string
    {
        return 'world/double/' . self::positionKey($left) . '/' . self::positionKey($right);
    }

    private static function positionKey(BlockPosition $position): string
    {
        return $position->x . ':' . $position->y . ':' . $position->z;
    }
}
