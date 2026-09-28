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
use Bedriox\Api\Command\CommandParameter;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\Command\CommandSoftEnum;
use Bedriox\Api\Command\PlayerCommandSender;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position;
use Closure;

final readonly class SummonCommand implements BuiltinCommand
{
    /** @param Closure(string, Position, ?Player): bool|null $summon */
    public function __construct(
        private CommandSoftEnum $entityIdentifiers,
        private ?Closure $summon = null,
    ) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition(
            'summon',
            'Summons an entity at a position.',
            permission: 'bedriox.command.summon',
        );
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()
            ->addArgument(CommandParameter::softEnum('type', $this->entityIdentifiers))
            ->addArgument(CommandParameter::position('position')->optional());
    }

    public function execute(CommandContext $context): CommandResult
    {
        $source = $context->sender() instanceof PlayerCommandSender
            ? $context->sender()->player()
            : null;
        $values = $context->values();
        if ($values->has('position')) {
            $position = $values->position('position');
        } else {
            if ($source === null) {
                return CommandResult::failure('Coordinates are required when running this command from the console.');
            }
            $position = $source->position;
        }
        if (!self::isSupportedPosition($position)) {
            return CommandResult::failure('Coordinates are outside the supported world bounds.');
        }
        $identifier = self::canonicalIdentifier($values->string('type'));
        if ($identifier === null || !$this->isAvailable($identifier)) {
            return CommandResult::failure('Unknown or unavailable entity type.');
        }
        if ($this->summon === null || !($this->summon)($identifier, $position, $source)) {
            return CommandResult::failure('Unable to summon the entity.');
        }

        return CommandResult::success(sprintf(
            'Summoned %s at %.2f, %.2f, %.2f.',
            $identifier,
            $position->x,
            $position->y,
            $position->z,
        ));
    }

    private function isAvailable(string $identifier): bool
    {
        foreach ($this->entityIdentifiers->values() as $available) {
            if (self::canonicalIdentifier($available) === $identifier) {
                return true;
            }
        }

        return false;
    }

    private static function canonicalIdentifier(string $value): ?string
    {
        $identifier = strtolower(str_contains($value, ':') ? $value : 'minecraft:' . $value);

        return preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) === 1 ? $identifier : null;
    }

    private static function isSupportedPosition(Position $position): bool
    {
        return is_finite($position->x) && $position->x >= -30_000_000.0 && $position->x <= 30_000_000.0
            && is_finite($position->y) && $position->y >= -64.0 && $position->y <= 319.0
            && is_finite($position->z) && $position->z >= -30_000_000.0 && $position->z <= 30_000_000.0;
    }
}
