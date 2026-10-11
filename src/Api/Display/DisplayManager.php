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

use Bedriox\Api\Visibility\VisibilityPolicy;
use Bedriox\Api\World\Position;
use Closure;
use InvalidArgumentException;

/** Owner-scoped access to text displays created by one plugin. */
final readonly class DisplayManager
{
    public const int MAXIMUM_KEY_BYTES = 128;

    /**
     * @param Closure(Position, string, ?string, VisibilityPolicy): TextDisplay $createText
     * @param Closure(string): ?TextDisplay $get
     * @param Closure(string): ?TextDisplay $getByUniqueId
     * @param Closure(): list<TextDisplay> $getAll
     * @param Closure(string): list<TextDisplay> $withTag
     * @param Closure(string): bool $remove
     * @param Closure(): void $removeAll
     * @internal The server owns manager construction.
     */
    public function __construct(
        private Closure $createText,
        private Closure $get,
        private Closure $getByUniqueId,
        private Closure $getAll,
        private Closure $withTag,
        private Closure $remove,
        private Closure $removeAll,
    ) {}

    public function createText(
        Position $position,
        string $text,
        ?string $key = null,
        VisibilityPolicy $visibility = new VisibilityPolicy(),
    ): TextDisplay {
        TextDisplay::validatePosition($position);
        TextDisplay::validateText($text);
        if ($key !== null) {
            self::validateKey($key);
        }

        return ($this->createText)($position, $text, $key, $visibility);
    }

    public function get(string $key): ?TextDisplay
    {
        self::validateKey($key);

        return ($this->get)($key);
    }

    public function getByUniqueId(string $uniqueId): ?TextDisplay
    {
        self::validateUniqueId($uniqueId);

        return ($this->getByUniqueId)(strtolower($uniqueId));
    }

    /** @return list<TextDisplay> */
    public function getAll(): array
    {
        return ($this->getAll)();
    }

    /** @return list<TextDisplay> */
    public function withTag(string $tag): array
    {
        TextDisplay::validateTag($tag);

        return ($this->withTag)($tag);
    }

    public function remove(string $key): bool
    {
        self::validateKey($key);

        return ($this->remove)($key);
    }

    public function removeAll(): void
    {
        ($this->removeAll)();
    }

    public static function validateKey(string $key): void
    {
        if ($key === '' || strlen($key) > self::MAXIMUM_KEY_BYTES
            || preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $key) !== 1) {
            throw new InvalidArgumentException('Display keys must be bounded lowercase identifiers.');
        }
    }

    private static function validateUniqueId(string $uniqueId): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $uniqueId) !== 1) {
            throw new InvalidArgumentException('Display UUID must be canonical.');
        }
    }
}
