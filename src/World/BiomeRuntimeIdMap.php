<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

use Bedriox\Data\BedrockDataSet;
use InvalidArgumentException;

/** Immutable mapping between canonical biome identities and the pinned Bedrock runtime IDs. */
final readonly class BiomeRuntimeIdMap
{
    /** @var array<int, string> */
    private array $identifiersById;

    /** @var array<string, int> */
    private array $idsByIdentifier;

    /** @param array<array-key, mixed> $runtimeIds */
    public function __construct(array $runtimeIds)
    {
        if ($runtimeIds === [] || count($runtimeIds) > 1_024 || array_is_list($runtimeIds)) {
            throw new InvalidArgumentException('Biome runtime IDs must be a non-empty bounded map.');
        }
        $identifiers = [];
        $ids = [];
        foreach ($runtimeIds as $identifier => $id) {
            if (!is_string($identifier) || !is_int($id) || $id < 0 || $id > 65_535
                || isset($identifiers[$id]) || isset($ids[$identifier])) {
                throw new InvalidArgumentException('Biome runtime IDs contain an invalid or duplicate identity.');
            }
            new Biome($identifier);
            $identifiers[$id] = $identifier;
            $ids[$identifier] = $id;
        }
        ksort($identifiers, SORT_NUMERIC);
        $this->identifiersById = $identifiers;
        $this->idsByIdentifier = $ids;
    }

    public static function bundled(): self
    {
        return new self(BedrockDataSet::bundled()->biomeRuntimeIds());
    }

    public function id(Biome $biome): int
    {
        return $this->idsByIdentifier[$biome->identifier]
            ?? throw new InvalidArgumentException("Biome {$biome->identifier} is not admitted by the pinned Bedrock data set.");
    }

    /** @return array<int, string> */
    public function identifiersById(): array
    {
        return $this->identifiersById;
    }

    public function contains(string $identifier): bool
    {
        return isset($this->idsByIdentifier[$identifier]);
    }
}
