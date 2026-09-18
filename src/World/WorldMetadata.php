<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

use InvalidArgumentException;

final readonly class WorldMetadata
{
    public function __construct(
        public string $name,
        public int $seed,
    ) {
        if ($name === '' || strlen($name) > 64 || preg_match('/^[A-Za-z0-9._ -]+$/D', $name) !== 1) {
            throw new InvalidArgumentException('World name must contain 1-64 safe display-name characters.');
        }
    }
}
