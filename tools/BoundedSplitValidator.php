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

namespace Bedriox\Tools;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class BoundedSplitValidator
{
    /** @param list<string> $directories
     *  @return list<string>
     */
    public static function validateDirectories(array $directories): array
    {
        $errors = [];
        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                $errors[] = "Bounded-split source directory is missing: {$directory}";
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $source = file_get_contents($file->getPathname());
                if (!is_string($source)) {
                    $errors[] = 'Unable to inspect bounded splits in ' . $file->getPathname();
                    continue;
                }
                array_push($errors, ...self::validateSource($source, $file->getPathname()));
            }
        }

        sort($errors, SORT_STRING);
        return $errors;
    }

    /** @return list<string> */
    public static function validateSource(string $source, string $label): array
    {
        $tokens = token_get_all($source);
        $errors = [];
        $line = 1;
        foreach ($tokens as $index => $token) {
            if (is_array($token)) {
                $line = $token[2];
                if ($token[0] !== T_STRING || strtolower($token[1]) !== 'explode') {
                    continue;
                }
            } else {
                continue;
            }
            $previous = self::significant($tokens, $index, -1);
            if (is_array($previous) && in_array($previous[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }
            $opening = self::significantIndex($tokens, $index, 1);
            if ($opening === null || $tokens[$opening] !== '(') {
                continue;
            }
            if (self::argumentCount($tokens, $opening) < 3) {
                $errors[] = "{$label}:{$line} explode() must specify an explicit split limit.";
            }
        }

        return $errors;
    }

    /** @param list<array{int, string, int}|string> $tokens */
    private static function argumentCount(array $tokens, int $opening): int
    {
        $parentheses = 1;
        $brackets = 0;
        $braces = 0;
        $commas = 0;
        $hasValue = false;
        for ($index = $opening + 1, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            $text = is_array($token) ? $token[1] : $token;
            if ($text === '(') {
                ++$parentheses;
            } elseif ($text === ')') {
                if (--$parentheses === 0) {
                    return $hasValue ? $commas + 1 : 0;
                }
            } elseif ($text === '[') {
                ++$brackets;
            } elseif ($text === ']') {
                --$brackets;
            } elseif ($text === '{') {
                ++$braces;
            } elseif ($text === '}') {
                --$braces;
            } elseif ($text === ',' && $parentheses === 1 && $brackets === 0 && $braces === 0) {
                ++$commas;
            }
            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $hasValue = true;
            }
        }

        return 0;
    }

    /** @param list<array{int, string, int}|string> $tokens
     *  @return array{int, string, int}|string|null
     */
    private static function significant(array $tokens, int $index, int $direction): array|string|null
    {
        $found = self::significantIndex($tokens, $index, $direction);
        return $found === null ? null : $tokens[$found];
    }

    /** @param list<array{int, string, int}|string> $tokens */
    private static function significantIndex(array $tokens, int $index, int $direction): ?int
    {
        for ($index += $direction; isset($tokens[$index]); $index += $direction) {
            $token = $tokens[$index];
            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $index;
            }
        }

        return null;
    }
}
