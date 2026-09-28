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

final class LogRedactor
{
    public const int MAXIMUM_MESSAGE_BYTES = 4_096;

    public function redact(string $message): string
    {
        $message = str_replace(["\r", "\n", "\0"], ['\\r ', '\\n ', ''], $message);
        $patterns = [
            '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i',
            '~\b(?:token|jwt|password|passwd|secret|credential|encryption[_ -]?key)\s*[:=]\s*[^\s,;]+~i',
            '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/',
        ];
        $message = preg_replace($patterns, '[REDACTED]', $message) ?? '[invalid log message]';

        return strlen($message) > self::MAXIMUM_MESSAGE_BYTES
            ? substr($message, 0, self::MAXIMUM_MESSAGE_BYTES) . '...'
            : $message;
    }
}
