<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Storage\LevelDb;

use Bedriox\Server\World\Biome;
use Bedriox\Server\World\BiomeRuntimeIdMap;
use InvalidArgumentException;

/** Explicitly bounded mapping between Bedrock persistent biome IDs and canonical world identities. */
final readonly class PersistentBiomeRegistry
{
    /** @var array<int, string> */
    private array $identifiersById;

    /** @var array<string, int> */
    private array $idsByIdentifier;

    /** @param array<int, string> $identifiersById */
    public function __construct(array $identifiersById = [])
    {
        $identifiersById = $identifiersById === [] ? BiomeRuntimeIdMap::identifiersById() : $identifiersById;
        if ($identifiersById === [] || count($identifiersById) > 4_096) {
            throw new InvalidArgumentException('Persistent biome registry must be non-empty and bounded.');
        }
        $reverse = [];
        foreach ($identifiersById as $id => $identifier) {
            if ($id < 0 || $id > 0xffff_ffff || isset($reverse[$identifier])) {
                throw new InvalidArgumentException('Persistent biome registry contains an invalid or duplicate entry.');
            }
            new Biome($identifier);
            $reverse[$identifier] = $id;
        }
        $this->identifiersById = $identifiersById;
        $this->idsByIdentifier = $reverse;
    }

    public function biome(int $id): Biome
    {
        $identifier = $this->identifiersById[$id] ?? null;
        if ($identifier === null) {
            throw new LevelDbStorageException("Persistent biome ID $id is not admitted by this server.");
        }

        return new Biome($identifier);
    }

    public function id(Biome $biome): int
    {
        return $this->idsByIdentifier[$biome->identifier]
            ?? throw new LevelDbStorageException("Biome {$biome->identifier} has no admitted persistent ID.");
    }
}
