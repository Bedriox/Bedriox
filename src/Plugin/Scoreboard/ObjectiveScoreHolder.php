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
use Bedriox\Api\Player\Player;

/** @internal Immutable identity retained by objectives instead of player or entity snapshots. */
final readonly class ObjectiveScoreHolder
{
    private function __construct(
        public ObjectiveScoreHolderType $type,
        public string $key,
        public ?string $playerUuid = null,
        public ?int $entityRuntimeId = null,
        public ?string $name = null,
    ) {}

    public static function from(Player|Entity|string $holder): self
    {
        return match (true) {
            $holder instanceof Player => new self(
                ObjectiveScoreHolderType::PLAYER,
                'player:' . strtolower($holder->uuid),
                playerUuid: strtolower($holder->uuid),
            ),
            $holder instanceof Entity => new self(
                ObjectiveScoreHolderType::ENTITY,
                'entity:' . strtolower($holder->getUniqueId()),
                entityRuntimeId: $holder->getRuntimeId(),
            ),
            default => new self(ObjectiveScoreHolderType::NAMED, 'named:' . $holder, name: $holder),
        };
    }
}
