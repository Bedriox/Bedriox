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

namespace Bedriox\Server\Plugin\Scoreboard;

use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\Scoreboard\PlayerScoreboardDisplayEvent;
use Bedriox\Api\Event\Scoreboard\ScoreboardScoreChangeEvent;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Scoreboard\ScoreboardDisplaySlot;
use Bedriox\Api\Scoreboard\ScoreboardManager;
use Bedriox\Api\Scoreboard\ScoreboardObjective;
use Bedriox\Api\Scoreboard\ScoreboardSortOrder;
use Bedriox\Api\Scoreboard\Sidebar;
use Bedriox\Api\Scoreboard\SidebarView;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Closure;
use InvalidArgumentException;
use LogicException;

/** @internal Owns bounded plugin scoreboard state; packet projection is handled by the runtime. */
final class ScoreboardRegistry
{
    public const int MAXIMUM_SIDEBARS_PER_PLUGIN = 64;
    public const int MAXIMUM_OBJECTIVES_PER_PLUGIN = 128;
    public const int MAXIMUM_SCORES_PER_OBJECTIVE = 16_384;
    public const int MAXIMUM_VIEWS_PER_SIDEBAR = 1_024;

    /** @var array<string, array<string, SidebarState>> */
    private array $sidebars = [];
    /** @var array<string, array<string, ObjectiveState>> */
    private array $objectives = [];
    /** @var array<string, true> */
    private array $ownedCleanup = [];
    /** @var array<string, int> */
    private array $nextSidebarId = [];
    /** @var array<string, ScoreboardDirtyEntry> */
    private array $dirty = [];
    /** @var array<string, true> */
    private array $activeEventMutations = [];

    /**
     * @param Closure(): list<Player> $onlinePlayers
     * @param (Closure(Event): Event)|null $dispatchEvent
     */
    public function __construct(
        private readonly PluginOwnershipRegistry $ownership,
        private readonly Closure $onlinePlayers,
        private readonly ?Closure $dispatchEvent = null,
    ) {}

    public function forOwner(string $owner): ScoreboardManager
    {
        return new ScoreboardManager(
            fn(string $title, ?string $id): Sidebar => $this->createSidebar($owner, $title, $id),
            fn(string $id): ?Sidebar => $this->sidebars[self::ownerKey($owner)][$id]->handle ?? null,
            fn(): array => array_values(array_map(
                static fn(SidebarState $state): Sidebar => $state->handle
                    ?? throw new LogicException('Sidebar handle was not initialized.'),
                $this->sidebars[self::ownerKey($owner)] ?? [],
            )),
            fn(string $id): bool => $this->removeSidebar($owner, $id),
            fn(string $id, string $displayName): ScoreboardObjective => $this->createObjective($owner, $id, $displayName),
            fn(string $id): ?ScoreboardObjective => $this->objectives[self::ownerKey($owner)][$id]->handle ?? null,
            fn(): array => array_values(array_map(
                static fn(ObjectiveState $state): ScoreboardObjective => $state->handle
                    ?? throw new LogicException('Scoreboard objective handle was not initialized.'),
                $this->objectives[self::ownerKey($owner)] ?? [],
            )),
            fn(string $id): bool => $this->removeObjective($owner, $id),
        );
    }

    /** @return list<ScoreboardDirtyEntry> */
    public function drainDirty(int $maximum = PHP_INT_MAX): array
    {
        if ($maximum < 1) {
            throw new InvalidArgumentException('The scoreboard dirty-entry budget must be positive.');
        }
        if (count($this->dirty) <= $maximum) {
            $dirty = array_values($this->dirty);
            $this->dirty = [];

            return $dirty;
        }
        $keys = array_slice(array_keys($this->dirty), 0, $maximum);
        $dirty = [];
        foreach ($keys as $key) {
            $dirty[] = $this->dirty[$key];
            unset($this->dirty[$key]);
        }

        return $dirty;
    }

    /** Removes transient viewer state for a disconnected player without scanning packet/session objects. */
    public function removePlayer(string $uuid): void
    {
        $key = strtolower($uuid);
        foreach ($this->sidebars as $states) {
            foreach ($states as $state) {
                $view = $state->views[$key] ?? null;
                if (!$view instanceof SidebarViewState) {
                    continue;
                }
                $wasShowing = $view->showing;
                $view->removed = true;
                $view->showing = false;
                ++$view->revision;
                if ($wasShowing) {
                    $this->markViewDirty($state, $view);
                }
                unset($state->views[$key]);
            }
        }
        foreach ($this->objectives as $states) {
            foreach ($states as $state) {
                if (isset($state->displays[$key])) {
                    unset($state->displays[$key]);
                    $this->touchObjective($state);
                }
            }
        }
    }

