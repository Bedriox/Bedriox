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

namespace Bedriox\Server\Plugin\Display;

use Bedriox\Api\Display\DisplayInteractionType;
use Bedriox\Api\Display\DisplayManager;
use Bedriox\Api\Display\InteractionEntity;
use Bedriox\Api\Display\TextDisplay;
use Bedriox\Api\Entity\Vector3;
use Bedriox\Api\Event\Display\DisplayInteractedEvent;
use Bedriox\Api\Event\Display\DisplayInteractEvent;
use Bedriox\Api\Event\Display\TextDisplayChangedEvent;
use Bedriox\Api\Event\Display\TextDisplayChangeEvent;
use Bedriox\Api\Event\Event;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Visibility\VisibilityPolicy;
use Bedriox\Api\World\Position;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Visibility\MutableVisibility;
use Closure;
use LogicException;

/** @internal Owns bounded plugin displays independently of their wire projection. */
final class DisplayRegistry
{
    public const int DEFAULT_VIEW_DISTANCE = 48;
    public const int MAXIMUM_DISPLAYS_PER_PLUGIN = 1_024;
    public const int MAXIMUM_DISPLAYS = 16_384;

    /** @var array<string, array<string, TextDisplayState>> */
    private array $displays = [];
    /** @var array<string, TextDisplayState> */
    private array $displaysByUniqueId = [];
    /** @var array<string, InteractionEntityState> */
    private array $interactionsByUniqueId = [];
    /** @var array<string, array<string, array<string, TextDisplayState>>> */
    private array $tagIndex = [];
    /** @var array<string, true> */
    private array $ownedCleanup = [];

    /**
     * @param null|Closure(Event): Event $dispatch
     * @param null|Closure(TextDisplay, DisplayChange, ?Player): void $onChange
     */
    public function __construct(
        private readonly PluginOwnershipRegistry $ownership,
        private readonly ?Closure $dispatch = null,
        private readonly ?Closure $onChange = null,
    ) {}

    public function forOwner(string $owner): DisplayManager
    {
        return new DisplayManager(
            fn(Position $position, string $text, ?string $key, VisibilityPolicy $visibility): TextDisplay =>
                $this->create($owner, $position, $text, $key, $visibility),
            fn(string $key): ?TextDisplay => $this->stateByKey($owner, $key)?->handle,
            function (string $uniqueId) use ($owner): ?TextDisplay {
                $state = $this->displaysByUniqueId[$uniqueId] ?? null;

                return $state instanceof TextDisplayState && $state->ownerKey === self::ownerKey($owner)
                    ? $state->handle
                    : null;
            },
            fn(): array => array_values(array_map(
                self::handle(...),
                $this->displays[self::ownerKey($owner)] ?? [],
            )),
            fn(string $tag): array => array_values(array_map(
                self::handle(...),
                $this->tagIndex[self::ownerKey($owner)][$tag] ?? [],
            )),
            fn(string $key): bool => $this->removeByKey($owner, $key),
            function () use ($owner): void {
                $this->removeAll($owner);
            },
        );
    }

    public function getByUniqueId(string $uniqueId): ?TextDisplay
    {
        return $this->displaysByUniqueId[strtolower($uniqueId)]->handle ?? null;
    }

    public function interactionByUniqueId(string $uniqueId): ?InteractionEntity
    {
        return $this->interactionsByUniqueId[strtolower($uniqueId)]->handle ?? null;
    }

    /** @return list<TextDisplay> */
    public function all(): array
    {
        return array_values(array_map(self::handle(...), $this->displaysByUniqueId));
    }

    public function dispatchInteraction(
        string $interactionUniqueId,
        Player $player,
        DisplayInteractionType $type,
        ?EquipmentSlot $hand = null,
        ?Vector3 $hitPosition = null,
    ): bool {
        $interaction = $this->interactionsByUniqueId[strtolower($interactionUniqueId)] ?? null;
        if (!$interaction instanceof InteractionEntityState || $interaction->removed || !$interaction->enabled
            || $interaction->display->removed || !$this->visibleTo($interaction->display, $player)) {
            return false;
        }
        $display = self::handle($interaction->display);
        $handle = self::interactionHandle($interaction);
        $event = new DisplayInteractEvent($player, $display, $handle, $type, $hand, $hitPosition);
        $this->dispatch($event);
        if ($event->isCancelled()) {
            return false;
        }
        $this->dispatch(new DisplayInteractedEvent($player, $display, $handle, $type, $hand, $hitPosition));

        return true;
    }

