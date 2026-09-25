<?php

declare(strict_types=1);

namespace Bedriox\Api\Inventory;

use Bedriox\Api\World\BlockPosition;
use InvalidArgumentException;

/** Immutable public view of a storage container without window or protocol identifiers. */
final readonly class ContainerView
{
    /** @var list<string> */
    public array $viewerUuids;

    /** @param array<mixed> $viewerUuids */
    public function __construct(
        public ContainerType $type,
        public InventoryView $inventory,
        public ?BlockPosition $position,
        public ?string $customName = null,
        array $viewerUuids = [],
    ) {
        if ($customName !== null
            && ($customName === '' || strlen($customName) > 256 || preg_match('//u', $customName) !== 1)) {
            throw new InvalidArgumentException('Container custom name must be non-empty bounded UTF-8.');
        }
        if (count($viewerUuids) > 128 || !array_is_list($viewerUuids)) {
            throw new InvalidArgumentException('Container viewer list exceeds its limit.');
        }
        $seen = [];
        $normalizedViewers = [];
        foreach ($viewerUuids as $viewerUuid) {
            if (!is_string($viewerUuid)
                || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $viewerUuid) !== 1
                || isset($seen[strtolower($viewerUuid)])) {
                throw new InvalidArgumentException('Container viewers must be unique UUIDs.');
            }
            $seen[strtolower($viewerUuid)] = true;
            $normalizedViewers[] = $viewerUuid;
        }
        $this->viewerUuids = $normalizedViewers;
    }
}
