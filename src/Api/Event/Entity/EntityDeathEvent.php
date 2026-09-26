<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Entity;

use Bedriox\Api\Entity\LivingEntity;
use Bedriox\Api\Event\Event;
use Bedriox\Api\Inventory\ItemStack;
use InvalidArgumentException;

/**
 * Non-cancellable death notification emitted after health reaches zero and
 * before the stable, once-evaluated drop list is spawned.
 */
final class EntityDeathEvent extends Event
{
    public const int MAXIMUM_DROPS = 128;

    /** @var list<ItemStack> */
    private array $drops;

    /** @param list<ItemStack> $drops */
    public function __construct(
        public readonly LivingEntity $entity,
        public readonly ?EntityDamageEvent $lastDamage,
        array $drops = [],
    ) {
        $this->drops = self::validateDrops($drops);
    }

    /** @return list<ItemStack> */
    public function getDrops(): array
    {
        return $this->drops;
    }

    /** @param list<ItemStack> $drops */
    public function setDrops(array $drops): void
    {
        $this->assertMutable();
        $this->drops = self::validateDrops($drops);
    }

    public function addDrop(ItemStack $drop): void
    {
        $this->assertMutable();
        if (count($this->drops) >= self::MAXIMUM_DROPS) {
            throw new InvalidArgumentException('Entity death drop list exceeds its supported bound.');
        }
        $this->drops[] = $drop;
    }

    public function clearDrops(): void
    {
        $this->assertMutable();
        $this->drops = [];
    }

    protected function state(): mixed
    {
        return $this->drops;
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state)) {
            throw new InvalidArgumentException('Invalid entity death event state.');
        }
        $this->drops = self::validateDrops($state);
    }

    /**
     * @param array<mixed> $drops
     * @return list<ItemStack>
     */
    private static function validateDrops(array $drops): array
    {
        if (!array_is_list($drops) || count($drops) > self::MAXIMUM_DROPS) {
            throw new InvalidArgumentException('Entity death drop list must be a bounded list.');
        }
        foreach ($drops as $drop) {
            if (!$drop instanceof ItemStack) {
                throw new InvalidArgumentException('Entity death drops must contain ItemStack values.');
            }
        }

        return $drops;
    }
}