    private function create(
        string $owner,
        Position $position,
        string $text,
        ?string $key,
        VisibilityPolicy $visibility,
    ): TextDisplay {
        $ownerKey = self::ownerKey($owner);
        if ($key !== null && isset($this->displays[$ownerKey][$key])) {
            throw new PluginException("Display already exists: {$key}");
        }
        if (count($this->displays[$ownerKey] ?? []) >= self::MAXIMUM_DISPLAYS_PER_PLUGIN
            || count($this->displaysByUniqueId) >= self::MAXIMUM_DISPLAYS) {
            throw new PluginException('Display capacity has been reached.');
        }
        if (!isset($this->ownedCleanup[$ownerKey])) {
            $this->ownership->own($owner, 'displays', function () use ($owner): void {
                $this->removeAll($owner, true);
            });
            $this->ownedCleanup[$ownerKey] = true;
        }

        $uniqueId = EntityUuid::random();
        $index = $key ?? $uniqueId;
        $state = new TextDisplayState(
            $owner,
            $ownerKey,
            $uniqueId,
            $key,
            $position,
            $text,
            self::DEFAULT_VIEW_DISTANCE,
        );
        $state->visibility = new MutableVisibility(
            $visibility,
            function () use ($state): void {
                $this->changed($state, DisplayChange::VISIBILITY);
            },
            fn(): bool => !$state->removed,
        );
        $state->handle = new TextDisplay(
            $uniqueId,
            $key,
            $owner,
            fn(): array => $this->snapshot($state),
            function (string $field, mixed $value) use ($state): void {
                $this->mutate($state, $field, $value);
            },
            $state->visibility,
            fn(float $width, float $height): InteractionEntity =>
                $this->enableInteraction($state, $width, $height),
            function () use ($state): void {
                $this->disableInteraction($state);
            },
            function () use ($state): void {
                $this->remove($state);
            },
        );
        $this->displays[$ownerKey][$index] = $state;
        $this->displaysByUniqueId[$uniqueId] = $state;
        $this->changed($state, DisplayChange::CREATED);

        return self::handle($state);
    }

    /** @return array{text: string, position: Position, viewDistance: int, tags: list<string>, interaction: ?InteractionEntity, removed: bool} */
    private function snapshot(TextDisplayState $state): array
    {
        return [
            'text' => $state->text,
            'position' => $state->position,
            'viewDistance' => $state->viewDistance,
            'tags' => array_keys($state->tags),
            'interaction' => $state->interaction?->handle,
            'removed' => $state->removed,
        ];
    }

    private function mutate(TextDisplayState $state, string $field, mixed $value): void
    {
        $this->requireActive($state);
        match ($field) {
            'text' => is_string($value)
                ? $this->replaceText($state, $value)
                : throw new LogicException('Display text must be a string.'),
            'position' => $value instanceof Position
                ? $this->replacePosition($state, $value)
                : throw new LogicException('Display position must be a Position.'),
            'viewDistance' => is_int($value)
                ? $this->replaceViewDistance($state, $value)
                : throw new LogicException('Display view distance must be an integer.'),
            'addTag' => is_string($value)
                ? $this->addTag($state, $value)
                : throw new LogicException('Display tag must be a string.'),
            'removeTag' => is_string($value)
                ? $this->removeTag($state, $value)
                : throw new LogicException('Display tag must be a string.'),
            default => throw new LogicException('Unknown display state field.'),
        };
    }

