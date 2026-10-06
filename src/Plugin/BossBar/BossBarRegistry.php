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

namespace Bedriox\Server\Plugin\BossBar;

use Bedriox\Api\BossBar\BossBar;
use Bedriox\Api\BossBar\BossBarColor;
use Bedriox\Api\BossBar\BossBarManager;
use Bedriox\Api\BossBar\BossBarStyle;
use Bedriox\Api\Player\Player;
use Bedriox\Protocol\Packet\ActorFlag;
use Bedriox\Protocol\Packet\ActorMetadata;
use Bedriox\Protocol\Packet\AddActorPacket;
use Bedriox\Protocol\Packet\BossEventAction;
use Bedriox\Protocol\Packet\BossEventColor;
use Bedriox\Protocol\Packet\BossEventOverlay;
use Bedriox\Protocol\Packet\BossEventPacket;
use Bedriox\Protocol\Packet\Packet;
use Bedriox\Protocol\Packet\RemoveActorPacket;
use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Closure;
use LogicException;

/** @internal Owns bounded boss-bar state and its Bedrock presentation projection. */
final class BossBarRegistry
{
    public const int MAXIMUM_BARS_PER_PLUGIN = 64;
    public const int MAXIMUM_BARS = 1_024;
    public const int MAXIMUM_VIEWERS_PER_BAR = 1_024;

    /** @var array<string, array<string, BossBarState>> */
    private array $bars = [];
    /** @var array<string, true> */
    private array $ownedCleanup = [];
    private int $nextActorId = -1;

    /**
     * @param Closure(): list<Player> $onlinePlayers
     */
    public function __construct(
        private readonly PluginOwnershipRegistry $ownership,
        private readonly Closure $onlinePlayers,
    ) {}

    public function forOwner(string $owner): BossBarManager
    {
        return new BossBarManager(
            fn(string $id, string $title, float $progress, BossBarColor $color, BossBarStyle $style): BossBar =>
                $this->create($owner, $id, $title, $progress, $color, $style),
            fn(string $id): ?BossBar => $this->bars[self::ownerKey($owner)][$id]->handle ?? null,
            fn(): array => array_values(array_map(
                static fn(BossBarState $state): BossBar => $state->handle
                    ?? throw new LogicException('Boss-bar handle was not initialized.'),
                $this->bars[self::ownerKey($owner)] ?? [],
            )),
            fn(string $id): bool => $this->remove($owner, $id),
        );
    }

    private function create(
        string $owner,
        string $id,
        string $title,
        float $progress,
        BossBarColor $color,
        BossBarStyle $style,
    ): BossBar {
        $ownerKey = self::ownerKey($owner);
        if (isset($this->bars[$ownerKey][$id])) {
            throw new PluginException("Boss bar already exists: {$id}");
        }
        if (count($this->bars[$ownerKey] ?? []) >= self::MAXIMUM_BARS_PER_PLUGIN
            || $this->count() >= self::MAXIMUM_BARS) {
            throw new PluginException('Boss-bar capacity has been reached.');
        }
        if (!isset($this->ownedCleanup[$ownerKey])) {
            $this->ownership->own($owner, 'boss-bars', function () use ($owner): void {
                $this->removeAll($owner);
            });
            $this->ownedCleanup[$ownerKey] = true;
        }

        $state = new BossBarState($owner, $id, $this->nextActorId--, $title, $progress, $color, $style);
        $state->handle = new BossBar(
            $id,
            fn(): array => $this->snapshot($state),
            function (string $field, mixed $value) use ($state): void {
                $this->mutate($state, $field, $value);
            },
            function (Player $player) use ($state): void {
                $this->addViewer($state, $player);
            },
            function (Player $player) use ($state): void {
                $this->removeViewer($state, $player);
            },
            function () use ($owner, $id): void {
                $this->remove($owner, $id);
            },
        );
        $this->bars[$ownerKey][$id] = $state;

        return $state->handle;
    }