    private function createSidebar(string $owner, string $title, ?string $id): Sidebar
    {
        $ownerKey = self::ownerKey($owner);
        $id ??= $this->allocateSidebarId($ownerKey);
        if (isset($this->sidebars[$ownerKey][$id])) {
            throw new PluginException("Sidebar already exists: {$id}");
        }
        if (count($this->sidebars[$ownerKey] ?? []) >= self::MAXIMUM_SIDEBARS_PER_PLUGIN) {
            throw new PluginException('Sidebar capacity has been reached.');
        }
        $this->ensureOwnerCleanup($owner, $ownerKey);
        $state = new SidebarState($owner, $id, $title);
        $state->handle = new Sidebar(
            $id,
            fn(): array => $this->sidebarSnapshot($state),
            function (string $operation, mixed $value) use ($state): void {
                $this->mutateSidebar($state, $operation, $value);
            },
            fn(Player $player): SidebarView => $this->getViewFor($state, $player),
            function (Player $player) use ($state): void {
                $this->requirePlayerOnline($player);
                $this->mutateView($state, $this->viewState($state, $player), 'showing', true);
            },
            function (Player $player) use ($state): void {
                $uuid = strtolower($player->uuid);
                if (isset($state->views[$uuid])) {
                    $state->views[$uuid]->player = $player;
                    $this->mutateView($state, $state->views[$uuid], 'showing', false);
                }
            },
            fn(Player $player): bool => $state->views[strtolower($player->uuid)]->showing ?? false,
            function () use ($state): void {
                foreach (($this->onlinePlayers)() as $player) {
                    $this->mutateView($state, $this->viewState($state, $player), 'showing', true);
                }
            },
            function () use ($state): void {
                foreach ($state->views as $view) {
                    $this->mutateView($state, $view, 'showing', false);
                }
            },
            function () use ($owner, $id): void {
                $this->removeSidebar($owner, $id);
            },
        );
        $this->sidebars[$ownerKey][$id] = $state;
        $this->markSidebarDirty($state);
        if (!$state->handle instanceof Sidebar) {
            throw new LogicException('Sidebar handle was not initialized.');
        }

        return $state->handle;
    }

    /** @return array{title: string, lines: array<string, string>, viewers: list<Player>, removed: bool} */
    private function sidebarSnapshot(SidebarState $state): array
    {
        return [
            'title' => $state->title,
            'lines' => $state->lines,
            'viewers' => array_values(array_map(
                static fn(SidebarViewState $view): Player => $view->player,
                array_filter($state->views, static fn(SidebarViewState $view): bool => $view->showing),
            )),
            'removed' => $state->removed,
        ];
    }

    private function mutateSidebar(SidebarState $state, string $operation, mixed $value): void
    {
        $this->requireSidebarActive($state);
        $lines = $state->lines;
        if ($operation === 'title') {
            if (!is_string($value)) {
                throw new LogicException('Sidebar title mutation must contain a string.');
            }
            if ($state->title === $value) {
                return;
            }
            $state->title = $value;
        } elseif ($operation === 'replace-lines') {
            if (!is_array($value)) {
                throw new LogicException('Sidebar line replacement must contain an array.');
            }
            /** @var array<string, string> $replacement */
            $replacement = $value;
            if ($lines === $replacement) {
                return;
            }
            $this->assertViewsFit($state, $replacement);
            $state->lines = $replacement;
        } elseif ($operation === 'remove-line') {
            if (!is_string($value) || !array_key_exists($value, $lines)) {
                return;
            }
            unset($lines[$value]);
            $state->lines = $lines;
        } else {
            $changed = $this->mutateOrderedLines($lines, $operation, $value);
            if (!$changed) {
                return;
            }
            $this->assertViewsFit($state, $lines);
            $state->lines = $lines;
        }
        ++$state->revision;
        $this->markSidebarDirty($state);
        foreach ($state->views as $view) {
            if ($view->showing) {
                $this->markViewDirty($state, $view);
            }
        }
    }

