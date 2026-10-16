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

namespace Bedriox\Api\Scoreboard;

use Bedriox\Api\Player\Player;
use Closure;
use InvalidArgumentException;

/** A plugin-owned sidebar with shared content and lightweight per-player views. */
final readonly class Sidebar
{
    public const int MAXIMUM_LINES = 15;
    public const int MAXIMUM_TEXT_BYTES = 1_024;
    public const int MAXIMUM_LINE_KEY_BYTES = 128;

    /**
     * @param Closure(): array{title: string, lines: array<string, string>, viewers: list<Player>, removed: bool} $state
     * @param Closure(string, mixed): void $mutate
     * @param Closure(Player): SidebarView $getViewFor
     * @param Closure(Player): void $showTo
     * @param Closure(Player): void $hideFrom
     * @param Closure(Player): bool $isShowingTo
     * @param Closure(): void $showToAll
     * @param Closure(): void $hideFromAll
     * @param Closure(): void $remove
     * @internal The server owns sidebar construction.
     */
    public function __construct(
        private string $id,
        private Closure $state,
        private Closure $mutate,
        private Closure $getViewFor,
        private Closure $showTo,
        private Closure $hideFrom,
        private Closure $isShowingTo,
        private Closure $showToAll,
        private Closure $hideFromAll,
        private Closure $remove,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return ($this->state)()['title'];
    }

    public function setTitle(string $title): void
    {
        self::validateText($title, 'Sidebar title');
        ($this->mutate)('title', $title);
    }

    /** @return array<string, string> Lines in display order, indexed by their stable keys. */
    public function getLines(): array
    {
        return ($this->state)()['lines'];
    }

    public function getLine(string $key): ?string
    {
        self::validateLineKey($key);

        return $this->getLines()[$key] ?? null;
    }

    public function hasLine(string $key): bool
    {
        self::validateLineKey($key);

        return array_key_exists($key, $this->getLines());
    }

    /** Positions are one-based. Omitting one appends a new line and preserves an existing line's position. */
    public function setLine(string $key, string $text, ?int $position = null): void
    {
        self::validateLineKey($key);
        self::validateText($text, 'Sidebar line');
        if ($position !== null && ($position < 1 || $position > self::MAXIMUM_LINES)) {
            throw new InvalidArgumentException('Sidebar line positions must be between 1 and 15.');
        }
        ($this->mutate)('set-line', [$key, $text, $position]);
    }

    public function prependLine(string $key, string $text): void
    {
        $this->setLine($key, $text, 1);
    }

    public function insertBefore(string $existingKey, string $key, string $text): void
    {
        self::validateLineKey($existingKey);
        self::validateLineKey($key);
        self::validateText($text, 'Sidebar line');
        ($this->mutate)('insert-before', [$existingKey, $key, $text]);
    }

    public function insertAfter(string $existingKey, string $key, string $text): void
    {
        self::validateLineKey($existingKey);
        self::validateLineKey($key);
        self::validateText($text, 'Sidebar line');
        ($this->mutate)('insert-after', [$existingKey, $key, $text]);
    }

    public function moveLine(string $key, int $position): void
    {
        self::validateLineKey($key);
        if ($position < 1 || $position > self::MAXIMUM_LINES) {
            throw new InvalidArgumentException('Sidebar line positions must be between 1 and 15.');
        }
        ($this->mutate)('move-line', [$key, $position]);
    }

    public function removeLine(string $key): bool
    {
        self::validateLineKey($key);
        $exists = $this->hasLine($key);
        if ($exists) {
            ($this->mutate)('remove-line', $key);
        }

        return $exists;
    }

    public function clearLines(): void
    {
        ($this->mutate)('replace-lines', []);
    }

    /** @param array<string, string> $lines Lines are retained in the supplied iteration order. */
    public function replaceLines(array $lines): void
    {
        if (count($lines) > self::MAXIMUM_LINES) {
            throw new InvalidArgumentException('A sidebar cannot contain more than 15 lines.');
        }
        foreach ($lines as $key => $text) {
            self::validateLineKey($key);
            self::validateText($text, 'Sidebar line');
        }
        ($this->mutate)('replace-lines', $lines);
    }

    /** Returns the same logical view whenever called for the same sidebar and player UUID. */
    public function getViewFor(Player $player): SidebarView
    {
        return ($this->getViewFor)($player);
    }

    public function showTo(Player $player): void
    {
        ($this->showTo)($player);
    }

    public function hideFrom(Player $player): void
    {
        ($this->hideFrom)($player);
    }

    public function isShowingTo(Player $player): bool
    {
        return ($this->isShowingTo)($player);
    }

    /** @return list<Player> */
    public function getViewers(): array
    {
        return ($this->state)()['viewers'];
    }

    public function showToAll(): void
    {
        ($this->showToAll)();
    }

    public function hideFromAll(): void
    {
        ($this->hideFromAll)();
    }

    public function isRemoved(): bool
    {
        return ($this->state)()['removed'];
    }

    public function remove(): void
    {
        ($this->remove)();
    }

    public static function validateText(string $text, string $field): void
    {
        if (strlen($text) > self::MAXIMUM_TEXT_BYTES || preg_match('//u', $text) !== 1) {
            throw new InvalidArgumentException("{$field} must be bounded UTF-8.");
        }
    }

    public static function validateLineKey(string $key): void
    {
        if ($key === '' || strlen($key) > self::MAXIMUM_LINE_KEY_BYTES
            || preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $key) !== 1) {
            throw new InvalidArgumentException('Sidebar line keys must be bounded lowercase identifiers.');
        }
    }
}
