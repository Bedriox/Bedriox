<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

use Bedriox\Api\Inventory\Container;
use Bedriox\Api\Inventory\ContainerLayout;
use Bedriox\Api\Inventory\ContainerManager;
use Bedriox\Api\Inventory\ContainerView;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\BlockPosition as ApiBlockPosition;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\World\BlockPosition;
use Closure;

/** @internal Builds plugin-scoped container capabilities over the authoritative simulation. */
final readonly class SimulationPluginContainerService
{
    public function __construct(
        private string $plugin,
        private PluginRuntimeControl $plugins,
        private PluginActionBuffer $actions,
        private PluginOwnershipRegistry $ownership,
        private WorldSimulation $simulation,
        private ?ItemCatalog $itemCatalog = null,
    ) {}

    public function manager(): ContainerManager
    {
        return new ContainerManager(
            fn(ApiBlockPosition $position): ?Container => $this->worldContainer($position),
            fn(ContainerLayout $layout, ?string $title): Container => $this->create($layout, $title),
            $this->assertEnabled(...),
        );
    }

    private function worldContainer(ApiBlockPosition $position): ?Container
    {
        $this->assertEnabled();
        $internal = new BlockPosition($position->x, $position->y, $position->z);
        if ($this->simulation->pluginWorldContainerView($internal) === null) {
            return null;
        }

        return $this->handle(
            fn() => $this->simulation->pluginWorldContainerView($internal),
            fn(array $contents, string $revision): bool => $this->simulation->pluginReplaceWorldContainer(
                $internal,
                $contents,
                $revision,
            ),
            fn(string $player): bool => $this->simulation->pluginOpenWorldContainer($player, $internal),
            fn(string $player): bool => $this->simulation->pluginCloseWorldContainer($player, $internal),
        );
    }

    private function create(ContainerLayout $layout, ?string $title): Container
    {
        $this->assertEnabled();
        $identifier = $this->simulation->createPluginVirtualContainer($this->plugin, $layout, $title);
        $resource = 'container:' . $identifier;
        try {
            $this->ownership->own(
                $this->plugin,
                $resource,
                fn() => $this->simulation->removePluginVirtualContainer($this->plugin, $identifier),
            );
        } catch (\Throwable $throwable) {
            $this->simulation->removePluginVirtualContainer($this->plugin, $identifier);
            throw $throwable;
        }

        return $this->handle(
            fn(): ?ContainerView => $this->simulation->pluginVirtualContainerView($this->plugin, $identifier),
            fn(array $contents, string $revision): bool => $this->simulation->pluginReplaceVirtualContainer(
                $this->plugin,
                $identifier,
                $contents,
                $revision,
            ),
            fn(string $player): bool => $this->simulation->pluginOpenVirtualContainer(
                $this->plugin,
                $identifier,
                $player,
            ),
            fn(string $player): bool => $this->simulation->pluginCloseVirtualContainer(
                $this->plugin,
                $identifier,
                $player,
            ),
        );
    }

    /**
     * @param Closure(): ?ContainerView $view
     * @param Closure(list<ItemStack|null>, string): bool $replace
     * @param Closure(string): bool $open
     * @param Closure(string): bool $close
     */
    private function handle(Closure $view, Closure $replace, Closure $open, Closure $close): Container
    {
        $guard = $this->assertEnabled(...);
        /** @param list<ItemStack|null> $contents */
        $deferredReplace = function (array $contents, string $revision) use ($replace): bool {
            $snapshot = self::contentsSnapshot($contents);
            $this->defer(static function () use ($replace, $snapshot, $revision): void {
                $replace($snapshot, $revision);
            });

            return true;
        };

        return new Container(
            $view,
            function (int $slot, ?ItemStack $stack, string $revision) use ($view, $deferredReplace): bool {
                $current = $view();
                if ($current === null || $slot < 0 || $slot >= $current->inventory->size()
                    || !hash_equals($current->inventory->revision, $revision)) {
                    return false;
                }
                $contents = $current->inventory->slots;
                $contents[$slot] = $stack;

                return $deferredReplace($contents, $revision);
            },
            function (ItemStack $stack, string $revision) use ($view, $deferredReplace): ?ItemStack {
                $current = $view();
                if ($current === null || !hash_equals($current->inventory->revision, $revision)) {
                    return $stack;
                }
                $contents = $current->inventory->slots;
                $remaining = $stack->count;
                $maximum = $this->maximumStackSize($stack->identifier);
                foreach ($contents as $slot => $existing) {
                    if ($existing === null || !self::sameItem($existing, $stack) || $existing->count >= $maximum) {
                        continue;
                    }
                    $added = min($remaining, $maximum - $existing->count);
                    $contents[$slot] = new ItemStack(
                        $existing->identifier,
                        $existing->count + $added,
                        $existing->damage,
                        $existing->nbt,
                        $existing->auxValue,
                    );
                    $remaining -= $added;
                    if ($remaining === 0) {
                        break;
                    }
                }
                foreach ($contents as $slot => $existing) {
                    if ($remaining === 0) {
                        break;
                    }
                    if ($existing !== null) {
                        continue;
                    }
                    $added = min($remaining, $maximum);
                    $contents[$slot] = new ItemStack(
                        $stack->identifier,
                        $added,
                        $stack->damage,
                        $stack->nbt,
                        $stack->auxValue,
                    );
                    $remaining -= $added;
                }
                if ($remaining !== $stack->count) {
                    $deferredReplace($contents, $revision);
                }

                return $remaining === 0
                    ? null
                    : new ItemStack($stack->identifier, $remaining, $stack->damage, $stack->nbt, $stack->auxValue);
            },
            function (ItemStack $stack, string $revision) use ($view, $deferredReplace): int {
                $current = $view();
                if ($current === null || !hash_equals($current->inventory->revision, $revision)) {
                    return 0;
                }
                $contents = $current->inventory->slots;
                $remaining = $stack->count;
                foreach ($contents as $slot => $existing) {
                    if ($existing === null || !self::sameItem($existing, $stack)) {
                        continue;
                    }
                    $removed = min($remaining, $existing->count);
                    $next = $existing->count - $removed;
                    $contents[$slot] = $next === 0
                        ? null
                        : new ItemStack(
                            $existing->identifier,
                            $next,
                            $existing->damage,
                            $existing->nbt,
                            $existing->auxValue,
                        );
                    $remaining -= $removed;
                    if ($remaining === 0) {
                        break;
                    }
                }
                $removed = $stack->count - $remaining;
                if ($removed > 0) {
                    $deferredReplace($contents, $revision);
                }

                return $removed;
            },
            function (string $revision) use ($view, $deferredReplace): bool {
                $current = $view();
                if ($current === null || !hash_equals($current->inventory->revision, $revision)) {
                    return false;
                }

                return $deferredReplace(array_fill(0, $current->inventory->size(), null), $revision);
            },
            function (Player $player) use ($open): bool {
                $this->defer(static function () use ($open, $player): void {
                    $open($player->uuid);
                });

                return true;
            },
            function (Player $player) use ($close): bool {
                $this->defer(static function () use ($close, $player): void {
                    $close($player->uuid);
                });

                return true;
            },
            $guard,
        );
    }

    /**
     * @param array<mixed> $contents
     * @return list<ItemStack|null>
     */
    private static function contentsSnapshot(array $contents): array
    {
        $snapshot = [];
        foreach ($contents as $stack) {
            if ($stack !== null && !$stack instanceof ItemStack) {
                throw new \InvalidArgumentException('Container contents may only contain item stacks or null.');
            }
            $snapshot[] = $stack;
        }

        return $snapshot;
    }

    private function maximumStackSize(string $identifier): int
    {
        return $this->itemCatalog !== null && $this->itemCatalog->has($identifier)
            ? $this->itemCatalog->type($identifier)->maximumStackSize
            : 64;
    }

    private static function sameItem(ItemStack $left, ItemStack $right): bool
    {
        return $left->identifier === $right->identifier
            && $left->damage === $right->damage
            && $left->auxValue === $right->auxValue
            && ($left->nbt?->toBinary() ?? '') === ($right->nbt?->toBinary() ?? '');
    }

    private function defer(Closure $action): void
    {
        $this->assertEnabled();
        $guarded = function () use ($action): void {
            $this->assertEnabled();
            $action();
        };
        if ($this->actions->isCapturing()) {
            $this->actions->stage($guarded);
        } else {
            $guarded();
        }
    }

    private function assertEnabled(): void
    {
        if (!$this->plugins->isEnabled($this->plugin)) {
            throw new PluginException("Disabled plugin {$this->plugin} cannot use the container API.");
        }
    }
}
