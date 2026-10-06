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

namespace Bedriox\Server\Update;

use Bedriox\Api\Update\UpdateChannel;
use InvalidArgumentException;

final readonly class SemanticVersion
{
    /** @param list<string> $prerelease */
    private function __construct(
        public string $value,
        private int $major,
        private int $minor,
        private int $patch,
        private array $prerelease,
    ) {}

    public static function parse(string $value): self
    {
        if (preg_match(
            '/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)' .
            '(?:-((?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*))?' .
            '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/D',
            $value,
            $matches,
        ) !== 1) {
            throw new InvalidArgumentException('Update version is not strict Semantic Versioning.');
        }

        return new self(
            $value,
            (int) $matches[1],
            (int) $matches[2],
            (int) $matches[3],
            isset($matches[4]) ? explode('.', $matches[4]) : [],
        );
    }

    public function channel(): UpdateChannel
    {
        if ($this->prerelease === []) {
            return UpdateChannel::STABLE;
        }
        if (strtolower($this->prerelease[0]) === 'beta') {
            return UpdateChannel::BETA;
        }

        throw new InvalidArgumentException('Bedriox update channels support stable and beta versions only.');
    }

    public function compare(self $other): int
    {
        foreach (['major', 'minor', 'patch'] as $part) {
            $comparison = $this->{$part} <=> $other->{$part};
            if ($comparison !== 0) {
                return $comparison;
            }
        }
        if ($this->prerelease === []) {
            return $other->prerelease === [] ? 0 : 1;
        }
        if ($other->prerelease === []) {
            return -1;
        }
        $length = max(count($this->prerelease), count($other->prerelease));
        for ($index = 0; $index < $length; ++$index) {
            if (!isset($this->prerelease[$index])) {
                return -1;
            }
            if (!isset($other->prerelease[$index])) {
                return 1;
            }
            $left = $this->prerelease[$index];
            $right = $other->prerelease[$index];
            if ($left === $right) {
                continue;
            }
            $leftNumeric = ctype_digit($left);
            $rightNumeric = ctype_digit($right);
            if ($leftNumeric && $rightNumeric) {
                return (int) $left <=> (int) $right;
            }
            if ($leftNumeric !== $rightNumeric) {
                return $leftNumeric ? -1 : 1;
            }

            return strcmp($left, $right) <=> 0;
        }

        return 0;
    }
}
