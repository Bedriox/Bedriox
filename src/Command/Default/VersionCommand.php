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
use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\TextFormat;
use Bedriox\Api\Update\UpdateInfo;
use Bedriox\Server\BuildInfo;
use Bedriox\Server\Command\CommandFeedback;
use Closure;

final readonly class VersionCommand implements BuiltinCommand
{
    /** @var null|Closure(): ?\Bedriox\Api\Update\UpdateInfo */
    private ?Closure $latestUpdate;

    /** @param null|Closure(): ?\Bedriox\Api\Update\UpdateInfo $latestUpdate */
    public function __construct(?Closure $latestUpdate = null)
    {
        $this->latestUpdate = $latestUpdate;
    }

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('version', 'Shows Bedriox and protocol version information.', aliases: ['ver']);
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::none();
    }

    public function execute(CommandContext $context): CommandResult
    {
        $build = BuildInfo::current();
        $context->sender()->sendMessage(CommandFeedback::line(
            $context->sender(),
            TextFormat::GREEN,
            "This server is running Bedriox version {$build->serverVersion} (protocol {$build->protocolVersion}).",
        ));
        $context->sender()->sendMessage(CommandFeedback::line(
            $context->sender(),
            TextFormat::AQUA,
            'Visit https://bedriox.com',
        ));
        $update = $this->resolveLatestUpdate();
        if ($update !== null) {
            $context->sender()->sendMessage(CommandFeedback::line(
                $context->sender(),
                TextFormat::YELLOW,
                "Bedriox {$update->version} is available: {$update->releaseUrl}",
            ));
        }

        return CommandResult::success();
    }

    private function resolveLatestUpdate(): ?UpdateInfo
    {
        $update = ($this->latestUpdate)?->__invoke();

        return $update instanceof UpdateInfo ? $update : null;
    }
}
