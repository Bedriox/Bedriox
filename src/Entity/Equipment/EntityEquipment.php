<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Equipment;

use Bedriox\Api\Entity\EntityEquipment as ApiEntityEquipment;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Server\Gameplay\Item\ArmorSlot;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Gameplay\Item\VanillaArmorDefinitions;
use Closure;
use InvalidArgumentException;
use LogicException;

/** Authoritative, bounded equipment owned by one living entity. */
final class EntityEquipment implements ApiEntityEquipment
{
    public const float DEFAULT_DROP_CHANCE = 0.0;
    public const int MAX_ITEM_NBT_BYTES = 32_768;

    /** @var array<string, ItemStack> */
    private array $items = [];

    /** @var array<string, float> */
    private array $dropChances = [];

    private ?ItemCatalog $catalog = null;

    private int $revision = 0;

    /** @var null|Closure(EquipmentSlot): void */
    private readonly ?Closure $onChanged;

    /** @var null|Closure(EquipmentSlot, ?ItemStack, float, ?ItemStack, float): (?EntityEquipmentTransition) */
    private ?Closure $beforeTransition = null;

    /** @var null|Closure(EquipmentSlot, ?ItemStack, float, ?ItemStack, float): void */
    private ?Closure $afterTransition = null;

    private bool $transitioning = false;

    /** @param null|Closure(EquipmentSlot): void $onChanged */
    public function __construct(?Closure $onChanged = null)
    {
        $this->onChanged = $onChanged;
        foreach (EquipmentSlot::cases() as $slot) {
            $this->dropChances[$slot->value] = self::DEFAULT_DROP_CHANCE;
        }
    }

    /**
     * Enables catalog-aware stack and slot validation.
     *
     * Existing contents are revalidated before the catalog becomes authoritative.
     */
    public function configureCatalog(ItemCatalog $catalog): void
    {
        if ($this->catalog !== null && $this->catalog !== $catalog) {
            throw new LogicException('Entity equipment already uses another item catalog.');
        }
        foreach (EquipmentSlot::cases() as $slot) {
            $item = $this->items[$slot->value] ?? null;
            if ($item !== null) {
                self::validateItem($slot, $item, $catalog);
            }
        }
        $this->catalog = $catalog;
    }

    /**
     * Installs the authoritative pre/post event boundary. Hydration never invokes these hooks.
     *
     * Returning null from the pre-hook cancels the transition. A returned value may adjust
     * both the item and its per-slot drop chance before validation and commit.
     *
     * @param null|Closure(EquipmentSlot, ?ItemStack, float, ?ItemStack, float): (?EntityEquipmentTransition) $before
     * @param null|Closure(EquipmentSlot, ?ItemStack, float, ?ItemStack, float): void $after
     */
    public function configureTransitionHooks(?Closure $before, ?Closure $after): void
    {
        if (($before === null) !== ($after === null)) {
            throw new InvalidArgumentException('Entity equipment transition hooks must be configured as a pair.');
        }
        $this->beforeTransition = $before;
        $this->afterTransition = $after;
    }

    public function getItem(EquipmentSlot $slot): ?ItemStack
    {
        return $this->items[$slot->value] ?? null;
    }

    /** @return array<string, ItemStack> keyed by EquipmentSlot value */
    public function getContents(): array
    {
        $contents = [];
        foreach (EquipmentSlot::cases() as $slot) {
            $item = $this->items[$slot->value] ?? null;
            if ($item !== null) {
                $contents[$slot->value] = $item;
            }
        }

        return $contents;
    }

    public function setItem(EquipmentSlot $slot, ?ItemStack $item): void
    {
        $this->transition($slot, $item, $this->dropChances[$slot->value]);
    }

    public function clear(EquipmentSlot $slot): void
    {
        $this->setItem($slot, null);
    }

    public function clearAll(): void
    {
        foreach (EquipmentSlot::cases() as $slot) {
            $this->clear($slot);
        }
    }

    public function getDropChance(EquipmentSlot $slot): float
    {
        return $this->dropChances[$slot->value];
    }

    public function setDropChance(EquipmentSlot $slot, float $chance): void
    {
        self::validateDropChance($chance);
        $this->transition($slot, $this->items[$slot->value] ?? null, $chance);
    }