    /** @param array<string, string> $lines */
    private function mutateOrderedLines(array &$lines, string $operation, mixed $value): bool
    {
        if (!is_array($value)) {
            throw new LogicException('Sidebar line mutation must contain an array.');
        }
        if ($operation === 'set-line') {
            [$key, $text, $position] = $value;
            if (!is_string($key) || !is_string($text) || ($position !== null && !is_int($position))) {
                throw new LogicException('Invalid sidebar line mutation.');
            }
            $existingPosition = array_search($key, array_keys($lines), true);
            if ($existingPosition !== false && $position === null && $lines[$key] === $text) {
                return false;
            }
            unset($lines[$key]);
            $position ??= $existingPosition === false ? count($lines) + 1 : $existingPosition + 1;
            $this->insertLineAt($lines, $key, $text, $position);
        } elseif ($operation === 'move-line') {
            [$key, $position] = $value;
            if (!is_string($key) || !is_int($position) || !isset($lines[$key])) {
                return false;
            }
            $text = $lines[$key];
            $old = array_search($key, array_keys($lines), true);
            unset($lines[$key]);
            $this->insertLineAt($lines, $key, $text, $position);
            if ($old === array_search($key, array_keys($lines), true)) {
                return false;
            }
        } elseif ($operation === 'insert-before' || $operation === 'insert-after') {
            [$existingKey, $key, $text] = $value;
            if (!is_string($existingKey) || !is_string($key) || !is_string($text)
                || !array_key_exists($existingKey, $lines)) {
                throw new InvalidArgumentException('The referenced sidebar line does not exist.');
            }
            $position = array_search($existingKey, array_keys($lines), true);
            if (!is_int($position)) {
                throw new LogicException('Sidebar line order is inconsistent.');
            }
            unset($lines[$key]);
            $this->insertLineAt($lines, $key, $text, $position + ($operation === 'insert-after' ? 2 : 1));
        } else {
            throw new LogicException("Unsupported sidebar mutation: {$operation}");
        }
        if (count($lines) > Sidebar::MAXIMUM_LINES) {
            throw new InvalidArgumentException('A sidebar cannot contain more than 15 lines.');
        }

        return true;
    }

    /** @param array<string, string> $lines */
    private function insertLineAt(array &$lines, string $key, string $text, int $position): void
    {
        $offset = min(count($lines), max(0, $position - 1));
        $lines = array_slice($lines, 0, $offset, true)
            + [$key => $text]
            + array_slice($lines, $offset, null, true);
    }

    private function getViewFor(SidebarState $state, Player $player): SidebarView
    {
        $this->requireSidebarActive($state);
        $this->requirePlayerOnline($player);
        $view = $this->viewState($state, $player);

        return $view->handle ?? throw new LogicException('Sidebar view handle was not initialized.');
    }

    private function viewState(SidebarState $state, Player $player): SidebarViewState
    {
        $uuid = strtolower($player->uuid);
        if (isset($state->views[$uuid])) {
            $state->views[$uuid]->player = $player;

            return $state->views[$uuid];
        }
        if (count($state->views) >= self::MAXIMUM_VIEWS_PER_SIDEBAR) {
            throw new PluginException('Sidebar viewer capacity has been reached.');
        }
        $view = new SidebarViewState($player);
        $view->handle = new SidebarView(
            $state->handle ?? throw new LogicException('Sidebar handle was not initialized.'),
            fn(): Player => $view->player,
            fn(): array => $this->viewSnapshot($state, $view),
            function (string $operation, mixed $value) use ($state, $view): void {
                $this->mutateView($state, $view, $operation, $value);
            },
        );
        $state->views[$uuid] = $view;

        return $view;
    }

    /** @return array{title: string, lines: array<string, string>, showing: bool, removed: bool} */
    private function viewSnapshot(SidebarState $state, SidebarViewState $view): array
    {
        return [
            'title' => $view->titleOverride ?? $state->title,
            'lines' => $this->effectiveLines($state->lines, $view->lineOverrides),
            'showing' => $view->showing,
            'removed' => $view->removed || $state->removed,
        ];
    }

