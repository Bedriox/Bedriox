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
use Bedriox\Api\Effect\EffectCause;
use Bedriox\Api\Effect\EffectInstance;
use Bedriox\Api\Effect\EffectType;
use LogicException;

final readonly class EffectCommand implements BuiltinCommand
{
    private const int DEFAULT_DURATION_SECONDS = 30;
    private const int MAXIMUM_DURATION_SECONDS = 1_073_741;

    public function definition(): CommandDefinition
    {
        return new CommandDefinition(
            'effect',
            'Adds or removes status effects from a player.',
            permission: 'bedriox.command.effect',
        );
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::onlinePlayer('player'))
                ->addArgument(CommandParameter::literal('clear')))
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::onlinePlayer('player'))
                ->addArgument(CommandParameter::literal('clear'))
                ->addArgument(CommandParameter::choice('clearEffect', self::effectNames())))
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::onlinePlayer('player'))
                ->addArgument(CommandParameter::choice('effect', self::effectNames()))
                ->addArgument(CommandParameter::integer('seconds')
                    ->minimum(1)
                    ->maximum(self::MAXIMUM_DURATION_SECONDS)
                    ->optional(self::DEFAULT_DURATION_SECONDS))
                ->addArgument(CommandParameter::integer('amplifier')
                    ->minimum(0)
                    ->maximum(EffectInstance::MAXIMUM_AMPLIFIER)
                    ->optional(0))
                ->addArgument(CommandParameter::boolean('hideParticles')->optional(false)))
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::onlinePlayer('player'))
                ->addArgument(CommandParameter::choice('effect', self::effectNames()))
                ->addArgument(CommandParameter::literal('infinite'))
                ->addArgument(CommandParameter::integer('amplifier')
                    ->minimum(0)
                    ->maximum(EffectInstance::MAXIMUM_AMPLIFIER)
                    ->optional(0))
                ->addArgument(CommandParameter::boolean('hideParticles')->optional(false)));
    }

    public function execute(CommandContext $context): CommandResult
    {
        $values = $context->values();
        $player = $values->player('player');
        try {
            if ($values->has('clear')) {
                if ($values->has('clearEffect')) {
                    $effect = self::effect($values->choice('clearEffect'));
                    $player->getEffects()->remove($effect, EffectCause::COMMAND);

                    return CommandResult::success("Removed {$effect->value} from {$player->name}.");
                }
                $player->getEffects()->clear(EffectCause::COMMAND);

                return CommandResult::success("Cleared all effects from {$player->name}.");
            }

            $effect = self::effect($values->choice('effect'));
            $infinite = $values->has('infinite');
            $seconds = $infinite ? 0 : $values->integer('seconds');
            $amplifier = $values->integer('amplifier');
            $hideParticles = $values->boolean('hideParticles');
            $player->getEffects()->add(new EffectInstance(
                $effect,
                $seconds * 20,
                $amplifier,
                visible: !$hideParticles,
                infinite: $infinite,
            ), EffectCause::COMMAND);

            return CommandResult::success($infinite ? sprintf(
                'Applied %s %d to %s indefinitely.',
                $effect->value,
                $amplifier + 1,
                $player->name,
            ) : sprintf(
                'Applied %s %d to %s for %d seconds.',
                $effect->value,
                $amplifier + 1,
                $player->name,
                $seconds,
            ));
        } catch (LogicException) {
            return CommandResult::failure('The player is no longer available.');
        }
    }

    /** @return list<string> */
    private static function effectNames(): array
    {
        return array_map(
            static fn(EffectType $type): string => substr($type->value, strlen('minecraft:')),
            EffectType::cases(),
        );
    }

    private static function effect(string $name): EffectType
    {
        return EffectType::from('minecraft:' . $name);
    }
}
