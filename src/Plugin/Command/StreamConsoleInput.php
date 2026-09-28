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

namespace Bedriox\Server\Plugin\Command;

use Bedriox\Server\Observability\ServerLogger;

final class StreamConsoleInput implements ConsoleInput
{
    /** @var resource|null */
    private $stream;
    private string $buffer = '';
    private bool $discardingOversizedLine = false;

    /** @param resource $stream */
    public function __construct(
        $stream,
        private readonly ServerLogger $logger,
        private readonly int $maximumLineBytes = 1024,
        private readonly int $maximumLinesPerPoll = 64,
    ) {
        if (!is_resource($stream) || $maximumLineBytes < 1 || $maximumLineBytes > 65536
            || $maximumLinesPerPoll < 1 || $maximumLinesPerPoll > 1024) {
            throw new \InvalidArgumentException('Invalid console input configuration.');
        }
        $this->stream = $stream;
        @stream_set_blocking($stream, false);
    }

    public function readAvailable(): array
    {
        if (!is_resource($this->stream)) {
            return [];
        }
        $chunk = @fread($this->stream, 8192);
        if (is_string($chunk) && $chunk !== '') {
            $this->buffer .= $chunk;
        }
        $lines = [];
        while (count($lines) < $this->maximumLinesPerPoll && ($newline = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $newline);
            $this->buffer = substr($this->buffer, $newline + 1);
            if ($this->discardingOversizedLine || strlen($line) > $this->maximumLineBytes) {
                $this->discardingOversizedLine = false;
                $this->logger->warning('Discarded a console command exceeding the 1024-byte limit', 'Command');
                continue;
            }
            $line = rtrim($line, "\r");
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        if (strlen($this->buffer) > $this->maximumLineBytes) {
            $this->buffer = '';
            $this->discardingOversizedLine = true;
        }

        return $lines;
    }

    public function close(): void
    {
        $this->stream = null;
        $this->buffer = '';
    }
}