    private function replaceText(TextDisplayState $state, string $text): void
    {
        if ($state->text === $text) {
            return;
        }
        $previous = $state->text;
        $event = new TextDisplayChangeEvent(self::handle($state), $previous, $text);
        $this->dispatch($event);
        if ($event->isCancelled() || $event->getText() === $previous) {
            return;
        }
        $state->text = $event->getText();
        $this->changed($state, DisplayChange::TEXT);
        $this->dispatch(new TextDisplayChangedEvent(self::handle($state), $previous, $state->text));
    }

    private function replacePosition(TextDisplayState $state, Position $position): void
    {
        if ($state->position === $position) {
            return;
        }
        $state->position = $position;
        if ($state->interaction instanceof InteractionEntityState) {
            $state->interaction->position = $position;
        }
        $this->changed($state, DisplayChange::POSITION);
    }

    private function replaceViewDistance(TextDisplayState $state, int $distance): void
    {
        if ($state->viewDistance === $distance) {
            return;
        }
        $state->viewDistance = $distance;
        $this->changed($state, DisplayChange::VIEW_DISTANCE);
    }

    private function addTag(TextDisplayState $state, string $tag): void
    {
        if (isset($state->tags[$tag])) {
            return;
        }
        if (count($state->tags) >= TextDisplay::MAXIMUM_TAGS) {
            throw new PluginException('Display tag capacity has been reached.');
        }
        $state->tags[$tag] = true;
        $this->tagIndex[$state->ownerKey][$tag][$state->uniqueId] = $state;
        $this->changed($state, DisplayChange::TAGS);
    }

    private function removeTag(TextDisplayState $state, string $tag): void
    {
        if (!isset($state->tags[$tag])) {
            return;
        }
        unset($state->tags[$tag]);
        unset($this->tagIndex[$state->ownerKey][$tag][$state->uniqueId]);
        if (($this->tagIndex[$state->ownerKey][$tag] ?? []) === []) {
            unset($this->tagIndex[$state->ownerKey][$tag]);
        }
        if (($this->tagIndex[$state->ownerKey] ?? []) === []) {
            unset($this->tagIndex[$state->ownerKey]);
        }
        $this->changed($state, DisplayChange::TAGS);
    }

    private function visibleTo(TextDisplayState $state, Player $player): bool
    {
        if ($state->removed) {
            return false;
        }

        return $state->visibility->isVisibleTo($player);
    }

    private function enableInteraction(TextDisplayState $display, float $width, float $height): InteractionEntity
    {
        $this->requireActive($display);
        if ($display->interaction instanceof InteractionEntityState && !$display->interaction->removed) {
            $this->replaceInteractionSize($display->interaction, $width, $height);

            return self::interactionHandle($display->interaction);
        }
        $state = new InteractionEntityState(EntityUuid::random(), $display, $display->position, $width, $height);
        $state->handle = new InteractionEntity(
            $state->uniqueId,
            fn(): array => $this->interactionSnapshot($state),
            function (string $field, mixed $value) use ($state): void {
                $this->mutateInteraction($state, $field, $value);
            },
            function () use ($state): void {
                $this->removeInteraction($state);
            },
        );
        $display->interaction = $state;
        $this->interactionsByUniqueId[$state->uniqueId] = $state;
        $this->changed($display, DisplayChange::INTERACTION);

        return $state->handle;
    }

    /** @return array{position: Position, width: float, height: float, enabled: bool, removed: bool} */
    private function interactionSnapshot(InteractionEntityState $state): array
    {
        return [
            'position' => $state->position,
            'width' => $state->width,
            'height' => $state->height,
            'enabled' => $state->enabled,
            'removed' => $state->removed,
        ];
    }

    private function mutateInteraction(InteractionEntityState $state, string $field, mixed $value): void
    {
        $this->requireInteractionActive($state);
        match ($field) {
            'size' => is_array($value) && count($value) === 2 && is_float($value[0]) && is_float($value[1])
                ? $this->replaceInteractionSize($state, $value[0], $value[1])
                : throw new LogicException('Interaction size must contain two floats.'),
            'enabled' => is_bool($value)
                ? $this->replaceInteractionEnabled($state, $value)
                : throw new LogicException('Interaction enabled state must be a boolean.'),
            default => throw new LogicException('Unknown interaction state field.'),
        };
    }

