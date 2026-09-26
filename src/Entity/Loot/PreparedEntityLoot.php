<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Loot;

use Bedriox\Api\Inventory\ItemStack;

final class PreparedEntityLoot
{
    private bool $evaluated = false;

    /** @var list<ItemStack> */
    private array $drops = [];

    public function __construct(
        private readonly LootTable $table,
        private readonly LootContext $context,
        private readonly LootRandomSource $random,
        private readonly LootOutputNormalizer $normalizer,
    ) {}

    /** @return list<ItemStack> */
    public function drops(): array
    {
        if (!$this->evaluated) {
            $drops = $this->table->roll($this->context, $this->random);
            foreach ($this->context->equipment as $equipped) {
                if ($equipped->dropChance >= 1.0
                    || ($equipped->dropChance > 0.0
                        && $this->random->nextInt(0, 999_999) < (int) floor($equipped->dropChance * 1_000_000.0))) {
                    $drops[] = $equipped->item;
                }
            }
            $this->drops = $this->normalizer->normalize($drops);
            $this->evaluated = true;
        }

        return $this->drops;
    }
}
