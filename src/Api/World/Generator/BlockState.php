<?php

declare(strict_types=1);

namespace Bedriox\Api\World\Generator;

use InvalidArgumentException;

final readonly class BlockState
{
    /** @var array<string, int|string> */
    public array $properties;

    /** @param array<array-key, mixed> $properties */
    public function __construct(
        public string $identifier,
        array $properties = [],
    ) {
        if (strlen($identifier) > 256
            || preg_match('/^[a-z0-9_.-]+:[a-z0-9_.-]+$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Generator block-state identifier must be bounded and namespaced.');
        }
        if (count($properties) > 128) {
            throw new InvalidArgumentException('Generator block state has too many properties.');
        }
        $normalized = [];
        foreach ($properties as $name => $value) {
            if (!is_string($name) || strlen($name) > 256
                || preg_match('/^[a-z0-9_.:-]+$/D', $name) !== 1
                || (!is_int($value) && !is_string($value))
                || (is_string($value) && (strlen($value) > 1_024 || !mb_check_encoding($value, 'UTF-8')))) {
                throw new InvalidArgumentException('Generator block state contains an invalid property.');
            }
            $normalized[$name] = $value;
        }
        ksort($normalized, SORT_STRING);
        $this->properties = $normalized;
    }
}