    private function replaceInteractionSize(InteractionEntityState $state, float $width, float $height): void
    {
        if ($state->width === $width && $state->height === $height) {
            return;
        }
        $state->width = $width;
        $state->height = $height;
        $this->changed($state->display, DisplayChange::INTERACTION);
    }

    private function replaceInteractionEnabled(InteractionEntityState $state, bool $enabled): void
    {
        if ($state->enabled === $enabled) {
            return;
        }
        $state->enabled = $enabled;
        $this->changed($state->display, DisplayChange::INTERACTION);
    }

    private function disableInteraction(TextDisplayState $display): void
    {
        $this->requireActive($display);
        if ($display->interaction instanceof InteractionEntityState) {
            $this->removeInteraction($display->interaction);
        }
    }

    private function removeInteraction(InteractionEntityState $state): void
    {
        if ($state->removed) {
            return;
        }
        $state->removed = true;
        $state->enabled = false;
        unset($this->interactionsByUniqueId[$state->uniqueId]);
        if ($state->display->interaction === $state) {
            $state->display->interaction = null;
        }
        if (!$state->display->removed) {
            $this->changed($state->display, DisplayChange::INTERACTION);
        }
    }

    private function removeByKey(string $owner, string $key): bool
    {
        $state = $this->stateByKey($owner, $key);
        if (!$state instanceof TextDisplayState) {
            return false;
        }
        $this->remove($state);

        return true;
    }

    private function remove(TextDisplayState $state): void
    {
        if ($state->removed) {
            return;
        }
        if ($state->interaction instanceof InteractionEntityState) {
            $this->removeInteraction($state->interaction);
        }
        $state->removed = true;
        $state->visibility->clear();
        foreach (array_keys($state->tags) as $tag) {
            unset($this->tagIndex[$state->ownerKey][$tag][$state->uniqueId]);
            if (($this->tagIndex[$state->ownerKey][$tag] ?? []) === []) {
                unset($this->tagIndex[$state->ownerKey][$tag]);
            }
        }
        if (($this->tagIndex[$state->ownerKey] ?? []) === []) {
            unset($this->tagIndex[$state->ownerKey]);
        }
        $index = $state->key ?? $state->uniqueId;
        unset($this->displays[$state->ownerKey][$index], $this->displaysByUniqueId[$state->uniqueId]);
        if (($this->displays[$state->ownerKey] ?? []) === []) {
            unset($this->displays[$state->ownerKey]);
        }
        $this->changed($state, DisplayChange::REMOVED);
    }

    private function removeAll(string $owner, bool $releasingOwner = false): void
    {
        $ownerKey = self::ownerKey($owner);
        foreach (array_values($this->displays[$ownerKey] ?? []) as $state) {
            $this->remove($state);
        }
        if ($releasingOwner) {
            unset($this->ownedCleanup[$ownerKey]);
        }
    }

    private function stateByKey(string $owner, string $key): ?TextDisplayState
    {
        return $this->displays[self::ownerKey($owner)][$key] ?? null;
    }

    private function requireActive(TextDisplayState $state): void
    {
        if ($state->removed) {
            throw new LogicException('Text display has been removed.');
        }
    }

    private function requireInteractionActive(InteractionEntityState $state): void
    {
        if ($state->removed || $state->display->removed) {
            throw new LogicException('Interaction entity has been removed.');
        }
    }

    private function dispatch(Event $event): Event
    {
        return $this->dispatch instanceof Closure ? ($this->dispatch)($event) : $event;
    }

    private function changed(TextDisplayState $state, DisplayChange $change, ?Player $player = null): void
    {
        if ($this->onChange instanceof Closure) {
            ($this->onChange)(self::handle($state), $change, $player);
        }
    }

    private static function handle(TextDisplayState $state): TextDisplay
    {
        return $state->handle ?? throw new LogicException('Text-display handle was not initialized.');
    }

    private static function interactionHandle(InteractionEntityState $state): InteractionEntity
    {
        return $state->handle ?? throw new LogicException('Interaction-entity handle was not initialized.');
    }

    private static function ownerKey(string $owner): string
    {
        return strtolower($owner);
    }
}
