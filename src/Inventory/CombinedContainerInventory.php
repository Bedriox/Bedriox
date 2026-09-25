<?php

declare(strict_types=1);

namespace Bedriox\Server\Inventory;

use Bedriox\Api\Inventory\ItemStack;
use InvalidArgumentException;

/** Deterministic left-then-right inventory projection used by paired containers. */
final class CombinedContainerInventory extends AbstractContainerInventory
{
    public function __construct(
        string $identifier,
        private readonly ContainerInventory $left,
        private readonly ContainerInventory $right,
    ) {
        if ($left === $right) {
            throw new InvalidArgumentException('Combined inventory sides must be distinct.');
        }
        if ($left->size() + $right->size() > self::MAX_SLOTS) {
            throw new InvalidArgumentException('Combined inventory exceeds the supported slot limit.');
        }
        parent::__construct($identifier);
    }

    public function size(): int
    {
        return $this->left->size() + $this->right->size();
    }

    public function revision(): string
    {
        return hash('sha256', $this->left->identifier() . "\0" . $this->left->revision()
            . "\0" . $this->right->identifier() . "\0" . $this->right->revision());
    }

    public function stackAt(int $slot): ?ItemStack
    {
        self::validateSlot($slot, $this->size());

        return $slot < $this->left->size()
            ? $this->left->stackAt($slot)
            : $this->right->stackAt($slot - $this->left->size());
    }

    public function contents(): array
    {
        return [...$this->left->contents(), ...$this->right->contents()];
    }

    public function setStack(int $slot, ?ItemStack $stack, ?string $expectedRevision = null): bool
    {
        self::validateSlot($slot, $this->size());
        $this->assertRevision($expectedRevision);

        return $slot < $this->left->size()
            ? $this->left->setStack($slot, $stack)
            : $this->right->setStack($slot - $this->left->size(), $stack);
    }

    public function replaceContents(array $contents, ?string $expectedRevision = null): bool
    {
        $this->assertRevision($expectedRevision);
        if (count($contents) !== $this->size()) {
            throw new InvalidArgumentException('Combined contents must contain exactly one entry per slot.');
        }
        $leftContents = array_slice($contents, 0, $this->left->size());
        $rightContents = array_slice($contents, $this->left->size());
        $leftChanged = $this->left->replaceContents($leftContents);
        $rightChanged = $this->right->replaceContents($rightContents);

        return $leftChanged || $rightChanged;
    }

    public function isDirty(): bool
    {
        return $this->left->isDirty() || $this->right->isDirty();
    }

    public function acknowledgePersistedRevision(string $revision): bool
    {
        if (!hash_equals($this->revision(), $revision)) {
            return false;
        }
        $leftRevision = $this->left->revision();
        $rightRevision = $this->right->revision();

        return $this->left->acknowledgePersistedRevision($leftRevision)
            && $this->right->acknowledgePersistedRevision($rightRevision);
    }

    public function left(): ContainerInventory
    {
        return $this->left;
    }

    public function right(): ContainerInventory
    {
        return $this->right;
    }
}
