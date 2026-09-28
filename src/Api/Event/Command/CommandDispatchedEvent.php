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

namespace Bedriox\Api\Event\Command;

use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\Command\CommandSender;
use Bedriox\Api\Command\CommandValues;
use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;

final class CommandDispatchedEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly CommandSender $sender,
        public readonly string $command,
        public readonly CommandValues $values,
        public readonly string $owner,
        public readonly CommandResult $result,
    ) {}
}
