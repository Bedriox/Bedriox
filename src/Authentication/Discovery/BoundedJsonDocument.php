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

namespace Bedriox\Server\Authentication\Discovery;

use Bedriox\Protocol\Security\BoundedJson;
use Throwable;

final class BoundedJsonDocument
{
    /** @return array<string, mixed> */
    public static function decode(string $body, int $maximumBytes, DiscoveryLimits $limits): array
    {
        if (strlen($body) > $maximumBytes || preg_match('//u', $body) !== 1) {
            throw new DiscoveryException('Discovery JSON is oversized or not UTF-8.');
        }
        try {
            $value = BoundedJson::decodeObject($body, $maximumBytes, $limits->maximumJsonDepth);
        } catch (Throwable) {
            throw new DiscoveryException('Discovery JSON is invalid.');
        }
        if (self::tokenCount($body) > $limits->maximumJsonTokens) {
            throw new DiscoveryException('Discovery JSON exceeds its token limit.');
        }
        return $value;
    }

    private static function tokenCount(string $json): int
    {
        $count = 0;
        $length = strlen($json);
        $inString = false;
        for ($offset = 0; $offset < $length; ++$offset) {
            $character = $json[$offset];
            if ($inString) {
                if ($character === '\\') {
                    ++$offset;
                } elseif ($character === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($character === '"') {
                $inString = true;
                ++$count;
            } elseif (str_contains('{}[],:', $character)) {
                ++$count;
            } elseif (!str_contains(" \t\r\n", $character)) {
                ++$count;
                while ($offset + 1 < $length && !str_contains("{}[],: \t\r\n", $json[$offset + 1])) {
                    ++$offset;
                }
            }
        }
        return $count;
    }
}
