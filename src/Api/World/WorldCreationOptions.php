<?php

declare(strict_types=1);

namespace Bedriox\Api\World;

use InvalidArgumentException;

final readonly class WorldCreationOptions
{
    public const int MAXIMUM_GENERATOR_OPTION_DEPTH = 8;
    public const int MAXIMUM_GENERATOR_OPTION_VALUES = 256;
    public const int MAXIMUM_GENERATOR_OPTION_STRING_BYTES = 4_096;

    /**
     * @param array<string|int, array<array-key, mixed>|bool|float|int|string|null> $generatorOptions
     */
    public function __construct(
        public string $generator = 'default',
        public int $seed = 0,
        public array $generatorOptions = [],
        public ?string $displayName = null,
        public WorldDifficulty $difficulty = WorldDifficulty::NORMAL,
        public int $initialTime = 0,
        public ?Position $spawn = null,
    ) {
        self::validateGeneratorIdentifier($generator);
        self::validateDisplayName($displayName);
        if ($initialTime < 0 || $initialTime > 0x7fffffff) {
            throw new InvalidArgumentException('Initial world time must be between 0 and 2147483647 ticks.');
        }
        if ($spawn !== null) {
            if ($spawn->world !== null) {
                throw new InvalidArgumentException('A new world spawn may not reference an existing world.');
            }
            $spawn->validate();
        }
        $values = 0;
        self::validateGeneratorOptions($generatorOptions, 0, $values);
    }

    private static function validateGeneratorIdentifier(string $generator): void
    {
        if (strlen($generator) > 128 || preg_match('/^[a-z0-9_.-]+(?::[a-z0-9_.\/-]+)?$/D', $generator) !== 1) {
            throw new InvalidArgumentException('Generator identifier must be canonical, bounded, and optionally namespaced.');
        }
        if (!str_contains($generator, ':') && !in_array($generator, ['default', 'flat', 'void'], true)) {
            throw new InvalidArgumentException('Custom generator identifiers must be namespaced.');
        }
    }

    private static function validateDisplayName(?string $displayName): void
    {
        if ($displayName === null) {
            return;
        }
        if ($displayName === '' || strlen($displayName) > 128 || preg_match('//u', $displayName) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $displayName) === 1) {
            throw new InvalidArgumentException('World display name must be bounded, non-empty UTF-8 without control characters.');
        }
    }

    /** @param array<array-key, mixed> $options */
    private static function validateGeneratorOptions(array $options, int $depth, int &$values): void
    {
        if ($depth > self::MAXIMUM_GENERATOR_OPTION_DEPTH) {
            throw new InvalidArgumentException('Generator options exceed their supported nesting depth.');
        }
        foreach ($options as $key => $value) {
            ++$values;
            if ($values > self::MAXIMUM_GENERATOR_OPTION_VALUES) {
                throw new InvalidArgumentException('Generator options contain too many values.');
            }
            if (is_string($key) && (strlen($key) > 64 || preg_match('/^[A-Za-z0-9_.-]+$/D', $key) !== 1)) {
                throw new InvalidArgumentException('Generator option keys must be bounded safe identifiers.');
            }
            if (is_array($value)) {
                self::validateGeneratorOptions($value, $depth + 1, $values);

                continue;
            }
            if (is_float($value) && !is_finite($value)) {
                throw new InvalidArgumentException('Generator option numbers must be finite.');
            }
            if (is_string($value) && (strlen($value) > self::MAXIMUM_GENERATOR_OPTION_STRING_BYTES
                || preg_match('//u', $value) !== 1)) {
                throw new InvalidArgumentException('Generator option strings must be bounded UTF-8.');
            }
            if ($value !== null && !is_bool($value) && !is_int($value) && !is_float($value) && !is_string($value)) {
                throw new InvalidArgumentException('Generator options may contain only bounded data values.');
            }
        }
    }
}
