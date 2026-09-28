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

use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Server\Permission\PermissionStore;
use Closure;

final readonly class DeopCommand extends OperatorCommand
{
    public function __construct(PermissionStore $permissions, ?Closure $authorityChanged = null)
    {
        parent::__construct($permissions, false, $authorityChanged);
    }

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('deop', 'Removes operator authority from an online player.', permission: 'bedriox.command.op');
    }
}
