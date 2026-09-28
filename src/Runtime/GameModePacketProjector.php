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

namespace Bedriox\Server\Runtime;

use Bedriox\Api\Player\GameMode;
use Bedriox\Protocol\Packet\Ability;
use Bedriox\Protocol\Packet\AbilityLayer;
use Bedriox\Protocol\Packet\CommandPermissionLevel;
use Bedriox\Protocol\Packet\GameType;
use Bedriox\Protocol\Packet\PlayerAbilities;
use Bedriox\Protocol\Packet\PlayerPermission;
use Bedriox\Protocol\Packet\UpdateAbilitiesPacket;

/** Projects server-owned gameplay mode and command authority into current Bedrock values. */
final readonly class GameModePacketProjector
{
    private const int BASE_ABILITY_LAYER = 1;
    private const int SPECTATOR_ABILITY_LAYER = 2;
    private const float FLY_SPEED = 0.05;
    private const float VERTICAL_FLY_SPEED = 1.0;
    private const float WALK_SPEED = 0.1;

    public function gameType(GameMode $gameMode): GameType
    {
        return match ($gameMode) {
            GameMode::SURVIVAL => GameType::Survival,
            GameMode::CREATIVE => GameType::Creative,
            GameMode::ADVENTURE => GameType::Adventure,
            GameMode::SPECTATOR => GameType::Spectator,
        };
    }

    public function abilities(GameMode $gameMode, int $uniqueEntityId, bool $operator = false): UpdateAbilitiesPacket
    {
        $enabled = $this->gameplayAbilities($gameMode);
        if ($operator) {
            $enabled[] = Ability::OperatorCommands;
            $enabled[] = Ability::Teleport;
        }

        $layers = [AbilityLayer::fromAbilities(
            self::BASE_ABILITY_LAYER,
            Ability::cases(),
            $enabled,
            self::FLY_SPEED,
            self::VERTICAL_FLY_SPEED,
            self::WALK_SPEED,
        )];
        if ($gameMode === GameMode::SPECTATOR) {
            $layers[] = AbilityLayer::fromAbilities(
                self::SPECTATOR_ABILITY_LAYER,
                [Ability::Flying],
                [Ability::Flying],
                self::FLY_SPEED,
                self::VERTICAL_FLY_SPEED,
                self::WALK_SPEED,
            );
        }

        return new UpdateAbilitiesPacket(new PlayerAbilities(
            $uniqueEntityId,
            $operator ? PlayerPermission::Operator : PlayerPermission::Member,
            $operator ? CommandPermissionLevel::Operator : CommandPermissionLevel::Normal,
            $layers,
        ));
    }

    /** @return list<Ability> */
    private function gameplayAbilities(GameMode $gameMode): array
    {
        $interaction = [
            Ability::DoorsAndSwitches,
            Ability::OpenContainers,
            Ability::AttackPlayers,
            Ability::AttackMobs,
        ];

        return match ($gameMode) {
            GameMode::SURVIVAL => [Ability::Build, Ability::Mine, ...$interaction],
            GameMode::CREATIVE => [
                Ability::Build,
                Ability::Mine,
                ...$interaction,
                Ability::Invulnerable,
                Ability::MayFly,
                Ability::InstantBuild,
            ],
            GameMode::ADVENTURE => $interaction,
            GameMode::SPECTATOR => [
                Ability::Invulnerable,
                Ability::Flying,
                Ability::MayFly,
                Ability::NoClip,
            ],
        };
    }
}
