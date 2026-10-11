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

namespace Bedriox\Server\Visibility;

use Bedriox\Api\Player\Player;
use Bedriox\Api\Visibility\Visibility;
use Bedriox\Api\Visibility\VisibilityMode;
use Bedriox\Api\Visibility\VisibilityPolicy;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Closure;
use LogicException;
use OverflowException;

/** @internal Shared authoritative UUID-only visibility state. */
final class MutableVisibility implements Visibility
{
    private VisibilityMode $mode;

    /** @var array<string, true> */
    private array $selectedPlayerUuids;

    /** @var array<string, true> */
    private array $excludedPlayerUuids;

    private ?PluginActionBuffer $actions = null;

    /**
     * @param Closure(): void $changed
     * @param Closure(): bool $available
     */
    public function __construct(
        VisibilityPolicy $policy,
        private readonly Closure $changed,
        private readonly Closure $available,
    ) {
        $this->mode = $policy->mode();
        $this->selectedPlayerUuids = array_fill_keys($policy->selectedPlayerUuids(), true);
        $this->excludedPlayerUuids = array_fill_keys($policy->excludedPlayerUuids(), true);
    }

    public function bindActions(?PluginActionBuffer $actions): void
    {
        $this->actions = $actions;
    }

    public function initialize(VisibilityPolicy $policy): void
    {
        $this->assertAvailable();
        $this->apply(
            $policy->mode(),
            $policy->selectedPlayerUuids(),
            $policy->excludedPlayerUuids(),
            false,
        );
    }

    /** Clears identity state when the owning presentation leaves its lifecycle. */
    public function clear(): void
    {
        $this->mode = VisibilityMode::HIDDEN;
        $this->selectedPlayerUuids = [];
        $this->excludedPlayerUuids = [];
        $this->actions = null;
    }

    public function getMode(): VisibilityMode
    {
        return $this->mode;
    }

    public function setMode(VisibilityMode $mode): void
    {
        $this->mutate(function () use ($mode): void {
            $this->apply(
                $mode,
                $mode === VisibilityMode::SELECTED ? array_keys($this->selectedPlayerUuids) : [],
                $mode === VisibilityMode::EXCLUDED ? array_keys($this->excludedPlayerUuids) : [],
            );
        });
    }

    public function showTo(Player $player): void
    {
        $uuid = VisibilityPolicy::normalizeUuid($player->uuid);
        $this->mutate(function () use ($uuid): void {
            switch ($this->mode) {
                case VisibilityMode::AUTOMATIC:
                    return;
                case VisibilityMode::SELECTED:
                    $this->addSelected($uuid);

                    return;
                case VisibilityMode::EXCLUDED:
                    $this->removeExcluded($uuid);

                    return;
                case VisibilityMode::HIDDEN:
                    $this->apply(VisibilityMode::SELECTED, [$uuid], []);
            }
        });
    }

    public function hideFrom(Player $player): void
    {
        $uuid = VisibilityPolicy::normalizeUuid($player->uuid);
        $this->mutate(function () use ($uuid): void {
            switch ($this->mode) {
                case VisibilityMode::AUTOMATIC:
                    $this->apply(VisibilityMode::EXCLUDED, [], [$uuid]);

                    return;
                case VisibilityMode::SELECTED:
                    $this->removeSelected($uuid);

                    return;
                case VisibilityMode::EXCLUDED:
                    $this->addExcluded($uuid);

                    return;
                case VisibilityMode::HIDDEN:
                    return;
            }
        });
    }

    public function showOnlyTo(Player $player): void
    {
        $uuid = VisibilityPolicy::normalizeUuid($player->uuid);
        $this->mutate(fn() => $this->apply(VisibilityMode::SELECTED, [$uuid], []));
    }

    public function showToAll(): void
    {
        $this->mutate(fn() => $this->apply(VisibilityMode::AUTOMATIC, [], []));
    }

    public function hideFromAll(): void
    {
        $this->mutate(fn() => $this->apply(VisibilityMode::HIDDEN, [], []));
    }

    public function isVisibleTo(Player $player): bool
    {
        return $this->allowsUuid($player->uuid);
    }

    public function getSelectedPlayerUuids(): array
    {
        return array_keys($this->selectedPlayerUuids);
    }

    public function getExcludedPlayerUuids(): array
    {
        return array_keys($this->excludedPlayerUuids);
    }

    public function allowsUuid(string $uuid): bool
    {
        if (!(($this->available)())) {
            return false;
        }
        $uuid = strtolower($uuid);

        return match ($this->mode) {
            VisibilityMode::AUTOMATIC => true,
            VisibilityMode::SELECTED => isset($this->selectedPlayerUuids[$uuid]),
            VisibilityMode::EXCLUDED => !isset($this->excludedPlayerUuids[$uuid]),
            VisibilityMode::HIDDEN => false,
        };
    }

    private function addSelected(string $uuid): void
    {
        if (isset($this->selectedPlayerUuids[$uuid])) {
            return;
        }
        $this->assertCapacity($this->selectedPlayerUuids);
        $selected = $this->selectedPlayerUuids;
        $selected[$uuid] = true;
        $this->apply(VisibilityMode::SELECTED, array_keys($selected), []);
    }

    private function removeSelected(string $uuid): void
    {
        if (!isset($this->selectedPlayerUuids[$uuid])) {
            return;
        }
        $selected = $this->selectedPlayerUuids;
        unset($selected[$uuid]);
        $this->apply(VisibilityMode::SELECTED, array_keys($selected), []);
    }

    private function addExcluded(string $uuid): void
    {
        if (isset($this->excludedPlayerUuids[$uuid])) {
            return;
        }
        $this->assertCapacity($this->excludedPlayerUuids);
        $excluded = $this->excludedPlayerUuids;
        $excluded[$uuid] = true;
        $this->apply(VisibilityMode::EXCLUDED, [], array_keys($excluded));
    }

    private function removeExcluded(string $uuid): void
    {
        if (!isset($this->excludedPlayerUuids[$uuid])) {
            return;
        }
        $excluded = $this->excludedPlayerUuids;
        unset($excluded[$uuid]);
        $this->apply(VisibilityMode::EXCLUDED, [], array_keys($excluded));
    }

    /**
     * @param list<string> $selected
     * @param list<string> $excluded
     */
    private function apply(
        VisibilityMode $mode,
        array $selected,
        array $excluded,
        bool $notify = true,
    ): void {
        $selectedSet = array_fill_keys($selected, true);
        $excludedSet = array_fill_keys($excluded, true);
        if ($this->mode === $mode
            && $this->selectedPlayerUuids === $selectedSet
            && $this->excludedPlayerUuids === $excludedSet) {
            return;
        }
        $this->mode = $mode;
        $this->selectedPlayerUuids = $selectedSet;
        $this->excludedPlayerUuids = $excludedSet;
        if ($notify) {
            ($this->changed)();
        }
    }

    /** @param array<string, true> $uuids */
    private function assertCapacity(array $uuids): void
    {
        if (count($uuids) >= VisibilityPolicy::MAXIMUM_PLAYER_UUIDS) {
            throw new OverflowException('Visibility player selection exceeds its supported capacity.');
        }
    }

    private function mutate(Closure $mutation): void
    {
        $this->assertAvailable();
        $guarded = function () use ($mutation): void {
            $this->assertAvailable();
            $mutation();
        };
        if ($this->actions?->isCapturing() === true) {
            $this->actions->stage($guarded);

            return;
        }
        $guarded();
    }

    private function assertAvailable(): void
    {
        if (!(($this->available)())) {
            throw new LogicException('Visibility is no longer available.');
        }
    }
}
