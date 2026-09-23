<?php

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

# World and gameplay
level-name=world
level-type=default
level-seed=0
gamemode=survival
difficulty=normal
pvp=true
view-distance=4
PROPERTIES
            . PHP_EOL;
    }

    protected function defaultContents(): string
    {
        return self::defaults();
    }
}
