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

namespace Bedriox\Api\Inventory;

use InvalidArgumentException;

/** Complete immutable before/after projection of one authoritative atomic transaction. */
final readonly class InventoryTransaction
{
    /** @var list<InventoryView> */
    public array $before;

    /** @var list<InventoryView> */
    public array $after;

    /** @var list<InventoryTransactionAction> */
    public array $actions;

    /**
     * @param non-empty-string $identifier
     * @param array<mixed> $before
     * @param array<mixed> $after
     * @param array<mixed> $actions
     */
    public function __construct(
        public string $identifier,
        public InventoryTransactionCause $cause,
        array $before,
        array $after,
        array $actions,
    ) {
        if (strlen($identifier) > 128 || preg_match('/^[A-Za-z0-9_.:-]+$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Transaction identifier must be non-empty, bounded, and portable.');
        }
        if ($before === [] || $after === [] || $actions === []
            || count($before) > 16 || count($after) > 16 || count($actions) > 512
            || !array_is_list($before) || !array_is_list($after) || !array_is_list($actions)) {
            throw new InvalidArgumentException('Transaction inventories and actions must be non-empty bounded lists.');
        }
        $beforeById = self::indexViews($before);
        $afterById = self::indexViews($after);
        if (array_keys($beforeById) !== array_keys($afterById)) {
            throw new InvalidArgumentException('Transaction before and after inventory sets must match.');
        }
        foreach ($beforeById as $inventoryIdentifier => $view) {
            if ($view->size() !== $afterById[$inventoryIdentifier]->size()) {
                throw new InvalidArgumentException('Transaction may not resize an inventory.');
            }
        }
        $normalizedActions = [];
        foreach ($actions as $action) {
            if (!$action instanceof InventoryTransactionAction || !isset($beforeById[$action->inventoryIdentifier])) {
                throw new InvalidArgumentException('Transaction actions must target an involved inventory.');
            }
            if ($action->slot >= $beforeById[$action->inventoryIdentifier]->size()) {
                throw new InvalidArgumentException('Transaction action slot is outside its inventory.');
            }
            $normalizedActions[] = $action;
        }
        $this->before = array_values($beforeById);
        $this->after = array_values($afterById);
        $this->actions = $normalizedActions;
    }

    /**
     * @param array<mixed> $views
     * @return array<string, InventoryView>
     */
    private static function indexViews(array $views): array
    {
        $indexed = [];
        foreach ($views as $view) {
            if (!$view instanceof InventoryView || isset($indexed[$view->identifier])) {
                throw new InvalidArgumentException('Transaction inventories must be uniquely identified views.');
            }
            $indexed[$view->identifier] = $view;
        }
        ksort($indexed, SORT_STRING);

        return $indexed;
    }
}
