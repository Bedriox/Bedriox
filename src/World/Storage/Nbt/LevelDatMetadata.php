<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Storage\Nbt;

use Bedriox\Server\World\Storage\Exception\CorruptWorldDataException;
use Bedriox\Server\World\Storage\Exception\UnsupportedWorldDataException;

final readonly class LevelDatMetadata
{
    /** @param array<string, LittleEndianNbtTag> $root */
    public function __construct(
        public int $headerVersion,
        public array $root,
    ) {}

    public function storageVersion(): int
    {
        return $this->requiredInteger('StorageVersion', LittleEndianNbtTag::INT);
    }

    public function networkVersion(): int
    {
        return $this->requiredInteger('NetworkVersion', LittleEndianNbtTag::INT);
    }

    public function levelName(): string
    {
        $tag = $this->required('LevelName', LittleEndianNbtTag::STRING);
        if (!is_string($tag->value) || $tag->value === '' || strlen($tag->value) > 64) {
            throw new CorruptWorldDataException("Invalid 'LevelName' tag in level.dat.");
        }

        return $tag->value;
    }

    public function seed(): int
    {
        return $this->requiredInteger('RandomSeed', LittleEndianNbtTag::LONG);
    }

    public function generatorName(): string
    {
        $tag = $this->root['generatorName'] ?? null;
        if ($tag instanceof LittleEndianNbtTag && $tag->type === LittleEndianNbtTag::STRING && is_string($tag->value)) {
            if ($tag->value === '' || strlen($tag->value) > 128
                || preg_match('/^[A-Za-z0-9._-]+(?::[A-Za-z0-9._\/-]+)?$/D', $tag->value) !== 1) {
                throw new CorruptWorldDataException("Invalid 'generatorName' tag in level.dat.");
            }
            $legacy = $this->root['Generator'] ?? null;
            if ($legacy instanceof LittleEndianNbtTag && $legacy->type === LittleEndianNbtTag::INT && is_int($legacy->value)) {
                $expected = match ($tag->value) {
                    'default' => 1,
                    'flat' => 2,
                    default => null,
                };
                if ($expected !== null && $legacy->value !== $expected) {
                    throw new CorruptWorldDataException('Generator metadata in level.dat is contradictory.');
                }
            }
            return $tag->value;
        }
        if ($tag !== null) {
            throw new CorruptWorldDataException("Invalid 'generatorName' tag in level.dat.");
        }
        $legacy = $this->root['Generator'] ?? null;
        if (!$legacy instanceof LittleEndianNbtTag || $legacy->type !== LittleEndianNbtTag::INT || !is_int($legacy->value)) {
            throw new CorruptWorldDataException("Missing or invalid generator metadata in level.dat.");
        }
        return match ($legacy->value) {
            1 => 'default',
            2 => 'flat',
            default => throw new UnsupportedWorldDataException(
                "Legacy generator {$legacy->value} is not supported.",
            ),
        };
    }

    public function generatorOptions(): string
    {
        $tag = $this->root['generatorOptions'] ?? null;
        if ($tag === null) {
            return '';
        }
        if ($tag->type !== LittleEndianNbtTag::STRING || !is_string($tag->value)) {
            throw new CorruptWorldDataException("Invalid 'generatorOptions' tag in level.dat.");
        }

        return $tag->value;
    }

    public function generatorVersion(): int
    {
        $tag = $this->root['BedrioxGeneratorVersion'] ?? null;
        if ($tag === null) {
            return 1;
        }
        if ($tag->type !== LittleEndianNbtTag::INT || !is_int($tag->value) || $tag->value < 1) {
            throw new CorruptWorldDataException("Invalid 'BedrioxGeneratorVersion' tag in level.dat.");
        }

        return $tag->value;
    }

    public function spawnX(): int
    {
        return $this->requiredInteger('SpawnX', LittleEndianNbtTag::INT);
    }

    public function spawnY(): int
    {
        return $this->requiredInteger('SpawnY', LittleEndianNbtTag::INT);
    }

    public function spawnZ(): int
    {
        return $this->requiredInteger('SpawnZ', LittleEndianNbtTag::INT);
    }

    public function time(): int
    {
        $tag = $this->root['Time'] ?? null;
        if (!$tag instanceof LittleEndianNbtTag || !in_array($tag->type, [LittleEndianNbtTag::INT, LittleEndianNbtTag::LONG], true) || !is_int($tag->value)) {
            throw new CorruptWorldDataException("Missing or invalid 'Time' tag in level.dat.");
        }

        return $tag->value;
    }

    public function difficulty(): int
    {
        $tag = $this->root['Difficulty'] ?? null;
        if ($tag === null) {
            return 2;
        }
        if ($tag->type !== LittleEndianNbtTag::INT || !is_int($tag->value) || $tag->value < 0 || $tag->value > 3) {
            throw new CorruptWorldDataException("Invalid 'Difficulty' tag in level.dat.");
        }

        return $tag->value;
    }

    private function requiredInteger(string $name, int $type): int
    {
        $tag = $this->required($name, $type);
        if (!is_int($tag->value)) {
            throw new CorruptWorldDataException("Invalid '$name' tag in level.dat.");
        }

        return $tag->value;
    }

    private function required(string $name, int $type): LittleEndianNbtTag
    {
        $tag = $this->root[$name] ?? null;
        if (!$tag instanceof LittleEndianNbtTag || $tag->type !== $type) {
            throw new CorruptWorldDataException("Missing or invalid '$name' tag in level.dat.");
        }

        return $tag;
    }
}
