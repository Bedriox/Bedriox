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

use Closure;
use RuntimeException;

/** Transactional first-run configuration wizard. */
final readonly class SetupWizard
{
    /** @param Closure(string): void $output @param Closure(): (string|null) $readLine */
    public function __construct(private Closure $output, private Closure $readLine) {}

    public function run(string $propertiesPath, string $whitelistPath): void
    {
        ($this->output)("Bedriox is free and open-source software licensed under GPL-3.0-only.\n"
            . "Copyright (C) 2026 Veno Ninja LLC.\n"
            . "License: https://github.com/Bedriox/Bedriox/blob/main/LICENSE\n\n");
        if (!$this->yesNo('Continue with server setup?', true)) {
            throw new RuntimeException('Server setup was cancelled.');
        }

        ($this->output)("For each question, press Enter to accept the default shown in brackets.\n\n");

        $values = [
            'server-name' => $this->text('Server name', 'Bedriox Server', 128),
            'motd' => $this->text('Message of the day', 'Powered by Bedriox', 128),
            'server-port' => (string) $this->integer('Network port to bind', 19_132, 1, 65_535),
            'level-name' => $this->identifier('World name', 'world'),
            'level-type' => $this->choice('World type', ['default', 'flat'], 'default'),
            'gamemode' => $this->choice('Default gamemode', ['survival', 'creative', 'adventure', 'spectator'], 'survival'),
            'difficulty' => $this->choice('Difficulty', ['peaceful', 'easy', 'normal', 'hard'], 'normal'),
            'max-players' => (string) $this->integer('Maximum players', 20, 1, 1_024),
            'view-distance' => (string) $this->integer('View distance (chunks)', 4, 1, 32),
            'spawn-animals' => $this->yesNo('Spawn animals?', true) ? 'true' : 'false',
            'spawn-monsters' => $this->yesNo('Spawn monsters?', true) ? 'true' : 'false',
            'white-list' => $this->yesNo('Enable the whitelist?', false) ? 'true' : 'false',
        ];
        $names = [];
        if ($values['white-list'] === 'true') {
            do {
                $name = $this->text('Initial whitelisted player name', '', 64, false);
                if ($name !== '') {
                    $names[] = $name;
                }
            } while ($this->yesNo('Add another player?', false));
            if ($names === []) {
                throw new RuntimeException('An enabled whitelist requires at least one initial player.');
            }
        }

        ($this->output)("\nConfiguration summary:\n");
        foreach ($values as $key => $value) {
            ($this->output)(sprintf("  %-18s %s\n", $key . ':', $value));
        }
        if ($names !== []) {
            ($this->output)('  whitelisted:       ' . implode(', ', $names) . "\n");
        }

        $contents = ServerPropertiesFile::defaults();
        foreach ($values as $key => $value) {
            $contents = preg_replace('/^' . preg_quote($key, '/') . '=.*$/m', $key . '=' . $value, $contents, 1) ?? $contents;
        }
        self::atomicWrite($propertiesPath, $contents);
        if ($names !== []) {
            $entries = array_map(static fn(string $name): array => [
                'uuid' => null,
                'name' => $name,
                'normalized_name' => strtolower($name),
            ], $names);
            self::atomicWrite($whitelistPath, json_encode(['schema' => 1, 'entries' => $entries], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        }
        ($this->output)("\nSetup complete. Plugins and documentation are available at https://bedriox.com\n"
            . "Only install plugins from sources you trust.\n"
            . "Preparing Bedriox, please wait...\n\n");
    }

    public static function installDefaults(string $propertiesPath): void
    {
        self::atomicWrite($propertiesPath, ServerPropertiesFile::defaults());
    }

    private function ask(string $prompt, string $default = ''): string
    {
        ($this->output)($prompt . ($default !== '' ? " [{$default}]" : '') . ': ');
        $line = ($this->readLine)();
        if (!is_string($line)) {
            throw new RuntimeException('Server setup ended before completion. No configuration was written.');
        }
        $line = trim($line);
        return $line === '' ? $default : $line;
    }

    private function text(string $prompt, string $default, int $maximumBytes, bool $required = true): string
    {
        while (true) {
            $value = $this->ask($prompt, $default);
            if ((!$required || $value !== '') && strlen($value) <= $maximumBytes && preg_match('//u', $value) === 1 && !str_contains($value, "\0")) {
                return $value;
            }
            ($this->output)("Please enter valid text up to {$maximumBytes} bytes.\n");
        }
    }

    private function identifier(string $prompt, string $default): string
    {
        while (true) {
            $value = $this->text($prompt, $default, 64);
            if (preg_match('/^[a-zA-Z0-9_. -]+$/D', $value) === 1) {
                return $value;
            }
            ($this->output)("Use letters, numbers, spaces, dots, underscores, or hyphens.\n");
        }
    }

    /** @param list<string> $choices */
    private function choice(string $prompt, array $choices, string $default): string
    {
        while (true) {
            $value = strtolower($this->ask($prompt . ' (' . implode('/', $choices) . ')', $default));
            if (in_array($value, $choices, true)) {
                return $value;
            }
            ($this->output)("Choose one of: " . implode(', ', $choices) . ".\n");
        }
    }

    private function integer(string $prompt, int $default, int $minimum, int $maximum): int
    {
        while (true) {
            $value = $this->ask($prompt, (string) $default);
            if (preg_match('/^[0-9]+$/D', $value) === 1 && (int) $value >= $minimum && (int) $value <= $maximum) {
                return (int) $value;
            }
            ($this->output)("Enter a number from {$minimum} to {$maximum}.\n");
        }
    }

    private function yesNo(string $prompt, bool $default): bool
    {
        while (true) {
            $value = strtolower($this->ask($prompt . ($default ? ' [Y/n]' : ' [y/N]')));
            if ($value === '') {
                return $default;
            }
            if (in_array($value, ['y', 'yes'], true)) {
                return true;
            }
            if (in_array($value, ['n', 'no'], true)) {
                return false;
            }
            ($this->output)("Please answer yes or no.\n");
        }
    }

    private static function atomicWrite(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            throw new RuntimeException('Configuration directory does not exist.');
        }
        $temporary = tempnam($directory, '.bedriox-');
        if (!is_string($temporary)) {
            throw new RuntimeException('Unable to create a temporary configuration file.');
        }
        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents) || !rename($temporary, $path)) {
                throw new RuntimeException('Unable to publish the server configuration.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
