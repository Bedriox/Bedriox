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

namespace Bedriox\Api\Display;

use Bedriox\Api\Visibility\Visibility;
use Bedriox\Api\World\Position;
use Closure;
use InvalidArgumentException;

/** Mutable plugin-owned text projected at a world position. */
final readonly class TextDisplay
{
    public const int MAXIMUM_TEXT_BYTES = 4_096;
    public const int MAXIMUM_TEXT_LINES = 32;
    public const int MAXIMUM_TAG_BYTES = 64;
    public const int MAXIMUM_TAGS = 32;
    public const int MINIMUM_VIEW_DISTANCE = 1;
    public const int MAXIMUM_VIEW_DISTANCE = 256;

    /**
     * @param Closure(): array{text: string, position: Position, viewDistance: int, tags: list<string>, interaction: ?InteractionEntity, removed: bool} $state
     * @param Closure(string, mixed): void $mutate
     * @param Closure(float, float): InteractionEntity $enableInteraction
     * @param Closure(): void $disableInteraction
     * @param Closure(): void $remove
     * @internal The server owns text-display construction.
     */
    public function __construct(
        private string $uniqueId,
        private ?string $key,
        private string $owner,
        private Closure $state,
        private Closure $mutate,
        private Visibility $visibility,
        private Closure $enableInteraction,
        private Closure $disableInteraction,
        private Closure $remove,
    ) {}

    public function getUniqueId(): string
    {
        return $this->uniqueId;
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    public function getOwner(): string
    {
        return $this->owner;
    }

    public function getText(): string
    {
        return ($this->state)()['text'];
    }

    public function setText(string $text): void
    {
        self::validateText($text);
        ($this->mutate)('text', $text);
    }

    public function getPosition(): Position
    {
        return ($this->state)()['position'];
    }

    public function teleport(Position $position): void
    {
        self::validatePosition($position);
        ($this->mutate)('position', $position);
    }

    public function getViewDistance(): int
    {
        return ($this->state)()['viewDistance'];
    }

    public function setViewDistance(int $distance): void
    {
        self::validateViewDistance($distance);
        ($this->mutate)('viewDistance', $distance);
    }

    public function getVisibility(): Visibility
    {
        return $this->visibility;
    }

    /** @return list<string> */
    public function getTags(): array
    {
        return ($this->state)()['tags'];
    }

    public function addTag(string $tag): void
    {
        self::validateTag($tag);
        ($this->mutate)('addTag', $tag);
    }

    public function hasTag(string $tag): bool
    {
        self::validateTag($tag);

        return in_array($tag, $this->getTags(), true);
    }

    public function removeTag(string $tag): void
    {
        self::validateTag($tag);
        ($this->mutate)('removeTag', $tag);
    }

    public function getInteraction(): ?InteractionEntity
    {
        return ($this->state)()['interaction'];
    }

    public function enableInteraction(
        float $width = InteractionEntity::DEFAULT_WIDTH,
        float $height = InteractionEntity::DEFAULT_HEIGHT,
    ): InteractionEntity {
        InteractionEntity::validateSize($width, $height);

        return ($this->enableInteraction)($width, $height);
    }

    public function disableInteraction(): void
    {
        ($this->disableInteraction)();
    }

    public function isRemoved(): bool
    {
        return ($this->state)()['removed'];
    }

    public function remove(): void
    {
        ($this->remove)();
    }

    public static function validateText(string $text): void
    {
        $lines = substr_count($text, "\n") + 1;
        if ($text === '' || strlen($text) > self::MAXIMUM_TEXT_BYTES
            || $lines > self::MAXIMUM_TEXT_LINES || preg_match('//u', $text) !== 1) {
            throw new InvalidArgumentException('Display text must be non-empty, bounded UTF-8.');
        }
    }

    public static function validateTag(string $tag): void
    {
        if ($tag === '' || strlen($tag) > self::MAXIMUM_TAG_BYTES
            || preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $tag) !== 1) {
            throw new InvalidArgumentException('Display tags must be bounded lowercase identifiers.');
        }
    }

    public static function validateViewDistance(int $distance): void
    {
        if ($distance < self::MINIMUM_VIEW_DISTANCE || $distance > self::MAXIMUM_VIEW_DISTANCE) {
            throw new InvalidArgumentException('Display view distance is outside its accepted range.');
        }
    }

    public static function validatePosition(Position $position): void
    {
        $position->validate();
        if ($position->world === null) {
            throw new InvalidArgumentException('Display positions must identify a loaded world.');
        }
    }
}
