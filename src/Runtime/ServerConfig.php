<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Server\Login\AuthenticationMode;
use Bedriox\Server\Observability\LogLevel;
use InvalidArgumentException;

final readonly class ServerConfig
{
    private const array DEFAULTS = [
        'server.name' => 'Bedriox Server',
        'server.motd' => 'Powered by Bedriox',
        'server.max-players' => '20',
        'network.bind-address' => '0.0.0.0',
        'network.port' => '19132',
        'network.authentication' => 'FULL',
        'level.name' => 'world',
        'level.generator' => 'flat',
        'level.seed' => '0',
        'level.default-gamemode' => 'survival',
        'level.difficulty' => 'normal',
        'level.spawn-x' => '',
        'level.spawn-y' => '',
        'level.spawn-z' => '',
        'chunks.view-distance' => '4',
        'chunks.spawn-radius' => '4',
        'chunks.send-per-tick' => '4',
        'chunks.generate-per-tick' => '4',
        'chunks.cache-limit' => '2048',
        'runtime.ticks-per-second' => '20',
        'plugins.enabled' => 'true',
        'plugins.maximum' => '64',
        'logging.level' => 'INFO',
        'logging.console' => 'true',
        'logging.console-colors' => 'auto',
        'logging.file' => 'true',
        'logging.file-max-size' => '16777216',
        'logging.file-history' => '10',
        'logging.protocol-trace' => 'false',
        'crash-report.include-player-identifiers' => 'true',
    ];

    private const array CLI_KEYS = [
        'bind' => 'network.bind-address',
        'port' => 'network.port',
        'name' => 'server.name',
        'motd' => 'server.motd',
        'max-players' => 'server.max-players',
        'auth' => 'network.authentication',
        'level-name' => 'level.name',
        'generator' => 'level.generator',
        'seed' => 'level.seed',
        'default-gamemode' => 'level.default-gamemode',
        'difficulty' => 'level.difficulty',
        'view-distance' => 'chunks.view-distance',
        'spawn-radius' => 'chunks.spawn-radius',
        'chunks-send-per-tick' => 'chunks.send-per-tick',
        'chunks-generate-per-tick' => 'chunks.generate-per-tick',
        'chunks-cache-limit' => 'chunks.cache-limit',
        'ticks-per-second' => 'runtime.ticks-per-second',
        'plugins-enabled' => 'plugins.enabled',
        'maximum-plugins' => 'plugins.maximum',
        'log-level' => 'logging.level',
        'log-console' => 'logging.console',
        'log-console-colors' => 'logging.console-colors',
        'log-file' => 'logging.file',
        'log-file-max-size' => 'logging.file-max-size',
        'log-file-history' => 'logging.file-history',
        'protocol-trace' => 'logging.protocol-trace',
        'crash-report-player-identifiers' => 'crash-report.include-player-identifiers',
        'spawn-x' => 'level.spawn-x',
        'spawn-y' => 'level.spawn-y',
        'spawn-z' => 'level.spawn-z',
    ];

    public function __construct(
        public string $bindAddress = '0.0.0.0',
        public int $port = 19_132,
        public string $serverName = 'Bedriox Server',
        public int $maximumPlayers = 20,
        public AuthenticationMode $authenticationMode = AuthenticationMode::FULL,
        public string $motd = 'Powered by Bedriox',
        public string $levelName = 'world',
        public string $levelGenerator = 'flat',
        public int $levelSeed = 0,
        public string $defaultGamemode = 'survival',
        public string $difficulty = 'normal',
        public int $viewDistance = 4,
        public int $spawnRadius = 4,
        public int $chunksSendPerTick = 4,
        public int $chunksGeneratePerTick = 4,
        public int $chunkCacheLimit = 2_048,
        public int $ticksPerSecond = 20,
        public bool $pluginsEnabled = true,
        public int $maximumPlugins = 64,
        public bool $protocolTrace = false,
        public ?int $spawnX = null,
        public ?int $spawnY = null,
        public ?int $spawnZ = null,
        public LogLevel $loggingLevel = LogLevel::INFO,
        public bool $loggingConsole = true,
        public string $loggingConsoleColors = 'auto',
        public bool $loggingFile = true,
        public int $loggingFileMaxSize = 16_777_216,
        public int $loggingFileHistory = 10,
        public bool $crashReportIncludePlayerIdentifiers = true,
    ) {
        if (filter_var($this->bindAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('Bind address must be a literal IPv4 address.');
        }
        if ($this->port < 1 || $this->port > 65_535) {
            throw new InvalidArgumentException('Port must be between 1 and 65535.');
        }
        if ($this->serverName === '' || strlen($this->serverName) > 128 || preg_match('//u', $this->serverName) !== 1) {
            throw new InvalidArgumentException('Server name must be valid UTF-8 between 1 and 128 bytes.');
        }
        if ($this->maximumPlayers < 1 || $this->maximumPlayers > 1_024) {
            throw new InvalidArgumentException('Maximum players must be between 1 and 1024.');
        }
        self::validateText($this->motd, 'MOTD', 128);
        self::validateText($this->levelName, 'Level name', 64);
        if ($this->levelSeed < -2_147_483_648 || $this->levelSeed > 2_147_483_647) {
            throw new InvalidArgumentException('Level seed must fit a signed 32-bit integer.');
        }
        if ($this->levelGenerator !== 'flat') {
            throw new InvalidArgumentException('Level generator must currently be exactly flat.');
        }
        if ($this->defaultGamemode !== 'survival') {
            throw new InvalidArgumentException('Default gamemode must currently be exactly survival.');
        }
        if (!in_array($this->difficulty, ['peaceful', 'easy', 'normal', 'hard'], true)) {
            throw new InvalidArgumentException('Difficulty is unsupported.');
        }
        self::range($this->viewDistance, 1, 32, 'View distance');
        self::range($this->spawnRadius, 1, $this->viewDistance, 'Spawn radius');
        self::range($this->chunksSendPerTick, 1, 64, 'Chunks sent per tick');
        self::range($this->chunksGeneratePerTick, 1, 64, 'Chunks generated per tick');
        self::range($this->chunkCacheLimit, 16, 65_536, 'Chunk cache limit');
        self::range($this->ticksPerSecond, 1, 100, 'Ticks per second');
        self::range($this->maximumPlugins, 0, 256, 'Maximum plugins');
        if (!in_array($this->loggingConsoleColors, ['auto', 'true', 'false'], true)) {
            throw new InvalidArgumentException('Console colors must be exactly auto, true, or false.');
        }
        self::range($this->loggingFileMaxSize, 65_536, 1_073_741_824, 'Log file maximum size');
        self::range($this->loggingFileHistory, 0, 100, 'Log file history');
        $maximumRetainedChunks = $this->maximumPlayers * (2 * $this->viewDistance + 1) ** 2;
        if ($maximumRetainedChunks > 65_536 || $this->chunkCacheLimit < $maximumRetainedChunks) {
            throw new InvalidArgumentException('Chunk cache limit must hold every configured player view within 65536 chunks.');
        }
        $spawnCount = (int) ($this->spawnX !== null) + (int) ($this->spawnY !== null) + (int) ($this->spawnZ !== null);
        if ($spawnCount !== 0 && $spawnCount !== 3) {
            throw new InvalidArgumentException('Spawn coordinates must be all empty or all populated.');
        }
        if ($this->spawnY !== null && ($this->spawnY < -64 || $this->spawnY > 319)) {
            throw new InvalidArgumentException('Spawn Y must be between -64 and 319.');
        }
        foreach ([$this->spawnX, $this->spawnZ] as $coordinate) {
            if ($coordinate !== null && ($coordinate < -30_000_000 || $coordinate > 30_000_000)) {
                throw new InvalidArgumentException('Spawn X and Z must be between -30000000 and 30000000.');
            }
        }
    }

    /** @param list<string> $arguments */
    public static function fromArguments(array $arguments): self
    {
        return self::fromValues([], $arguments);
    }

    /** @param list<string> $arguments */
    public static function fromSettingsFile(string $path, array $arguments): self
    {
        return self::fromValues((new ServerSettingsFile())->loadOrCreate($path), $arguments);
    }

    /**
     * @param array<string, string> $fileValues
     * @param list<string>          $arguments
     */
    private static function fromValues(array $fileValues, array $arguments): self
    {
        $unknown = array_diff_key($fileValues, self::DEFAULTS);
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf('Unknown setting "%s".', array_key_first($unknown)));
        }
        $values = array_replace(self::DEFAULTS, $fileValues);
        $seen = [];
        foreach ($arguments as $argument) {
            if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
                throw new InvalidArgumentException('Server options must use --name=value syntax.');
            }
            [$name, $value] = explode('=', substr($argument, 2), 2);
            if (!array_key_exists($name, self::CLI_KEYS) || $value === '' || isset($seen[$name])) {
                throw new InvalidArgumentException('Unknown or empty server option.');
            }
            $seen[$name] = true;
            $values[self::CLI_KEYS[$name]] = $value;
        }
        $mode = match ($values['network.authentication']) {
            'FULL' => AuthenticationMode::FULL,
            'SELF_SIGNED' => AuthenticationMode::SELF_SIGNED,
            default => throw new InvalidArgumentException('Authentication must be exactly FULL or SELF_SIGNED.'),
        };

        $spawn = [];
        foreach (['level.spawn-x', 'level.spawn-y', 'level.spawn-z'] as $key) {
            $spawn[] = $values[$key] === '' ? null : self::integer($values[$key], $key, -30_000_000, 30_000_000, true);
        }

        return new self(
            bindAddress: $values['network.bind-address'],
            port: self::integer($values['network.port'], 'network.port', 1, 65_535),
            serverName: $values['server.name'],
            maximumPlayers: self::integer($values['server.max-players'], 'server.max-players', 1, 1_024),
            authenticationMode: $mode,
            motd: $values['server.motd'],
            levelName: $values['level.name'],
            levelGenerator: $values['level.generator'],
            levelSeed: self::integer($values['level.seed'], 'level.seed', -2_147_483_648, 2_147_483_647, true),
            defaultGamemode: $values['level.default-gamemode'],
            difficulty: $values['level.difficulty'],
            viewDistance: self::integer($values['chunks.view-distance'], 'chunks.view-distance', 1, 32),
            spawnRadius: self::integer($values['chunks.spawn-radius'], 'chunks.spawn-radius', 1, 32),
            chunksSendPerTick: self::integer($values['chunks.send-per-tick'], 'chunks.send-per-tick', 1, 64),
            chunksGeneratePerTick: self::integer($values['chunks.generate-per-tick'], 'chunks.generate-per-tick', 1, 64),
            chunkCacheLimit: self::integer($values['chunks.cache-limit'], 'chunks.cache-limit', 16, 65_536),
            ticksPerSecond: self::integer($values['runtime.ticks-per-second'], 'runtime.ticks-per-second', 1, 100),
            pluginsEnabled: self::boolean($values['plugins.enabled'], 'plugins.enabled'),
            maximumPlugins: self::integer($values['plugins.maximum'], 'plugins.maximum', 0, 256),
            protocolTrace: self::boolean($values['logging.protocol-trace'], 'logging.protocol-trace'),
            spawnX: $spawn[0],
            spawnY: $spawn[1],
            spawnZ: $spawn[2],
            loggingLevel: LogLevel::parse($values['logging.level']),
            loggingConsole: self::boolean($values['logging.console'], 'logging.console'),
            loggingConsoleColors: $values['logging.console-colors'],
            loggingFile: self::boolean($values['logging.file'], 'logging.file'),
            loggingFileMaxSize: self::integer($values['logging.file-max-size'], 'logging.file-max-size', 65_536, 1_073_741_824),
            loggingFileHistory: self::integer($values['logging.file-history'], 'logging.file-history', 0, 100),
            crashReportIncludePlayerIdentifiers: self::boolean($values['crash-report.include-player-identifiers'], 'crash-report.include-player-identifiers'),
        );
    }

    private static function validateText(string $value, string $name, int $maximumBytes): void
    {
        if ($value === '' || strlen($value) > $maximumBytes || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new InvalidArgumentException(sprintf('%s must be valid non-control UTF-8 between 1 and %d bytes.', $name, $maximumBytes));
        }
    }

    private static function range(int $value, int $minimum, int $maximum, string $name): void
    {
        if ($value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException(sprintf('%s must be between %d and %d.', $name, $minimum, $maximum));
        }
    }

    private static function integer(string $value, string $name, int $minimum, int $maximum, bool $signed = false): int
    {
        $pattern = $signed ? '/\A(?:0|-?[1-9][0-9]*)\z/D' : '/\A(?:0|[1-9][0-9]*)\z/D';
        if (preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('%s must be a canonical decimal integer.', $name));
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($parsed) || $parsed < $minimum || $parsed > $maximum) {
            throw new InvalidArgumentException(sprintf('%s is outside its supported range.', $name));
        }

        return $parsed;
    }

    private static function boolean(string $value, string $name): bool
    {
        return match ($value) {
            'true' => true,
            'false' => false,
            default => throw new InvalidArgumentException(sprintf('%s must be exactly true or false.', $name)),
        };
    }
}