    public function revision(): int
    {
        return $this->revision;
    }

    /** @internal Persistence hydration before the entity becomes observable. */
    public function restoreItem(EquipmentSlot $slot, ?ItemStack $item, float $dropChance): void
    {
        if ($item !== null) {
            self::validateItem($slot, $item, $this->catalog);
            $this->items[$slot->value] = $item;
        } else {
            unset($this->items[$slot->value]);
        }
        self::validateDropChance($dropChance);
        $this->dropChances[$slot->value] = $dropChance;
    }

    private function changed(EquipmentSlot $slot): void
    {
        if ($this->revision < PHP_INT_MAX) {
            ++$this->revision;
        }
        ($this->onChanged)?->__invoke($slot);
    }

    private function transition(EquipmentSlot $slot, ?ItemStack $item, float $dropChance): void
    {
        if ($this->transitioning) {
            throw new LogicException('Entity equipment transitions cannot be nested.');
        }
        $previous = $this->items[$slot->value] ?? null;
        $previousDropChance = $this->dropChances[$slot->value];
        if ($previous == $item && $previousDropChance === $dropChance) {
            return;
        }

        $this->transitioning = true;
        try {
            $transition = $this->beforeTransition === null
                ? new EntityEquipmentTransition($item, $dropChance)
                : ($this->beforeTransition)(
                    $slot,
                    $previous,
                    $previousDropChance,
                    $item,
                    $dropChance,
                );
            if ($transition === null) {
                return;
            }
            if ($transition->item !== null) {
                self::validateItem($slot, $transition->item, $this->catalog);
            }
            self::validateDropChance($transition->dropChance);
            if ($previous == $transition->item && $previousDropChance === $transition->dropChance) {
                return;
            }

            if ($transition->item === null) {
                unset($this->items[$slot->value]);
            } else {
                $this->items[$slot->value] = $transition->item;
            }
            $this->dropChances[$slot->value] = $transition->dropChance;
            $this->changed($slot);
            ($this->afterTransition)?->__invoke(
                $slot,
                $previous,
                $previousDropChance,
                $transition->item,
                $transition->dropChance,
            );
        } finally {
            $this->transitioning = false;
        }
    }

    private static function validateDropChance(float $chance): void
    {
        if (!is_finite($chance) || $chance < 0.0 || $chance > 1.0) {
            throw new InvalidArgumentException('Entity equipment drop chance must be finite and between zero and one.');
        }
    }

    private static function validateItem(EquipmentSlot $slot, ItemStack $item, ?ItemCatalog $catalog): void
    {
        if ($item->nbt !== null && strlen($item->nbt->toBinary()) > self::MAX_ITEM_NBT_BYTES) {
            throw new InvalidArgumentException('Entity equipment item NBT exceeds its persistence limit.');
        }
        $type = null;
        if ($catalog !== null) {
            if (!$catalog->has($item->identifier)) {
                throw new InvalidArgumentException('Entity equipment item is not present in the active item catalog.');
            }
            $type = $catalog->type($item->identifier);
            if ($item->count > $type->maximumStackSize) {
                throw new InvalidArgumentException('Entity equipment stack exceeds the supported item stack size.');
            }
        }

        if ($slot->isArmor()) {
            $armor = $type === null
                ? VanillaArmorDefinitions::definition($item->identifier)
                : ($type->armor ?? VanillaArmorDefinitions::definition($item->identifier));
            $expected = match ($slot) {
                EquipmentSlot::HEAD => ArmorSlot::Head,
                EquipmentSlot::CHEST => ArmorSlot::Chest,
                EquipmentSlot::LEGS => ArmorSlot::Legs,
                EquipmentSlot::FEET => ArmorSlot::Feet,
                default => throw new LogicException('Non-armor equipment slot reached armor validation.'),
            };
            if ($armor === null || $armor->slot !== $expected) {
                throw new InvalidArgumentException('Entity equipment item is incompatible with the armor slot.');
            }
        }

        if ($slot === EquipmentSlot::OFF_HAND && $type !== null && !$type->allowedInOffhand) {
            throw new InvalidArgumentException('Entity equipment item is not allowed in the offhand slot.');
        }
    }
}
