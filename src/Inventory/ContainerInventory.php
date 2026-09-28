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

use Bedriox\Api\Inventory\ItemStack;

/** Mutable authoritative storage behind a container; never exposed directly to plugins. */
interface ContainerInventory
{
    public function identifier(): string;

    public function size(): int;

    /** Opaque token which changes after every successful content mutation. */
    public function revision(): string;

    public function stackAt(int $slot): ?ItemStack;

    /** @return list<ItemStack|null> */
    public function contents(): array;

    /** @throws ContainerRevisionMismatchException */
    public function setStack(int $slot, ?ItemStack $stack, ?string $expectedRevision = null): bool;

    /**
     * @param list<ItemStack|null> $contents
     * @throws ContainerRevisionMismatchException
     */
    public function replaceContents(array $contents, ?string $expectedRevision = null): bool;

    public function isDirty(): bool;

    /** Clears dirty state only when the exact persisted revision is still current. */
    public function acknowledgePersistedRevision(string $revision): bool;

    public function addViewer(string $playerUuid): void;

    public function removeViewer(string $playerUuid): void;

    /** @return list<string> */
    public function viewerUuids(): array;
}