    /** @return array{title: string, progress: float, color: BossBarColor, style: BossBarStyle, viewers: list<Player>, visible: bool, removed: bool} */
    private function snapshot(BossBarState $state): array
    {
        return [
            'title' => $state->title,
            'progress' => $state->progress,
            'color' => $state->color,
            'style' => $state->style,
            'viewers' => array_values($state->viewers),
            'visible' => $state->visible,
            'removed' => $state->removed,
        ];
    }

    private function mutate(BossBarState $state, string $field, mixed $value): void
    {
        $this->requireActive($state);
        $action = match ($field) {
            'title' => is_string($value)
                ? $this->replaceTitle($state, $value)
                : throw new LogicException('Boss-bar title must be a string.'),
            'progress' => is_float($value)
                ? $this->replaceProgress($state, $value)
                : throw new LogicException('Boss-bar progress must be a float.'),
            'color' => $value instanceof BossBarColor
                ? $this->replaceColor($state, $value)
                : throw new LogicException('Boss-bar color must be a BossBarColor.'),
            'style' => $value instanceof BossBarStyle
                ? $this->replaceStyle($state, $value)
                : throw new LogicException('Boss-bar style must be a BossBarStyle.'),
            'visible' => $this->mutateVisibility($state, $value),
            default => throw new LogicException('Unknown boss-bar state field.'),
        };
        if ($action instanceof BossEventAction && $state->visible) {
            $this->broadcast($state, $this->event($state, $action));
        }
    }

    private function replaceTitle(BossBarState $state, string $title): ?BossEventAction
    {
        if ($state->title === $title) {
            return null;
        }
        $state->title = $title;

        return BossEventAction::UPDATE_NAME;
    }

    private function replaceProgress(BossBarState $state, float $progress): ?BossEventAction
    {
        if ($state->progress === $progress) {
            return null;
        }
        $state->progress = $progress;

        return BossEventAction::UPDATE_PERCENTAGE;
    }

    private function replaceColor(BossBarState $state, BossBarColor $color): ?BossEventAction
    {
        if ($state->color === $color) {
            return null;
        }
        $state->color = $color;

        return BossEventAction::UPDATE_STYLE;
    }

    private function replaceStyle(BossBarState $state, BossBarStyle $style): ?BossEventAction
    {
        if ($state->style === $style) {
            return null;
        }
        $state->style = $style;

        return BossEventAction::UPDATE_STYLE;
    }

    private function mutateVisibility(BossBarState $state, mixed $visible): null
    {
        if (!is_bool($visible)) {
            throw new LogicException('Boss-bar visibility must be a boolean.');
        }
        if ($state->visible === $visible) {
            return null;
        }
        $state->visible = $visible;
        foreach (array_keys($state->viewers) as $uuid) {
            $visible ? $this->show($state, $uuid) : $this->hide($state, $uuid);
        }

        return null;
    }

    private function addViewer(BossBarState $state, Player $player): void
    {
        $this->requireActive($state);
        $key = strtolower($player->uuid);
        if (isset($state->viewers[$key])) {
            return;
        }
        if (count($state->viewers) >= self::MAXIMUM_VIEWERS_PER_BAR) {
            throw new PluginException('Boss-bar viewer capacity has been reached.');
        }
        $state->viewers[$key] = $player;
        if ($state->visible) {
            $this->show($state, $key);
        }
    }

    private function removeViewer(BossBarState $state, Player $player): void
    {
        $this->requireActive($state);
        $key = strtolower($player->uuid);
        if (!isset($state->viewers[$key])) {
            return;
        }
        if ($state->visible) {
            $this->hide($state, $key);
        }
        unset($state->viewers[$key]);
    }