    private function mutateView(SidebarState $state, SidebarViewState $view, string $operation, mixed $value): void
    {
        $this->requireSidebarActive($state);
        if ($view->removed) {
            throw new LogicException('This sidebar view has been removed.');
        }
        $changed = false;
        if ($operation === 'title') {
            if (!is_string($value)) {
                throw new LogicException('Sidebar view title mutation must contain a string.');
            }
            $changed = $view->titleOverride !== $value;
            $view->titleOverride = $value;
        } elseif ($operation === 'reset-title') {
            $changed = $view->titleOverride !== null;
            $view->titleOverride = null;
        } elseif ($operation === 'set-line') {
            if (!is_array($value) || !isset($value[0], $value[1]) || !is_string($value[0]) || !is_string($value[1])) {
                throw new LogicException('Invalid sidebar view line mutation.');
            }
            [$key, $text] = $value;
            $changed = !array_key_exists($key, $view->lineOverrides) || $view->lineOverrides[$key] !== $text;
            $overrides = $view->lineOverrides;
            $overrides[$key] = $text;
            $this->assertEffectiveLineLimit($state->lines, $overrides);
            $view->lineOverrides = $overrides;
        } elseif ($operation === 'remove-line') {
            if (!is_string($value)) {
                throw new LogicException('Invalid sidebar view line key.');
            }
            $changed = !array_key_exists($value, $view->lineOverrides) || $view->lineOverrides[$value] !== null;
            $view->lineOverrides[$value] = null;
        } elseif ($operation === 'reset-line') {
            if (!is_string($value)) {
                throw new LogicException('Invalid sidebar view line key.');
            }
            $changed = array_key_exists($value, $view->lineOverrides);
            unset($view->lineOverrides[$value]);
        } elseif ($operation === 'reset-overrides') {
            $changed = $view->titleOverride !== null || $view->lineOverrides !== [];
            $view->titleOverride = null;
            $view->lineOverrides = [];
        } elseif ($operation === 'showing') {
            if (!is_bool($value)) {
                throw new LogicException('Sidebar visibility mutation must contain a boolean.');
            }
            $changed = $view->showing !== $value;
            if ($changed && !$this->dispatchDisplayTransition(
                $view->player,
                ScoreboardDisplaySlot::SIDEBAR,
                $value ? $state->handle : null,
                ScoreboardSortOrder::DESCENDING,
            )) {
                return;
            }
            if ($value) {
                $this->hideOtherDisplays($state, $view->player, ScoreboardDisplaySlot::SIDEBAR);
            }
            $view->showing = $value;
        } else {
            throw new LogicException("Unsupported sidebar view mutation: {$operation}");
        }
        if (!$changed) {
            return;
        }
        ++$view->revision;
        if ($view->showing || $operation === 'showing') {
            $this->markViewDirty($state, $view);
        }
    }

    private function hideOtherDisplays(
        SidebarState|ObjectiveState $selected,
        Player $player,
        ScoreboardDisplaySlot $slot,
    ): void {
        $uuid = strtolower($player->uuid);
        if ($slot === ScoreboardDisplaySlot::SIDEBAR) {
            foreach ($this->sidebars as $states) {
                foreach ($states as $state) {
                    if ($state === $selected || !isset($state->views[$uuid]) || !$state->views[$uuid]->showing) {
                        continue;
                    }
                    $state->views[$uuid]->showing = false;
                    ++$state->views[$uuid]->revision;
                    $this->markViewDirty($state, $state->views[$uuid]);
                }
            }
        }
        foreach ($this->objectives as $states) {
            foreach ($states as $state) {
                if ($state === $selected || !isset($state->displays[$uuid][$slot->value])) {
                    continue;
                }
                unset($state->displays[$uuid][$slot->value]);
                $this->touchObjective($state);
            }
        }
    }

    /**
     * @param array<string, string> $base
     * @param array<string, string|null> $overrides
     * @return array<string, string>
     */
    private function effectiveLines(array $base, array $overrides): array
    {
        $effective = [];
        foreach ($base as $key => $text) {
            if (array_key_exists($key, $overrides)) {
                if ($overrides[$key] !== null) {
                    $effective[$key] = $overrides[$key];
                }
            } else {
                $effective[$key] = $text;
            }
        }
        foreach ($overrides as $key => $text) {
            if (!array_key_exists($key, $base) && $text !== null) {
                $effective[$key] = $text;
            }
        }

        return $effective;
    }

    /** @param array<string, string> $lines */
    private function assertViewsFit(SidebarState $state, array $lines): void
    {
        foreach ($state->views as $view) {
            $this->assertEffectiveLineLimit($lines, $view->lineOverrides);
        }
    }

