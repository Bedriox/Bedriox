<?php

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
