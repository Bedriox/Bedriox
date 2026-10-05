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

use Bedriox\Api\Player\GameMode;
use Bedriox\Server\Login\AuthenticationMode;
use Bedriox\Server\Observability\LogLevel;
use Bedriox\Server\Worker\WorkerCoreCount;
use InvalidArgumentException;

final readonly class ServerConfig
{
    private const array DEFAULTS = [
        'server-name' => 'Bedriox Server',
        'motd' => 'Powered by Bedriox',
        'server-ip' => '0.0.0.0',
        'server-port' => '19132',
        'max-players' => '20',
        'memory-limit' => '500MB',
        'xbox-auth' => 'true',
        'enable-console' => 'true',
        'enable-plugins' => 'true',
        'white-list' => 'false',
        'level-name' => 'world',
        'level-type' => 'default',
        'level-seed' => '',
        'gamemode' => 'survival',
        'difficulty' => 'normal',
        'pvp' => 'true',
        'spawn-animals' => 'true',
        'spawn-monsters' => 'true',
        'view-distance' => '4',
        'network.authentication' => 'FULL',
        'level.spawn-x' => '',
        'level.spawn-y' => '',
        'level.spawn-z' => '',
        'runtime.ticks-per-second' => '20',
        'workers.core-count' => 'auto',
        'chunk-sending.spawn-radius' => '4',
        'chunk-sending.per-tick' => '8',
        'chunk-generation.per-tick' => '4',
        'chunk-generation.queue-size' => '1024',
        'chunk-loading.prefetch-radius' => '1',
        'chunk-cache.limit' => 'auto',
        'chunk-saving.per-tick' => '8',
        'chunk-unloading.grace-ticks' => '600',
        'chunk-unloading.per-tick' => '96',
        'memory-management.enabled' => 'true',
        'memory-management.soft-threshold' => '70',
        'memory-management.high-threshold' => '85',
        'memory-management.critical-threshold' => '92',
        'level.autosave-interval-ticks' => '6000',
        'players.autosave-interval-ticks' => '6000',
        'players.save-per-tick' => '8',
        'movement.rewind-history-size' => '40',
        'entities.ai.enabled' => 'true',
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

    private const array SERVER_PROPERTY_KEYS = [
        'server-name' => true,
        'motd' => true,
        'server-ip' => true,
        'server-port' => true,
        'max-players' => true,
        'memory-limit' => true,
        'xbox-auth' => true,
        'enable-console' => true,
        'enable-plugins' => true,
        'white-list' => true,
        'level-name' => true,
        'level-type' => true,
        'level-seed' => true,
        'gamemode' => true,
        'difficulty' => true,
        'pvp' => true,
        'spawn-animals' => true,
        'spawn-monsters' => true,
        'view-distance' => true,
    ];

    private const array ADVANCED_SETTING_KEYS = [
        'runtime.ticks-per-second' => true,
        'workers.core-count' => true,
        'chunk-sending.spawn-radius' => true,
        'chunk-sending.per-tick' => true,
        'chunk-generation.per-tick' => true,
        'chunk-generation.queue-size' => true,
        'chunk-loading.prefetch-radius' => true,
        'chunk-cache.limit' => true,
        'chunk-saving.per-tick' => true,
        'chunk-unloading.grace-ticks' => true,
        'chunk-unloading.per-tick' => true,
        'memory-management.enabled' => true,
        'memory-management.soft-threshold' => true,
        'memory-management.high-threshold' => true,
        'memory-management.critical-threshold' => true,
        'level.autosave-interval-ticks' => true,
        'players.autosave-interval-ticks' => true,
        'players.save-per-tick' => true,
        'movement.rewind-history-size' => true,
        'entities.ai.enabled' => true,
        'plugins.maximum' => true,
        'logging.level' => true,
        'logging.console' => true,
        'logging.console-colors' => true,
        'logging.file' => true,
        'logging.file-max-size' => true,
        'logging.file-history' => true,
        'logging.protocol-trace' => true,
        'crash-report.include-player-identifiers' => true,
    ];

    private const array CLI_KEYS = [
        'bind' => 'server-ip',
        'port' => 'server-port',
        'name' => 'server-name',
        'motd' => 'motd',
        'max-players' => 'max-players',
        'memory-limit' => 'memory-limit',
        'auth' => 'network.authentication',
        'level-name' => 'level-name',
        'generator' => 'level-type',
        'seed' => 'level-seed',
        'default-gamemode' => 'gamemode',
        'difficulty' => 'difficulty',
        'pvp' => 'pvp',
        'spawn-animals' => 'spawn-animals',
        'spawn-monsters' => 'spawn-monsters',
        'level-autosave-interval-ticks' => 'level.autosave-interval-ticks',
        'view-distance' => 'view-distance',
        'spawn-radius' => 'chunk-sending.spawn-radius',
        'chunks-send-per-tick' => 'chunk-sending.per-tick',
        'chunks-generate-per-tick' => 'chunk-generation.per-tick',
        'chunk-generation-queue-size' => 'chunk-generation.queue-size',
        'chunk-loading-prefetch-radius' => 'chunk-loading.prefetch-radius',
        'chunks-cache-limit' => 'chunk-cache.limit',
        'chunks-save-per-tick' => 'chunk-saving.per-tick',
        'players-autosave-interval-ticks' => 'players.autosave-interval-ticks',
        'players-save-per-tick' => 'players.save-per-tick',
        'movement-rewind-history-size' => 'movement.rewind-history-size',
        'ticks-per-second' => 'runtime.ticks-per-second',
        'workers' => 'workers.core-count',
        'console-enabled' => 'enable-console',
        'plugins-enabled' => 'enable-plugins',
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
        public string $levelGenerator = 'default',
        public int $levelSeed = 0,
        public string $defaultGamemode = 'survival',
        public string $difficulty = 'normal',
        public bool $pvp = true,
        public bool $spawnAnimals = true,
        public bool $spawnMonsters = true,
        public int $levelAutosaveIntervalTicks = 6_000,
        public int $viewDistance = 4,
        public int $spawnRadius = 4,
        public int $chunksSendPerTick = 8,
        public int $chunksGeneratePerTick = 4,
        public int $chunkCacheLimit = 4_096,
        public int $chunksSavePerTick = 8,
        public int $playersAutosaveIntervalTicks = 6_000,
        public int $playersSavePerTick = 8,
        public int $movementRewindHistorySize = 40,
        public int $ticksPerSecond = 20,
        public int $workerCoreCount = 1,
        public bool $consoleEnabled = true,
        public bool $pluginsEnabled = true,
        public bool $whitelistEnabled = false,
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
        public int $memoryLimitBytes = 500_000_000,
        public int $chunkGenerationQueueSize = 1_024,
        public int $chunkLoadingPrefetchRadius = 1,
        public int $chunkUnloadGraceTicks = 600,
        public int $chunkUnloadPerTick = 96,
        public bool $memoryManagementEnabled = true,
        public int $memorySoftThreshold = 70,
        public int $memoryHighThreshold = 85,
        public int $memoryCriticalThreshold = 92,
        public bool $entityAiEnabled = true,
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
        if (!in_array($this->levelGenerator, ['default', 'flat'], true)) {
            throw new InvalidArgumentException('Level generator must be exactly default or flat.');
        }
        if (GameMode::tryFrom($this->defaultGamemode) === null) {
            throw new InvalidArgumentException('Default gamemode is unsupported.');
        }
        if (!in_array($this->difficulty, ['peaceful', 'easy', 'normal', 'hard'], true)) {
            throw new InvalidArgumentException('Difficulty is unsupported.');
        }
        self::range($this->levelAutosaveIntervalTicks, 20, 72_000, 'Level autosave interval');
        self::range($this->viewDistance, 1, 32, 'View distance');
        self::range($this->spawnRadius, 1, $this->viewDistance, 'Spawn radius');
        self::range($this->chunksSendPerTick, 1, 64, 'Chunks sent per tick');
        self::range($this->chunksGeneratePerTick, 1, 64, 'Chunks generated per tick');
        self::range($this->chunkGenerationQueueSize, 1, 65_536, 'Chunk generation queue size');
        self::range($this->chunkLoadingPrefetchRadius, 0, 8, 'Chunk loading prefetch radius');
        if ($this->viewDistance + $this->chunkLoadingPrefetchRadius > 32) {
            throw new InvalidArgumentException('View distance plus chunk loading prefetch radius must not exceed 32.');
        }
        self::range($this->chunkCacheLimit, 16, 65_536, 'Chunk cache limit');
        self::range($this->chunksSavePerTick, 1, 64, 'Chunks saved per tick');
        self::range($this->chunkUnloadGraceTicks, 0, 6_000, 'Chunk unload grace period');
        self::range($this->chunkUnloadPerTick, 1, 1_024, 'Chunks unloaded per tick');
        self::range($this->memorySoftThreshold, 1, 98, 'Memory soft threshold');
        self::range($this->memoryHighThreshold, 2, 99, 'Memory high threshold');
        self::range($this->memoryCriticalThreshold, 3, 100, 'Memory critical threshold');
        if ($this->memorySoftThreshold >= $this->memoryHighThreshold
            || $this->memoryHighThreshold >= $this->memoryCriticalThreshold) {
            throw new InvalidArgumentException('Memory thresholds must be strictly increasing.');
        }
        self::range($this->playersAutosaveIntervalTicks, 20, 72_000, 'Player autosave interval');
        self::range($this->playersSavePerTick, 1, 64, 'Players saved per tick');
        self::range($this->movementRewindHistorySize, 1, 1_200, 'Movement rewind history size');
        self::range($this->ticksPerSecond, 1, 100, 'Ticks per second');
        self::range($this->workerCoreCount, 0, 32, 'Core worker count');
        self::range($this->maximumPlugins, 0, 256, 'Maximum plugins');
        if (!in_array($this->loggingConsoleColors, ['auto', 'true', 'false'], true)) {
            throw new InvalidArgumentException('Console colors must be exactly auto, true, or false.');
        }
        self::range($this->loggingFileMaxSize, 65_536, 1_073_741_824, 'Log file maximum size');
        self::range($this->loggingFileHistory, 0, 100, 'Log file history');
        if ($this->memoryLimitBytes !== 0
            && ($this->memoryLimitBytes < 128_000_000 || $this->memoryLimitBytes > 68_719_476_736)) {
            throw new InvalidArgumentException('Memory limit must be unlimited or between 128MB and 64GiB.');
        }
        $retainedRadius = $this->viewDistance + $this->chunkLoadingPrefetchRadius;
        $retainedDiameter = 2 * $retainedRadius + 1;
        $maximumRetainedChunks = $this->maximumPlayers * $retainedDiameter * $retainedDiameter + 5;
        if ($maximumRetainedChunks > 65_536 || $this->chunkCacheLimit < $maximumRetainedChunks) {
            throw new InvalidArgumentException('Chunk cache limit must hold every configured player view, the world spawn, and four transition chunks within 65536 chunks.');
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
        return self::fromValues([], [], $arguments);
    }

    /** @param list<string> $arguments */
    public static function fromConfigurationFiles(string $serverPropertiesPath, string $advancedSettingsPath, array $arguments): self
    {
        return self::fromValues(
            (new ServerPropertiesFile())->loadOrCreate($serverPropertiesPath),
            (new ServerSettingsFile())->loadOrCreate($advancedSettingsPath),
            $arguments,
        );
    }

    /**
     * @param array<string, string> $serverProperties
     * @param array<string, string> $advancedSettings
     * @param list<string>          $arguments
     */
    private static function fromValues(array $serverProperties, array $advancedSettings, array $arguments): self
    {
        self::rejectUnknown($serverProperties, self::SERVER_PROPERTY_KEYS, 'server property');
        self::rejectUnknown($advancedSettings, self::ADVANCED_SETTING_KEYS, 'advanced setting');
        $values = array_replace(self::DEFAULTS, $serverProperties, $advancedSettings);
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
        $mode = isset($seen['auth'])
            ? match ($values['network.authentication']) {
                'FULL' => AuthenticationMode::FULL,
                'SELF_SIGNED' => AuthenticationMode::SELF_SIGNED,
                default => throw new InvalidArgumentException('Authentication must be exactly FULL or SELF_SIGNED.'),
            }
        : (self::boolean($values['xbox-auth'], 'xbox-auth')
            ? AuthenticationMode::FULL
            : AuthenticationMode::SELF_SIGNED);

        $spawn = [];
        foreach (['level.spawn-x', 'level.spawn-y', 'level.spawn-z'] as $key) {
            $spawn[] = $values[$key] === '' ? null : self::integer($values[$key], $key, -30_000_000, 30_000_000, true);
        }

        $maximumPlayers = self::integer($values['max-players'], 'max-players', 1, 1_024);
        $viewDistance = self::integer($values['view-distance'], 'view-distance', 1, 32);
        $chunkLoadingPrefetchRadius = self::integer(
            $values['chunk-loading.prefetch-radius'],
            'chunk-loading.prefetch-radius',
            0,
            8,
        );
        if ($viewDistance + $chunkLoadingPrefetchRadius > 32) {
            throw new InvalidArgumentException('View distance plus chunk loading prefetch radius must not exceed 32.');
        }
        $viewDiameter = 2 * ($viewDistance + $chunkLoadingPrefetchRadius) + 1;
        $maximumRetainedChunks = $maximumPlayers * $viewDiameter * $viewDiameter + 5;
        $chunkCacheLimit = $values['chunk-cache.limit'] === 'auto'
            ? max(2_048, $maximumRetainedChunks)
            : self::integer($values['chunk-cache.limit'], 'chunk-cache.limit', 16, 65_536);

        return new self(
            bindAddress: $values['server-ip'],
            port: self::integer($values['server-port'], 'server-port', 1, 65_535),
            serverName: $values['server-name'],
            maximumPlayers: $maximumPlayers,
            authenticationMode: $mode,
            motd: $values['motd'],
            levelName: $values['level-name'],
            levelGenerator: $values['level-type'],
            levelSeed: self::seed($values['level-seed']),
            defaultGamemode: $values['gamemode'],
            difficulty: $values['difficulty'],
            pvp: self::boolean($values['pvp'], 'pvp'),
            spawnAnimals: self::boolean($values['spawn-animals'], 'spawn-animals'),
            spawnMonsters: self::boolean($values['spawn-monsters'], 'spawn-monsters'),
            levelAutosaveIntervalTicks: self::integer($values['level.autosave-interval-ticks'], 'level.autosave-interval-ticks', 20, 72_000),
            viewDistance: $viewDistance,
            spawnRadius: self::integer($values['chunk-sending.spawn-radius'], 'chunk-sending.spawn-radius', 1, 32),
            chunksSendPerTick: self::integer($values['chunk-sending.per-tick'], 'chunk-sending.per-tick', 1, 64),
            chunksGeneratePerTick: self::integer($values['chunk-generation.per-tick'], 'chunk-generation.per-tick', 1, 64),
            chunkCacheLimit: $chunkCacheLimit,
            chunksSavePerTick: self::integer($values['chunk-saving.per-tick'], 'chunk-saving.per-tick', 1, 64),
            playersAutosaveIntervalTicks: self::integer($values['players.autosave-interval-ticks'], 'players.autosave-interval-ticks', 20, 72_000),
            playersSavePerTick: self::integer($values['players.save-per-tick'], 'players.save-per-tick', 1, 64),
            movementRewindHistorySize: self::integer(
                $values['movement.rewind-history-size'],
                'movement.rewind-history-size',
                1,
                1_200,
            ),
            ticksPerSecond: self::integer($values['runtime.ticks-per-second'], 'runtime.ticks-per-second', 1, 100),
            workerCoreCount: WorkerCoreCount::parse($values['workers.core-count']),
            consoleEnabled: self::boolean($values['enable-console'], 'enable-console'),
            pluginsEnabled: self::boolean($values['enable-plugins'], 'enable-plugins'),
            whitelistEnabled: self::boolean($values['white-list'], 'white-list'),
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
            memoryLimitBytes: ProcessMemoryLimit::parse($values['memory-limit']),
            chunkGenerationQueueSize: self::integer(
                $values['chunk-generation.queue-size'],
                'chunk-generation.queue-size',
                1,
                65_536,
            ),
            chunkLoadingPrefetchRadius: $chunkLoadingPrefetchRadius,
            chunkUnloadGraceTicks: self::integer(
                $values['chunk-unloading.grace-ticks'],
                'chunk-unloading.grace-ticks',
                0,
                6_000,
            ),
            chunkUnloadPerTick: self::integer(
                $values['chunk-unloading.per-tick'],
                'chunk-unloading.per-tick',
                1,
                1_024,
            ),
            memoryManagementEnabled: self::boolean($values['memory-management.enabled'], 'memory-management.enabled'),
            memorySoftThreshold: self::integer(
                $values['memory-management.soft-threshold'],
                'memory-management.soft-threshold',
                1,
                98,
            ),
            memoryHighThreshold: self::integer(
                $values['memory-management.high-threshold'],
                'memory-management.high-threshold',
                2,
                99,
            ),
            memoryCriticalThreshold: self::integer(
                $values['memory-management.critical-threshold'],
                'memory-management.critical-threshold',
                3,
                100,
            ),
            entityAiEnabled: self::boolean($values['entities.ai.enabled'], 'entities.ai.enabled'),
        );
    }

    /**
     * @param array<string, string> $values
     * @param array<string, true>   $allowed
     */
    private static function rejectUnknown(array $values, array $allowed, string $kind): void
    {
        $unknown = array_diff_key($values, $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf('Unknown %s "%s".', $kind, array_key_first($unknown)));
        }
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

    /** Resolves an empty seed once while preserving every explicit signed 64-bit value, including zero. */
    private static function seed(string $value): int
    {
        if ($value === '') {
            return random_int(PHP_INT_MIN, PHP_INT_MAX);
        }

        return self::integer($value, 'level-seed', PHP_INT_MIN, PHP_INT_MAX, true);
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
