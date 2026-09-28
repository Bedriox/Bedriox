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

use InvalidArgumentException;
use OverflowException;

abstract class AbstractContainerInventory implements ContainerInventory
{
    public const int MAX_SLOTS = 256;
    public const int MAX_VIEWERS = 128;

    /** @var array<string, string> lowercase UUID => original UUID */
    private array $viewerUuids = [];

    protected int $localRevision = 0;

    protected bool $dirty = false;

    public function __construct(private readonly string $inventoryIdentifier)
    {
        if (strlen($inventoryIdentifier) > 256
            || preg_match('/^[A-Za-z0-9_.:\/-]+$/D', $inventoryIdentifier) !== 1) {
            throw new InvalidArgumentException('Container inventory identifier must be non-empty, bounded, and portable.');
        }
    }

    final public function identifier(): string
    {
        return $this->inventoryIdentifier;
    }

    public function revision(): string
    {
        return (string) $this->localRevision;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    public function acknowledgePersistedRevision(string $revision): bool
    {
        if (!hash_equals($this->revision(), $revision)) {
            return false;
        }
        $this->dirty = false;

        return true;
    }

    final public function addViewer(string $playerUuid): void
    {
        self::validateUuid($playerUuid);
        $key = strtolower($playerUuid);
        if (isset($this->viewerUuids[$key])) {
            return;
        }
        if (count($this->viewerUuids) >= self::MAX_VIEWERS) {
            throw new OverflowException('Container inventory viewer limit reached.');
        }
        $this->viewerUuids[$key] = $playerUuid;
        ksort($this->viewerUuids, SORT_STRING);
    }

    final public function removeViewer(string $playerUuid): void
    {
        self::validateUuid($playerUuid);
        unset($this->viewerUuids[strtolower($playerUuid)]);
    }

    final public function viewerUuids(): array
    {
        return array_values($this->viewerUuids);
    }

    final protected function assertRevision(?string $expectedRevision): void
    {
        if ($expectedRevision !== null && !hash_equals($this->revision(), $expectedRevision)) {
            throw new ContainerRevisionMismatchException();
        }
    }

    final protected function recordMutation(): void
    {
        if ($this->localRevision === PHP_INT_MAX) {
            throw new OverflowException('Container inventory revision is exhausted.');
        }
        ++$this->localRevision;
        $this->dirty = true;
    }

    final protected static function validateSlot(int $slot, int $size): void
    {
        if ($slot < 0 || $slot >= $size) {
            throw new InvalidArgumentException('Container inventory slot is outside the inventory.');
        }
    }

    private static function validateUuid(string $uuid): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $uuid) !== 1) {
            throw new InvalidArgumentException('Container inventory viewer must be identified by UUID.');
        }
    }
}
