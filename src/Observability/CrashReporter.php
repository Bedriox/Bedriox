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

namespace Bedriox\Server\Observability;

use Bedriox\Server\Bedriox;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

final readonly class CrashReporter
{
    private const int MAXIMUM_PLAYERS = 1_024;
    private const int MAXIMUM_TRACE_BYTES = 65_536;

    public function __construct(
        private string $directory,
        private ServerLogger $logger,
        private bool $includePlayerIdentifiers = true,
        private ?string $privateRoot = null,
    ) {}

    public function report(Throwable $failure, ?CrashContext $context = null): string
    {
        return $this->write(
            $failure::class,
            $failure->getMessage(),
            $failure->getFile(),
            $failure->getLine(),
            $failure->getTraceAsString(),
            $context ?? new CrashContext(),
        );
    }

    /** @param array{type?: int, message?: string, file?: string, line?: int} $fatal */
    public function reportFatal(array $fatal, ?CrashContext $context = null): string
    {
        return $this->write(
            'PHP fatal error ' . ($fatal['type'] ?? E_ERROR),
            $fatal['message'] ?? 'Unknown fatal error',
            $fatal['file'] ?? 'unknown',
            $fatal['line'] ?? 0,
            '[fatal shutdown; no throwable stack trace available]',
            $context ?? new CrashContext(),
        );
    }

    private function write(string $type, string $message, string $file, int $line, string $trace, CrashContext $context): string
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create the crash report directory.');
        }
        $timestamp = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $path = $this->uniquePath($timestamp->format('Y-m-d_H-i-s_UTC'));
        $contents = $this->render($timestamp, $type, $message, $file, $line, $trace, $context);
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        $handle = @fopen($temporary, 'x');
        if ($handle === false) {
            throw new RuntimeException('Unable to create a temporary crash report.');
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
                throw new RuntimeException('Unable to write the crash report.');
            }
        } finally {
            fclose($handle);
        }
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to publish the crash report atomically.');
        }

        return $path;
    }

    private function render(DateTimeImmutable $timestamp, string $type, string $message, string $file, int $line, string $trace, CrashContext $context): string
    {
        $memory = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        $lines = [
            'BEDRIOX CRASH REPORT - SENSITIVE LOCAL DIAGNOSTIC',
            'Do not publish this report without reviewing player identifiers and network addresses.',
            '',
            'Time (UTC): ' . $timestamp->format(DATE_ATOM),
            'Bedriox: ' . Bedriox::VERSION,
            'PHP: ' . PHP_VERSION,
            'Operating system: ' . PHP_OS_FAMILY . ' (' . php_uname('m') . ')',
            'Uptime/tick: tick ' . $context->tick,
            sprintf('Memory: %d bytes current, %d bytes peak', $memory, $peak),
            'Player count: ' . count($context->players),
            'Plugin attribution: ' . ($context->pluginAttribution ?? 'none'),
            '',
            'Failure: ' . $this->sanitize($type),
            'Message: ' . $this->sanitize($message),
            'Location: ' . $this->sanitizePath($file) . ':' . $line,
            '',
            'Stack trace:',
            $this->sanitize(substr($trace, 0, self::MAXIMUM_TRACE_BYTES)),
            '',
            'Involved player/session:',
        ];
        $lines = [...$lines, ...$this->playerLines($context->involvedPlayer)];
        $lines[] = '';
        $lines[] = 'Connected players:';
        if ($context->players === []) {
            $lines[] = '- none recorded';
        } else {
            foreach (array_slice($context->players, 0, self::MAXIMUM_PLAYERS) as $player) {
                $lines = [...$lines, ...$this->playerLines($player)];
            }
        }
        $lines[] = '';
        $lines[] = 'Recent operational log:';
        foreach ($this->logger->recentLines() as $recent) {
            $lines[] = $this->sanitize($recent);
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /** @return list<string> */
    private function playerLines(?CrashPlayer $player): array
    {
        if ($player === null) {
            return ['- none recorded'];
        }
        if (!$this->includePlayerIdentifiers) {
            return ['- identifiers disabled; phase=' . $this->safeField($player->sessionPhase)];
        }

        return [sprintf(
            '- name=%s uuid=%s xuid=%s remote=%s platform=%s phase=%s',
            $this->safeField($player->name),
            $this->safeField($player->uuid),
            $this->safeField($player->xuid),
            $this->safeField($player->remoteAddress),
            $this->safeField($player->platform),
            $this->safeField($player->sessionPhase),
        )];
    }

    private function safeField(string $value): string
    {
        $value = preg_replace('/[\x00-\x1f\x7f]/', '', $value) ?? '';
        return substr($value, 0, 256);
    }

    private function sanitizePath(string $path): string
    {
        if ($this->privateRoot !== null && $this->privateRoot !== '') {
            $path = str_ireplace(str_replace('\\', '/', $this->privateRoot), '{server}', str_replace('\\', '/', $path));
        }
        return $this->sanitize($path);
    }

    private function sanitize(string $value): string
    {
        $value = (new LogRedactor())->redact($value);
        if ($this->privateRoot !== null && $this->privateRoot !== '') {
            $value = str_ireplace(
                [str_replace('\\', '/', $this->privateRoot), str_replace('/', '\\', $this->privateRoot)],
                '{server}',
                $value,
            );
        }
        return $value;
    }

    private function uniquePath(string $stem): string
    {
        for ($suffix = 1; $suffix <= 10_000; ++$suffix) {
            $candidate = $this->directory . DIRECTORY_SEPARATOR . $stem . ($suffix === 1 ? '' : '-' . $suffix) . '.txt';
            if (!file_exists($candidate)) {
                return $candidate;
            }
        }
        throw new RuntimeException('Unable to allocate a unique crash report name.');
    }
}