    /**
     * @param array<string, string> $base
     * @param array<string, string|null> $overrides
     */
    private function assertEffectiveLineLimit(array $base, array $overrides): void
    {
        if (count($this->effectiveLines($base, $overrides)) > Sidebar::MAXIMUM_LINES) {
            throw new InvalidArgumentException('A personalized sidebar cannot contain more than 15 effective lines.');
        }
    }

    private function removeSidebar(string $owner, string $id): bool
    {
        $ownerKey = self::ownerKey($owner);
        $state = $this->sidebars[$ownerKey][$id] ?? null;
        if (!$state instanceof SidebarState) {
            return false;
        }
        $state->removed = true;
        ++$state->revision;
        foreach ($state->views as $view) {
            $wasShowing = $view->showing;
            $view->removed = true;
            $view->showing = false;
            ++$view->revision;
            if ($wasShowing) {
                $this->markViewDirty($state, $view);
            }
        }
        $this->markSidebarDirty($state);
        unset($this->sidebars[$ownerKey][$id]);

        return true;
    }

    private function createObjective(string $owner, string $id, string $displayName): ScoreboardObjective
    {
        $ownerKey = self::ownerKey($owner);
        if (isset($this->objectives[$ownerKey][$id])) {
            throw new PluginException("Scoreboard objective already exists: {$id}");
        }
        if (count($this->objectives[$ownerKey] ?? []) >= self::MAXIMUM_OBJECTIVES_PER_PLUGIN) {
            throw new PluginException('Scoreboard objective capacity has been reached.');
        }
        $this->ensureOwnerCleanup($owner, $ownerKey);
        $state = new ObjectiveState($owner, $id, $displayName);
        $state->handle = new ScoreboardObjective(
            $id,
            fn(): array => ['displayName' => $state->displayName, 'removed' => $state->removed],
            function (string $name) use ($state): void {
                $this->requireObjectiveActive($state);
                if ($state->displayName !== $name) {
                    $state->displayName = $name;
                    $this->touchObjective($state);
                }
            },
            fn(Player|Entity|string $holder): ?int => $state->scores[$this->holderKey($holder)]['score'] ?? null,
            function (Player|Entity|string $holder, ?int $score) use ($state): void {
                $this->setObjectiveScore($state, $holder, $score);
            },
            function (Player $player, ScoreboardDisplaySlot $slot, ScoreboardSortOrder $sortOrder) use ($state): void {
                $this->showObjective($state, $player, $slot, $sortOrder);
            },
            function (Player $player, ScoreboardDisplaySlot $slot) use ($state): void {
                $this->hideObjective($state, $player, $slot);
            },
            fn(Player $player, ScoreboardDisplaySlot $slot): bool => $state->isShowingTo($player, $slot),
            function () use ($owner, $id): void {
                $this->removeObjective($owner, $id);
            },
        );
        $this->objectives[$ownerKey][$id] = $state;
        $this->markObjectiveDirty($state);
        if (!$state->handle instanceof ScoreboardObjective) {
            throw new LogicException('Scoreboard objective handle was not initialized.');
        }

        return $state->handle;
    }

    private function setObjectiveScore(ObjectiveState $state, Player|Entity|string $holder, ?int $score): void
    {
        $this->requireObjectiveActive($state);
        $key = $this->holderKey($holder);
        $current = $state->scores[$key]['score'] ?? null;
        if ($current === $score) {
            return;
        }
        $eventKey = 'score:' . self::ownerKey($state->owner) . ':' . $state->id . ':' . $key;
        $this->enterEventMutation($eventKey);
        try {
            if ($this->dispatchEvent !== null) {
                $event = ($this->dispatchEvent)(new ScoreboardScoreChangeEvent(
                    $state->handle ?? throw new LogicException('Scoreboard objective handle was not initialized.'),
                    $holder,
                    $current,
                    $score,
                ));
                if (!$event instanceof ScoreboardScoreChangeEvent) {
                    throw new LogicException('Scoreboard event dispatch returned the wrong event type.');
                }
                if ($event->isCancelled()) {
                    return;
                }
                $score = $event->score();
                if ($current === $score) {
                    return;
                }
            }
        } finally {
            unset($this->activeEventMutations[$eventKey]);
        }
        if ($score === null) {
            unset($state->scores[$key]);
        } else {
            if (!isset($state->scores[$key]) && count($state->scores) >= self::MAXIMUM_SCORES_PER_OBJECTIVE) {
                throw new PluginException('Scoreboard objective entry capacity has been reached.');
            }
            $state->scores[$key] = ['holder' => ObjectiveScoreHolder::from($holder), 'score' => $score];
        }
        $this->touchObjective($state);
    }

