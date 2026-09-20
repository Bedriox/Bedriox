<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use InvalidArgumentException;
use RuntimeException;

final class ServerSettingsFile
{
    public const int MAX_BYTES = 65_536;
    public const int MAX_LINES = 256;

    /** @return array<string, string> */
    public function loadOrCreate(string $path): array
    {
        if (!is_file($path)) {
            $this->create($path);
        }

        return $this->parse($this->read($path));
    }

    /** @return array<string, string> */
    public function parse(string $contents): array
    {
        if (strlen($contents) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Settings file exceeds the 65536-byte limit.');
        }
        if (str_contains($contents, "\0")) {
            throw new InvalidArgumentException('Settings file contains a NUL byte.');
        }

        $lines = preg_split('/\R/u', $contents);
        if (!is_array($lines)) {
            throw new InvalidArgumentException('Settings file is not valid UTF-8 text.');
        }
        if (count($lines) > self::MAX_LINES) {
            throw new InvalidArgumentException('Settings file exceeds the 256-line limit.');
        }

        $values = [];
        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                throw new InvalidArgumentException(sprintf('Malformed setting on line %d.', $index + 1));
            }
            [$key, $value] = array_map(trim(...), explode('=', $line, 2));
            if ($key === '' || preg_match('/\A[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*\z/D', $key) !== 1) {
                throw new InvalidArgumentException(sprintf('Invalid setting name on line %d.', $index + 1));
            }
            if (array_key_exists($key, $values)) {
                throw new InvalidArgumentException(sprintf('Duplicate setting "%s".', $key));
            }
            $values[$key] = $value;
        }

        return $values;
    }

    public static function defaults(): string
    {
        return <<<'SETTINGS'
# Bedriox operator settings. CLI options override values in this file.

# Server identity
server.name=Bedriox Server
server.motd=Powered by Bedriox
server.max-players=20

# Network
network.bind-address=0.0.0.0
network.port=19132
network.authentication=FULL

# Level
level.name=world
level.generator=default
level.seed=0
level.default-gamemode=survival
level.difficulty=normal
pvp=true
level.autosave-interval-ticks=6000

# Leave all three commented to use the level's calculated default spawn.
# level.spawn-x=
# level.spawn-y=
# level.spawn-z=

# Chunk generation and streaming
chunks.view-distance=4
chunks.spawn-radius=4
chunks.send-per-tick=4
chunks.generate-per-tick=1
chunks.cache-limit=2048
chunks.save-per-tick=8

# Player persistence
players.autosave-interval-ticks=6000
players.save-per-tick=8

# Runtime and diagnostics
movement.rewind-history-size=40
runtime.ticks-per-second=20
console.enabled=true

# Plugins
plugins.enabled=true
plugins.maximum=64

logging.level=INFO
logging.console=true
logging.console-colors=auto
logging.file=true
logging.file-max-size=16777216
logging.file-history=10
logging.protocol-trace=false
crash-report.include-player-identifiers=true
SETTINGS
            . PHP_EOL;
    }

    private function create(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            throw new RuntimeException('Settings directory does not exist.');
        }
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            if (is_file($path)) {
                return;
            }
            throw new RuntimeException('Unable to create the settings file.');
        }
        try {
            $contents = self::defaults();
            if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
                throw new RuntimeException('Unable to write the settings file.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function read(string $path): string
    {
        $size = @filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new RuntimeException('Settings file is unreadable or exceeds the size limit.');
        }
        $contents = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read the settings file.');
        }

        return $contents;
    }
}
