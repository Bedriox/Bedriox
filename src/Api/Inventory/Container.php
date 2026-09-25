<?php

declare(strict_types=1);

namespace Bedriox\Api\Inventory;

use Bedriox\Api\Player\Player;
use Bedriox\Api\World\BlockPosition;
use Closure;

/**
 * Live protocol-neutral handle for one authoritative container.
 *
 * Every mutation is bounded intent which the server revalidates on the simulation thread. The
 * handle never exposes a window ID, stack-network ID, packet, registry, or mutable server object.
 */
final readonly class Container
{
    private Closure $guard;

    /**
     * @param Closure(): ?ContainerView $view
     * @param Closure(int, ?ItemStack, string): bool $setItem
     * @param Closure(ItemStack, string): ?ItemStack $addItem
     * @param Closure(ItemStack, string): int $removeItem
     * @param Closure(string): bool $clear
     * @param Closure(Player): bool $open
     * @param Closure(Player): bool $close
     * @internal The server owns live container construction.
     */
    public function __construct(
        private Closure $view,
        private Closure $setItem,
        private Closure $addItem,
        private Closure $removeItem,
        private Closure $clear,
        private Closure $open,
        private Closure $close,
        ?Closure $guard = null,
    ) {
        $this->guard = $guard ?? static function (): void {};
    }

    public function isAvailable(): bool
    {
        ($this->guard)();

        return ($this->view)() !== null;
    }

    public function view(): ?ContainerView
    {
        ($this->guard)();

        return ($this->view)();
    }

    public function type(): ?ContainerType
    {
        return $this->view()?->type;
    }

    public function position(): ?BlockPosition
    {
        return $this->view()?->position;
    }

    /** @return list<ItemStack|null> */
    public function contents(): array
    {
        $view = $this->view();

        return $view === null ? [] : $view->inventory->slots;
    }

    /** @return list<string> */
    public function viewerUuids(): array
    {
        $view = $this->view();

        return $view === null ? [] : $view->viewerUuids;
    }

    public function setItem(int $slot, ?ItemStack $stack): bool
    {
        $view = $this->view();
        if ($view === null || $slot < 0 || $slot >= $view->inventory->size()) {
            return false;
        }

        return ($this->setItem)($slot, $stack, $view->inventory->revision);
    }

    /** Adds as much as possible and returns the unaccepted remainder, if any. */
    public function addItem(ItemStack $stack): ?ItemStack
    {
        $view = $this->view();

        return $view === null ? $stack : ($this->addItem)($stack, $view->inventory->revision);
    }

    /** Removes up to the requested count and returns the number actually removed. */
    public function removeItem(ItemStack $stack): int
    {
        $view = $this->view();

        return $view === null ? 0 : ($this->removeItem)($stack, $view->inventory->revision);
    }

    public function clear(): bool
    {
        $view = $this->view();

        return $view !== null && ($this->clear)($view->inventory->revision);
    }

    public function open(Player $player): bool
    {
        ($this->guard)();

        return ($this->view)() !== null && ($this->open)($player);
    }

    public function close(Player $player): bool
    {
        ($this->guard)();

        return ($this->close)($player);
    }
}