    private function showObjective(
        ObjectiveState $state,
        Player $player,
        ScoreboardDisplaySlot $slot,
        ScoreboardSortOrder $sortOrder,
    ): void {
        $this->requireObjectiveActive($state);
        $this->requirePlayerOnline($player);
        $uuid = strtolower($player->uuid);
        $current = $state->displays[$uuid][$slot->value] ?? null;
        if ($current !== null && $current['sortOrder'] === $sortOrder) {
            return;
        }
        if (!$this->dispatchDisplayTransition(
            $player,
            $slot,
            $state->handle,
            $sortOrder,
            $current !== null,
        )) {
            return;
        }
        $this->hideOtherDisplays($state, $player, $slot);
        $state->displays[$uuid][$slot->value] = ['player' => $player, 'sortOrder' => $sortOrder];
        $this->touchObjective($state);
    }

    private function hideObjective(ObjectiveState $state, Player $player, ScoreboardDisplaySlot $slot): void
    {
        $this->requireObjectiveActive($state);
        $uuid = strtolower($player->uuid);
        if (!isset($state->displays[$uuid][$slot->value])) {
            return;
        }
        if (!$this->dispatchDisplayTransition($player, $slot, null, $state->displays[$uuid][$slot->value]['sortOrder'])) {
            return;
        }
        unset($state->displays[$uuid][$slot->value]);
        $this->touchObjective($state);
    }

    private function dispatchDisplayTransition(
        Player $player,
        ScoreboardDisplaySlot $slot,
        Sidebar|ScoreboardObjective|null $display,
        ScoreboardSortOrder $sortOrder,
        bool $force = false,
    ): bool {
        if ($this->dispatchEvent === null) {
            return true;
        }
        $previous = $this->activeDisplay($player, $slot);
        if (!$force && $previous === $display) {
            return true;
        }
        $eventKey = 'display:' . strtolower($player->uuid) . ':' . $slot->value;
        $this->enterEventMutation($eventKey);
        try {
            $event = ($this->dispatchEvent)(new PlayerScoreboardDisplayEvent(
                $player,
                $slot,
                $previous,
                $display,
                $sortOrder,
            ));
            if (!$event instanceof PlayerScoreboardDisplayEvent) {
                throw new LogicException('Scoreboard event dispatch returned the wrong event type.');
            }

            return !$event->isCancelled();
        } finally {
            unset($this->activeEventMutations[$eventKey]);
        }
    }

    private function activeDisplay(
        Player $player,
        ScoreboardDisplaySlot $slot,
    ): Sidebar|ScoreboardObjective|null {
        $uuid = strtolower($player->uuid);
        if ($slot === ScoreboardDisplaySlot::SIDEBAR) {
            foreach ($this->sidebars as $states) {
                foreach ($states as $state) {
                    if (($state->views[$uuid]->showing ?? false) && $state->handle instanceof Sidebar) {
                        return $state->handle;
                    }
                }
            }
        }
        foreach ($this->objectives as $states) {
            foreach ($states as $state) {
                if (isset($state->displays[$uuid][$slot->value]) && $state->handle instanceof ScoreboardObjective) {
                    return $state->handle;
                }
            }
        }

        return null;
    }

    private function enterEventMutation(string $key): void
    {
        if (isset($this->activeEventMutations[$key])) {
            throw new LogicException('Recursive scoreboard mutation is not allowed for the same resource.');
        }
        $this->activeEventMutations[$key] = true;
    }

    private function removeObjective(string $owner, string $id): bool
    {
        $ownerKey = self::ownerKey($owner);
        $state = $this->objectives[$ownerKey][$id] ?? null;
        if (!$state instanceof ObjectiveState) {
            return false;
        }
        $state->removed = true;
        ++$state->revision;
        $state->displays = [];
        $this->markObjectiveDirty($state);
        unset($this->objectives[$ownerKey][$id]);

        return true;
    }

    private function holderKey(Player|Entity|string $holder): string
    {
        return ObjectiveScoreHolder::from($holder)->key;
    }

    private function touchObjective(ObjectiveState $state): void
    {
        ++$state->revision;
        $this->markObjectiveDirty($state);
    }