    private function remove(string $owner, string $id): bool
    {
        $ownerKey = self::ownerKey($owner);
        $state = $this->bars[$ownerKey][$id] ?? null;
        if (!$state instanceof BossBarState) {
            return false;
        }
        if ($state->visible) {
            foreach (array_keys($state->viewers) as $uuid) {
                $this->hide($state, $uuid);
            }
        }
        $state->removed = true;
        $state->viewers = [];
        unset($this->bars[$ownerKey][$id]);
        if (($this->bars[$ownerKey] ?? []) === []) {
            unset($this->bars[$ownerKey]);
        }

        return true;
    }

    private function removeAll(string $owner): void
    {
        $ownerKey = self::ownerKey($owner);
        foreach (array_keys($this->bars[$ownerKey] ?? []) as $id) {
            $this->remove($owner, $id);
        }
        unset($this->ownedCleanup[$ownerKey]);
    }

    private function show(BossBarState $state, string $uuid): void
    {
        $player = $this->onlinePlayer($uuid);
        if (!$player instanceof Player) {
            return;
        }
        $player->connection()->sendPacket(new AddActorPacket(
            $state->actorId,
            UnsignedLong::fromSignedBits($state->actorId),
            'minecraft:creeper',
            $player->position->x,
            $player->position->y - 10.0,
            $player->position->z,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            metadata: [
                ActorMetadata::long(0, ActorFlag::Invisible->mask()),
                ActorMetadata::float(38, 0.0),
                ActorMetadata::float(53, 0.0),
                ActorMetadata::float(54, 0.0),
            ],
        ));
        $player->connection()->sendPacket($this->event($state, BossEventAction::CREATE));
    }

    private function hide(BossBarState $state, string $uuid): void
    {
        $this->send($uuid, $this->event($state, BossEventAction::REMOVE));
        $this->send($uuid, new RemoveActorPacket($state->actorId));
    }

    private function broadcast(BossBarState $state, Packet $packet): void
    {
        foreach (array_keys($state->viewers) as $uuid) {
            $this->send($uuid, $packet);
        }
    }

    private function send(string $uuid, Packet $packet): void
    {
        $this->onlinePlayer($uuid)?->connection()->sendPacket($packet);
    }

    private function onlinePlayer(string $uuid): ?Player
    {
        foreach (($this->onlinePlayers)() as $player) {
            if (strtolower($player->uuid) === $uuid && $player->isConnected()) {
                return $player;
            }
        }

        return null;
    }

    private function event(BossBarState $state, BossEventAction $action): BossEventPacket
    {
        return new BossEventPacket(
            $state->actorId,
            $action,
            $state->title,
            '',
            $state->progress,
            match ($state->color) {
                BossBarColor::PINK => BossEventColor::PINK,
                BossBarColor::BLUE => BossEventColor::BLUE,
                BossBarColor::RED => BossEventColor::RED,
                BossBarColor::GREEN => BossEventColor::GREEN,
                BossBarColor::YELLOW => BossEventColor::YELLOW,
                BossBarColor::PURPLE => BossEventColor::PURPLE,
                BossBarColor::WHITE => BossEventColor::WHITE,
            },
            match ($state->style) {
                BossBarStyle::SOLID => BossEventOverlay::PROGRESS,
                BossBarStyle::SEGMENTED_6 => BossEventOverlay::NOTCHED_6,
                BossBarStyle::SEGMENTED_10 => BossEventOverlay::NOTCHED_10,
                BossBarStyle::SEGMENTED_12 => BossEventOverlay::NOTCHED_12,
                BossBarStyle::SEGMENTED_20 => BossEventOverlay::NOTCHED_20,
            },
        );
    }

    private function requireActive(BossBarState $state): void
    {
        if ($state->removed) {
            throw new LogicException('Boss bar has been removed.');
        }
    }

    private function count(): int
    {
        return array_sum(array_map('count', $this->bars));
    }

    private static function ownerKey(string $owner): string
    {
        return strtolower($owner);
    }
}
