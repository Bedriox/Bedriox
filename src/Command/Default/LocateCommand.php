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
use Bedriox\Api\World\Position;
use Bedriox\Api\World\World;
use Bedriox\Server\Command\CommandFeedback;
use Bedriox\Server\World\Generation\Structure\Location\LocatedFeature;
use Closure;

final readonly class LocateCommand implements BuiltinCommand
{
    /**
     * @param Closure(\Bedriox\Api\Player\Player, World, Position, LocateStructure, bool, Closure(?LocatedFeature, ?string): void): bool $locateStructure
     * @param Closure(\Bedriox\Api\Player\Player, World, Position, string, Closure(?LocatedFeature, ?string): void): bool                $locateBiome
     */
    public function __construct(
        private Closure $locateStructure,
        private Closure $locateBiome,
    ) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition(
            'locate',
            'Displays the coordinates for the closest structure or biome of a given type.',
            permission: 'bedriox.command.locate',
            allowedSenders: AllowedCommandSenders::PLAYER_ONLY,
        );
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal('structure'))
                ->addArgument(CommandParameter::enum('structureType', LocateStructure::class))
                ->addArgument(CommandParameter::boolean('useNewChunksOnly')->optional(false)))
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal('biome'))
                ->addArgument(CommandParameter::choice('biomeType', LocateBiomeCatalog::identifiers())));
    }

    public function execute(CommandContext $context): CommandResult
    {
        $sender = $context->sender();
        if (!$sender instanceof PlayerCommandSender) {
            return CommandResult::failure('This command requires an in-world player sender.');
        }
        $position = $sender->player()->position;
        $world = $position->world;
        if ($world === null) {
            return CommandResult::failure('The player world is unavailable.');
        }
        $values = $context->values();
        if ($values->has('structure')) {
            /** @var LocateStructure $structure */
            $structure = $values->enum('structureType', LocateStructure::class);
            $identifier = $structure->value;
            $accepted = ($this->locateStructure)(
                $sender->player(),
                $world,
                $position,
                $structure,
                $values->boolean('useNewChunksOnly'),
                $this->completion($sender, $position, $identifier),
            );
        } else {
            $identifier = $values->choice('biomeType');
            $accepted = ($this->locateBiome)(
                $sender->player(),
                $world,
                $position,
                $identifier,
                $this->completion($sender, $position, $identifier),
            );
        }
        if (!$accepted) {
            return CommandResult::failure('The locate search queue is busy. Try again shortly.');
        }

        return CommandResult::information("Searching for {$identifier}...");
    }

    /** @return Closure(?LocatedFeature, ?string): void */
    private function completion(PlayerCommandSender $sender, Position $origin, string $identifier): Closure
    {
        return static function (?LocatedFeature $located, ?string $failure) use ($sender, $origin, $identifier): void {
            if ($failure !== null) {
                $sender->sendMessage(CommandFeedback::error($sender, $failure));
                return;
            }
            if ($located === null) {
                $sender->sendMessage(CommandFeedback::error($sender, "Could not find {$identifier} in this dimension."));
                return;
            }
            $distance = (int) round(hypot($located->x - $origin->x, $located->z - $origin->z));
            $sender->sendMessage(CommandFeedback::success($sender, sprintf(
                'The nearest %s is at block %d, %d, %d (%d blocks away)',
                $located->identifier,
                $located->x,
                $located->y,
                $located->z,
                $distance,
            )));
        };
    }
}
