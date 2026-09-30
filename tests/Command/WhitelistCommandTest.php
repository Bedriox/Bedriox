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

namespace Bedriox\Server\Tests\Command;

use Bedriox\Server\Access\WhitelistManager;
use Bedriox\Server\Command\Default\WhitelistCommand;
use PHPUnit\Framework\TestCase;

final class WhitelistCommandTest extends TestCase
{
    public function testSchemaRetainsEveryWhitelistAction(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-whitelist-command-' . bin2hex(random_bytes(8)) . '.json';
        try {
            $command = new WhitelistCommand(new WhitelistManager($path, false, static function (bool $enabled): void {}));
            self::assertSame([
                '/whitelist',
                '/whitelist status',
                '/whitelist on',
                '/whitelist off',
                '/whitelist list',
                '/whitelist reload',
                '/whitelist add <player>',
                '/whitelist remove <player>',
            ], $command->defineArguments()->usage('whitelist'));
        } finally {
            @unlink($path);
        }
    }
}
