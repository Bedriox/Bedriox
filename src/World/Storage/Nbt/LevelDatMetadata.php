<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Storage\Nbt;

use Bedriox\Server\World\Storage\Exception\CorruptWorldDataException;

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
        $tag = $this->required('generatorName', LittleEndianNbtTag::STRING);

        return is_string($tag->value) ? $tag->value : throw new CorruptWorldDataException("Invalid 'generatorName' tag in level.dat.");
    }

    public function generatorOptions(): string
    {
        $tag = $this->required('generatorOptions', LittleEndianNbtTag::STRING);

        return is_string($tag->value) ? $tag->value : throw new CorruptWorldDataException("Invalid 'generatorOptions' tag in level.dat.");
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
