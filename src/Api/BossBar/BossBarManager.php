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

namespace Bedriox\Api\BossBar;

use Closure;
use InvalidArgumentException;

/** Public owner-scoped access to plugin boss bars. */
final readonly class BossBarManager
{
    public const int MAXIMUM_ID_BYTES = 128;

    /**
     * @param Closure(string, string, float, BossBarColor, BossBarStyle): BossBar $create
     * @param Closure(string): ?BossBar $get
     * @param Closure(): list<BossBar> $getAll
     * @param Closure(string): bool $remove
     * @internal The server owns manager construction.
     */
    public function __construct(
        private Closure $create,
        private Closure $get,
        private Closure $getAll,
        private Closure $remove,
    ) {}

    public function create(
        string $id,
        string $title,
        float $progress = 1.0,
        BossBarColor $color = BossBarColor::PURPLE,
        BossBarStyle $style = BossBarStyle::SOLID,
    ): BossBar {
        self::validateId($id);
        BossBar::validateTitle($title);
        if (!is_finite($progress) || $progress < 0.0 || $progress > 1.0) {
            throw new InvalidArgumentException('Boss-bar progress must be finite and between 0.0 and 1.0.');
        }

        return ($this->create)($id, $title, $progress, $color, $style);
    }

    public function get(string $id): ?BossBar
    {
        self::validateId($id);

        return ($this->get)($id);
    }

    /** @return list<BossBar> */
    public function getAll(): array
    {
        return ($this->getAll)();
    }

    public function remove(string $id): bool
    {
        self::validateId($id);

        return ($this->remove)($id);
    }

    private static function validateId(string $id): void
    {
        if ($id === '' || strlen($id) > self::MAXIMUM_ID_BYTES
            || preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $id) !== 1) {
            throw new InvalidArgumentException('Boss-bar ID must be a bounded lowercase identifier.');
        }
    }
}
