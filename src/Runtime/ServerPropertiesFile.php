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

namespace Bedriox\Server\Runtime;

final class ServerPropertiesFile extends ServerSettingsFile
{
    public static function defaults(): string
    {
        return <<<'PROPERTIES'
# Bedriox server properties. Advanced settings belong in bedriox.settings.

# Server identity and network
server-name=Bedriox Server
motd=Powered by Bedriox
server-ip=0.0.0.0
server-port=19132
max-players=20

# Main process and access
memory-limit=500MB
xbox-auth=true
enable-console=true
enable-plugins=true
white-list=false

# World and gameplay
level-name=world
level-type=default
level-seed=
gamemode=survival
difficulty=normal
pvp=true
spawn-animals=true
spawn-monsters=true
view-distance=4
PROPERTIES
            . PHP_EOL;
    }

    protected function defaultContents(): string
    {
        return self::defaults();
    }
}
