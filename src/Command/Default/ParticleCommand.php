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

namespace Bedriox\Server\Command\Default;

use Bedriox\Api\Command\AllowedCommandSenders;
use Bedriox\Api\Command\CommandArguments;
use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandOverload;
use Bedriox\Api\Command\CommandParameter;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\Command\PlayerCommandSender;
use Bedriox\Api\World\Particle\ParticleType;
use Bedriox\Api\World\Particle\SimpleParticle;
use Bedriox\Api\World\Position;
use LogicException;

final readonly class ParticleCommand implements BuiltinCommand
{
    public function definition(): CommandDefinition
    {
        return new CommandDefinition(
            'particle',
            'Spawns a particle in your current world.',
            permission: 'bedriox.command.particle',
            allowedSenders: AllowedCommandSenders::PLAYER_ONLY,
        );
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::enum('particle', ParticleType::class)))
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::enum('particle', ParticleType::class))
                ->addArgument(CommandParameter::position('position')));
    }

    public function execute(CommandContext $context): CommandResult
    {
        if (!$context->sender() instanceof PlayerCommandSender) {
            return CommandResult::failure('This command requires a player world.');
        }
        $player = $context->sender()->player();
        $world = $player->position->world;
        if ($world === null) {
            return CommandResult::failure('The player world is unavailable.');
        }
        /** @var ParticleType $type */
        $type = $context->values()->enum('particle', ParticleType::class);
        $position = $context->values()->has('position')
            ? $context->values()->position('position')
            : $player->position;
        $position = new Position($position->x, $position->y, $position->z, world: $world);
        try {
            $world->spawnParticle($position, new SimpleParticle($type));
        } catch (LogicException) {
            return CommandResult::failure('The player world is no longer available.');
        }

        return CommandResult::administrativeSuccess(sprintf(
            'Spawned %s at %.2f, %.2f, %.2f.',
            $type->value,
            $position->x,
            $position->y,
            $position->z,
        ));
    }
}
