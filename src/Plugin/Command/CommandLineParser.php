<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Command;

use Bedriox\Server\Plugin\PluginException;

final class CommandLineParser
{
    /** @return list<string> */
    public function parse(string $line, int $maximumArguments = 64): array
    {
        if ($line === '' || strlen($line) > 1024 || preg_match('//u', $line) !== 1 || str_contains($line, "\0")) {
            throw new PluginException('Command line is empty, malformed, or exceeds 1024 bytes.');
        }
        $tokens = [];
        $current = '';
        $quote = null;
        $preserveQuote = false;
        $structuredDepth = 0;
        $escaped = false;
        foreach (preg_split('//u', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if ($escaped) {
                if ($structuredDepth > 0 || ($character !== '\\' && $character !== '"' && $character !== "'")) {
                    $current .= '\\';
                }
                $current .= $character;
                $escaped = false;
                continue;
            }
            if ($character === '\\') {
                $escaped = true;
                continue;
            }
            if ($quote !== null) {
                if ($character === $quote) {
                    if ($preserveQuote) {
                        $current .= $character;
                    }
                    $quote = null;
                    $preserveQuote = false;
                } else {
                    $current .= $character;
                }
                continue;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;
                $preserveQuote = $current !== '' || $structuredDepth > 0;
                if ($preserveQuote) {
                    $current .= $character;
                }
            } elseif (preg_match('/\s/u', $character) === 1) {
                if ($structuredDepth > 0) {
                    $current .= $character;
                } elseif ($current !== '') {
                    $tokens[] = $current;
                    $current = '';
                }
            } else {
                $current .= $character;
                if ($character === '{' || $character === '[') {
                    ++$structuredDepth;
                } elseif (($character === '}' || $character === ']') && $structuredDepth > 0) {
                    --$structuredDepth;
                }
            }
            if (count($tokens) > $maximumArguments) {
                throw new PluginException('Command argument limit exceeded.');
            }
        }
        if ($escaped) {
            $current .= '\\';
        }
        if ($quote !== null) {
            throw new PluginException('Command contains an unterminated quote.');
        }
        if ($current !== '') {
            $tokens[] = $current;
        }
        if ($tokens === [] || count($tokens) - 1 > $maximumArguments) {
            throw new PluginException('Command line has no command or exceeds its argument limit.');
        }

        return $tokens;
    }
}
