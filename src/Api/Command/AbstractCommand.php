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

namespace Bedriox\Api\Command;

abstract class AbstractCommand implements Command
{
    public function __construct(
        private readonly string $name,
        private readonly string $description,
    ) {}

    final public function definition(): CommandDefinition
    {
        return new CommandDefinition(
            $this->name,
            $this->description,
            $this->aliases(),
            $this->permission(),
            $this->allowedSenders(),
        );
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::none();
    }

    /** @return list<string> */
    protected function aliases(): array
    {
        return [];
    }

    protected function permission(): ?string
    {
        return null;
    }

    protected function allowedSenders(): AllowedCommandSenders
    {
        return AllowedCommandSenders::ANY;
    }

    final protected function success(?string $message = null): CommandResult
    {
        return CommandResult::success($message);
    }

    final protected function failure(string $message): CommandResult
    {
        return CommandResult::failure($message);
    }
}
