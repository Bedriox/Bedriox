<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Loot;

use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Entity\EntityType;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Event\Entity\EntityDamageEvent;
use Bedriox\Api\Player\Player;
use InvalidArgumentException;

final readonly class LootContext
{
    /** @var list<EquippedLootItem> */
    public array $equipment;

    /**
     * @param array<int, mixed> $equipment
     */
    public function __construct(
        public EntityType $entityType,
        public ?EntityDamageEvent $lastDamage,
        public Entity|Player|null $killer,
        public bool $burning,
        array $equipment,
        public SpawnCause $spawnOrigin,
        public int $difficulty,
        public int $lootingLevel = 0,
    ) {
        if ($difficulty < 0 || $difficulty > 3) {
            throw new InvalidArgumentException('Loot difficulty must be a Bedrock value between zero and three.');
        }
        if ($lootingLevel < 0 || $lootingLevel > 255) {
            throw new InvalidArgumentException('Looting level must be between zero and 255.');
        }
        if (count($equipment) > 6) {
            throw new InvalidArgumentException('Loot equipment exceeds the supported slot count.');
        }
        $slots = [];
        foreach ($equipment as $entry) {
            if (!$entry instanceof EquippedLootItem) {
                throw new InvalidArgumentException('Loot equipment must contain equipped-item values.');
            }
            if (isset($slots[$entry->slot->value])) {
                throw new InvalidArgumentException('Loot equipment contains a duplicate slot.');
            }
            $slots[$entry->slot->value] = true;
        }
        $this->equipment = array_values($equipment);
    }
}
