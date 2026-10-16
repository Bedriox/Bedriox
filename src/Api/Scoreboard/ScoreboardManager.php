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

use Closure;
use InvalidArgumentException;

/** Owner-scoped access to scoreboards created by one plugin. */
final readonly class ScoreboardManager
{
    public const int MAXIMUM_ID_BYTES = 128;

    /**
     * @param Closure(string, ?string): Sidebar $createSidebar
     * @param Closure(string): ?Sidebar $getSidebar
     * @param Closure(): list<Sidebar> $getSidebars
     * @param Closure(string): bool $removeSidebar
     * @param Closure(string, string): ScoreboardObjective $createObjective
     * @param Closure(string): ?ScoreboardObjective $getObjective
     * @param Closure(): list<ScoreboardObjective> $getObjectives
     * @param Closure(string): bool $removeObjective
     * @internal The server owns manager construction.
     */
    public function __construct(
        private Closure $createSidebar,
        private Closure $getSidebar,
        private Closure $getSidebars,
        private Closure $removeSidebar,
        private Closure $createObjective,
        private Closure $getObjective,
        private Closure $getObjectives,
        private Closure $removeObjective,
    ) {}

    public function createSidebar(string $title, ?string $id = null): Sidebar
    {
        Sidebar::validateText($title, 'Sidebar title');
        if ($id !== null) {
            self::validateId($id);
        }

        return ($this->createSidebar)($title, $id);
    }

    public function getSidebar(string $id): ?Sidebar
    {
        self::validateId($id);

        return ($this->getSidebar)($id);
    }

    /** @return list<Sidebar> */
    public function getSidebars(): array
    {
        return ($this->getSidebars)();
    }

    public function removeSidebar(string|Sidebar $sidebar): bool
    {
        $id = $sidebar instanceof Sidebar ? $sidebar->getId() : $sidebar;
        self::validateId($id);

        return ($this->removeSidebar)($id);
    }

    public function createObjective(string $id, string $displayName): ScoreboardObjective
    {
        self::validateId($id);
        ScoreboardObjective::validateDisplayName($displayName);

        return ($this->createObjective)($id, $displayName);
    }

    public function getObjective(string $id): ?ScoreboardObjective
    {
        self::validateId($id);

        return ($this->getObjective)($id);
    }

    /** @return list<ScoreboardObjective> */
    public function getObjectives(): array
    {
        return ($this->getObjectives)();
    }

    public function removeObjective(string|ScoreboardObjective $objective): bool
    {
        $id = $objective instanceof ScoreboardObjective ? $objective->getId() : $objective;
        self::validateId($id);

        return ($this->removeObjective)($id);
    }

    public static function validateId(string $id): void
    {
        if ($id === '' || strlen($id) > self::MAXIMUM_ID_BYTES
            || preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $id) !== 1) {
            throw new InvalidArgumentException('Scoreboard IDs must be bounded lowercase identifiers.');
        }
    }
}
