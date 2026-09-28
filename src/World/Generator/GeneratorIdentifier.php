<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Generator;

use InvalidArgumentException;

final readonly class GeneratorIdentifier
{
    public const int MAXIMUM_BYTES = 97;

    public function __construct(public string $value)
    {
        if (strlen($value) > self::MAXIMUM_BYTES
            || preg_match('/^[a-z0-9][a-z0-9_.-]{0,31}:[a-z0-9][a-z0-9_.-]{0,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Generator identifier must be a bounded lowercase namespaced identifier.');
        }
    }

    public function namespace(): string
    {
        return explode(':', $this->value, 2)[0];
    }

    public function name(): string
    {
        return substr($this->value, strpos($this->value, ':') + 1);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