    private function markSidebarDirty(SidebarState $state): void
    {
        $key = "sidebar\0" . self::ownerKey($state->owner) . "\0{$state->id}";
        $projection = new SidebarProjection(
            $state->owner,
            $state->id,
            $state->title,
            $state->lines,
            $state->revision,
            $state->removed,
        );
        $this->dirty[$key] = new ScoreboardDirtyEntry(
            ScoreboardDirtyResource::SIDEBAR,
            $state->owner,
            $state->id,
            $state->revision,
            $state->removed,
            projection: $projection,
        );
    }

    private function markViewDirty(SidebarState $state, SidebarViewState $view): void
    {
        $uuid = strtolower($view->player->uuid);
        $key = "view\0" . self::ownerKey($state->owner) . "\0{$state->id}\0{$uuid}";
        $projection = new SidebarViewProjection(
            $state->owner,
            $state->id,
            $view->player,
            $view->titleOverride ?? $state->title,
            $this->effectiveLines($state->lines, $view->lineOverrides),
            $view->showing,
            $view->revision,
            $view->removed || $state->removed,
        );
        $this->dirty[$key] = new ScoreboardDirtyEntry(
            ScoreboardDirtyResource::SIDEBAR_VIEW,
            $state->owner,
            $state->id,
            $view->revision,
            $view->removed || $state->removed,
            $uuid,
            $projection,
        );
    }

    private function markObjectiveDirty(ObjectiveState $state): void
    {
        $key = "objective\0" . self::ownerKey($state->owner) . "\0{$state->id}";
        $scores = [];
        foreach ($state->scores as $score) {
            $scores[] = new ObjectiveScoreProjection($score['holder'], $score['score']);
        }
        $displays = [];
        foreach ($state->displays as $slots) {
            foreach ($slots as $slot => $display) {
                $displaySlot = ScoreboardDisplaySlot::tryFrom($slot)
                    ?? throw new LogicException('Scoreboard display slot state is invalid.');
                $displays[] = new ObjectiveDisplayProjection(
                    $display['player'],
                    $displaySlot,
                    $display['sortOrder'],
                );
            }
        }
        $projection = new ObjectiveProjection(
            $state->owner,
            $state->id,
            $state->displayName,
            $scores,
            $displays,
            $state->revision,
            $state->removed,
        );
        $this->dirty[$key] = new ScoreboardDirtyEntry(
            ScoreboardDirtyResource::OBJECTIVE,
            $state->owner,
            $state->id,
            $state->revision,
            $state->removed,
            projection: $projection,
        );
    }

    private function allocateSidebarId(string $ownerKey): string
    {
        do {
            $next = ($this->nextSidebarId[$ownerKey] ?? 0) + 1;
            $this->nextSidebarId[$ownerKey] = $next;
            $id = "sidebar-{$next}";
        } while (isset($this->sidebars[$ownerKey][$id]));

        return $id;
    }

    private function ensureOwnerCleanup(string $owner, string $ownerKey): void
    {
        if (isset($this->ownedCleanup[$ownerKey])) {
            return;
        }
        $this->ownership->own($owner, 'scoreboards', function () use ($owner, $ownerKey): void {
            foreach (array_keys($this->sidebars[$ownerKey] ?? []) as $id) {
                $this->removeSidebar($owner, $id);
            }
            foreach (array_keys($this->objectives[$ownerKey] ?? []) as $id) {
                $this->removeObjective($owner, $id);
            }
            unset($this->ownedCleanup[$ownerKey], $this->nextSidebarId[$ownerKey]);
        });
        $this->ownedCleanup[$ownerKey] = true;
    }

    private function requireSidebarActive(SidebarState $state): void
    {
        if ($state->removed) {
            throw new LogicException('This sidebar has been removed.');
        }
    }

    private function requireObjectiveActive(ObjectiveState $state): void
    {
        if ($state->removed) {
            throw new LogicException('This scoreboard objective has been removed.');
        }
    }

    private function requirePlayerOnline(Player $player): void
    {
        $uuid = strtolower($player->uuid);
        foreach (($this->onlinePlayers)() as $onlinePlayer) {
            if (strtolower($onlinePlayer->uuid) === $uuid) {
                return;
            }
        }

        throw new LogicException('Scoreboard displays can only be shown to online players.');
    }

    private static function ownerKey(string $owner): string
    {
        return strtolower($owner);
    }
}
