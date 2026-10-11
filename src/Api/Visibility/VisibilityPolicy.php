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

namespace Bedriox\Api\Visibility;

use Bedriox\Api\Player\Player;
use InvalidArgumentException;

/** Immutable initial audience policy applied before a presentation becomes observable. */
final readonly class VisibilityPolicy
{
    public const int MAXIMUM_PLAYER_UUIDS = 4_096;
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    /** @var list<string> */
    private array $selectedPlayerUuids;

    /** @var list<string> */
    private array $excludedPlayerUuids;

    /**
     * @param list<string> $selectedPlayerUuids
     * @param list<string> $excludedPlayerUuids
     */
    public function __construct(
        private VisibilityMode $mode = VisibilityMode::AUTOMATIC,
        array $selectedPlayerUuids = [],
        array $excludedPlayerUuids = [],
    ) {
        $this->selectedPlayerUuids = self::normalizeUuids($selectedPlayerUuids);
        $this->excludedPlayerUuids = self::normalizeUuids($excludedPlayerUuids);
        if ($mode !== VisibilityMode::SELECTED && $this->selectedPlayerUuids !== []) {
            throw new InvalidArgumentException('Only selected visibility may contain selected player UUIDs.');
        }
        if ($mode !== VisibilityMode::EXCLUDED && $this->excludedPlayerUuids !== []) {
            throw new InvalidArgumentException('Only excluded visibility may contain excluded player UUIDs.');
        }
    }

    public static function automatic(): self
    {
        return new self();
    }

    /** @param list<Player> $players */
    public static function selected(array $players): self
    {
        return self::selectedUuids(array_map(static fn(Player $player): string => $player->uuid, $players));
    }

    /** @param list<string> $playerUuids */
    public static function selectedUuids(array $playerUuids): self
    {
        return new self(VisibilityMode::SELECTED, $playerUuids);
    }

    public static function only(Player $player): self
    {
        return self::selected([$player]);
    }

    public static function onlyUuid(string $playerUuid): self
    {
        return self::selectedUuids([$playerUuid]);
    }

    /** @param list<Player> $players */
    public static function excluded(array $players): self
    {
        return self::excludedUuids(array_map(static fn(Player $player): string => $player->uuid, $players));
    }

    /** @param list<string> $playerUuids */
    public static function excludedUuids(array $playerUuids): self
    {
        return new self(VisibilityMode::EXCLUDED, excludedPlayerUuids: $playerUuids);
    }

    public static function hidden(): self
    {
        return new self(VisibilityMode::HIDDEN);
    }

    public function mode(): VisibilityMode
    {
        return $this->mode;
    }

    /** @return list<string> */
    public function selectedPlayerUuids(): array
    {
        return $this->selectedPlayerUuids;
    }

    /** @return list<string> */
    public function excludedPlayerUuids(): array
    {
        return $this->excludedPlayerUuids;
    }

    /** @internal Shared validation for live visibility mutation. */
    public static function normalizeUuid(string $uuid): string
    {
        $normalized = strtolower($uuid);
        if (preg_match(self::UUID_PATTERN, $normalized) !== 1) {
            throw new InvalidArgumentException('Visibility player UUID must be canonical.');
        }

        return $normalized;
    }

    /**
     * @param list<string> $uuids
     * @return list<string>
     */
    private static function normalizeUuids(array $uuids): array
    {
        if (count($uuids) > self::MAXIMUM_PLAYER_UUIDS) {
            throw new InvalidArgumentException('Visibility player selection exceeds its supported capacity.');
        }
        $normalized = [];
        foreach ($uuids as $uuid) {
            $normalized[self::normalizeUuid($uuid)] = true;
        }

        return array_keys($normalized);
    }
}
