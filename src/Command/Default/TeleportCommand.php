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

use Bedriox\Api\Command\CommandArguments;
use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandOverload;
use Bedriox\Api\Command\CommandParameter;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\Command\PlayerCommandSender;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position;
use Closure;

final readonly class TeleportCommand implements BuiltinCommand
{
    /** @param Closure(Player, Position, ?float, ?float): bool|null $teleport */
    public function __construct(private ?Closure $teleport = null) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition(
            'tp',
            'Teleports a player to another player or a position.',
            aliases: ['teleport'],
            permission: 'bedriox.command.teleport',
        );
    }

    public function defineArguments(): CommandArguments
    {
        $destinationPlayer = CommandParameter::onlinePlayer('destinationPlayer');
        $subject = CommandParameter::onlinePlayer('subject');
        $destination = CommandParameter::position('destination');
        $yaw = CommandParameter::float('yaw')->minimum(-360.0)->maximum(360.0);
        $pitch = CommandParameter::float('pitch')->minimum(-90.0)->maximum(90.0);

        return CommandArguments::create()
            ->addOverload(CommandOverload::create()->addArgument($destinationPlayer))
            ->addOverload(CommandOverload::create()->addArgument($subject)->addArgument($destinationPlayer))
            ->addOverload(CommandOverload::create()->addArgument($destination))
            ->addOverload(CommandOverload::create()->addArgument($subject)->addArgument($destination))
            ->addOverload(CommandOverload::create()->addArgument($destination)->addArgument($yaw)->addArgument($pitch))
            ->addOverload(CommandOverload::create()->addArgument($subject)->addArgument($destination)->addArgument($yaw)->addArgument($pitch));
    }

    public function execute(CommandContext $context): CommandResult
    {
        $values = $context->values();
        $hasSubject = $values->has('subject');
        $subject = $hasSubject
            ? $values->player('subject')
            : ($context->sender() instanceof PlayerCommandSender ? $context->sender()->player() : null);
        if ($subject === null) {
            return CommandResult::failure('A player target is required when running this command from the console.');
        }
        if ($hasSubject && !$context->sender()->hasPermission('bedriox.command.teleport.other')) {
            return CommandResult::failure('You do not have permission to teleport other players.');
        }

        if ($values->has('destinationPlayer')) {
            $destinationPlayer = $values->player('destinationPlayer');
            if (!$this->queue($subject, $destinationPlayer->position, $destinationPlayer->yaw, $destinationPlayer->pitch)) {
                return CommandResult::failure('Unable to teleport the player.');
            }

            return CommandResult::success("Teleported {$subject->name} to {$destinationPlayer->name}.");
        }

        $position = $values->position('destination');
        if (!self::isSupportedPosition($position)) {
            return CommandResult::failure('Coordinates are outside the supported world bounds.');
        }
        $yaw = $values->has('yaw') ? $values->float('yaw') : $subject->yaw;
        $pitch = $values->has('pitch') ? $values->float('pitch') : $subject->pitch;
        if (!$this->queue($subject, $position, $yaw, $pitch)) {
            return CommandResult::failure('Unable to teleport the player.');
        }

        return CommandResult::success(sprintf(
            'Teleported %s to %.2f, %.2f, %.2f.',
            $subject->name,
            $position->x,
            $position->y,
            $position->z,
        ));
    }

    private static function isSupportedPosition(Position $position): bool
    {
        return is_finite($position->x) && $position->x >= -30_000_000.0 && $position->x <= 30_000_000.0
            && is_finite($position->y) && $position->y >= -64.0 && $position->y <= 319.0
            && is_finite($position->z) && $position->z >= -30_000_000.0 && $position->z <= 30_000_000.0;
    }

    private function queue(Player $subject, Position $position, float $yaw, float $pitch): bool
    {
        return $this->teleport !== null && ($this->teleport)($subject, $position, $yaw, $pitch);
    }
}
