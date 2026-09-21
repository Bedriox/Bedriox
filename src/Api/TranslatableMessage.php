<?php

declare(strict_types=1);

namespace Bedriox\Api;

use InvalidArgumentException;

/** A client-localized message key with bounded string parameters. */
final readonly class TranslatableMessage
{
    /** @var list<string> */
    public array $parameters;

    /** @param array<array-key, mixed> $parameters */
    public function __construct(public string $key, array $parameters = [])
    {
        if ($key === '' || strlen($key) > 128 || preg_match('/^[a-z0-9_.-]+$/D', $key) !== 1) {
            throw new InvalidArgumentException('Translation key must use a bounded canonical identifier.');
        }
        if (count($parameters) > 16) {
            throw new InvalidArgumentException('A translated message may contain at most 16 parameters.');
        }
        foreach ($parameters as $parameter) {
            if (!is_string($parameter) || strlen($parameter) > 1024 || preg_match('//u', $parameter) !== 1) {
                throw new InvalidArgumentException('Translation parameters must be bounded UTF-8 strings.');
            }
            $characters = preg_match_all('/./us', $parameter);
            if (!is_int($characters) || $characters > 256) {
                throw new InvalidArgumentException('Translation parameters may contain at most 256 characters.');
            }
        }
        $this->parameters = array_values($parameters);
    }
}
