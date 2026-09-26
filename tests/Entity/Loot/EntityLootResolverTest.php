<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Entity\Loot;

use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityIdentifier;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Server\Entity\Loot\EntityLootResolver;
use Bedriox\Server\Entity\Loot\EquippedLootItem;
use Bedriox\Server\Entity\Loot\LootContext;
use Bedriox\Server\Entity\Loot\LootItemRegistry;
use Bedriox\Server\Entity\Loot\LootRandomSource;
use Bedriox\Server\Entity\Loot\LootTable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EntityLootResolverTest extends TestCase
{
    public function testZombieDropsBoundedFleshAndOneRareItem(): void
    {
        $random = new ScriptedLootRandom([2, 4, 1]);
        $drops = self::resolver($random)->prepare(self::context(VanillaEntityType::ZOMBIE))->drops();

        self::assertSame(['minecraft:rotten_flesh', 'minecraft:carrot'], array_column($drops, 'identifier'));
        self::assertSame([2, 1], array_column($drops, 'count'));
        self::assertSame(3, $random->calls);
    }

    public function testZombieOmitsZeroCountAndRareMiss(): void
    {
        $random = new ScriptedLootRandom([0, 5]);

        self::assertSame([], self::resolver($random)->prepare(self::context(VanillaEntityType::ZOMBIE))->drops());
        self::assertSame(2, $random->calls);
    }

    public function testCowDropsLeatherAndRawOrCookedBeef(): void
    {
        $raw = self::resolver(new ScriptedLootRandom([2, 3]))
            ->prepare(self::context(VanillaEntityType::COW))
            ->drops();
        $cooked = self::resolver(new ScriptedLootRandom([0, 1]))
            ->prepare(self::context(VanillaEntityType::COW, burning: true))
            ->drops();

        self::assertSame(['minecraft:leather', 'minecraft:beef'], array_column($raw, 'identifier'));
        self::assertSame([2, 3], array_column($raw, 'count'));
        self::assertSame(['minecraft:cooked_beef'], array_column($cooked, 'identifier'));
        self::assertSame([1], array_column($cooked, 'count'));
    }

    public function testGenericEntityHasNoImplicitLoot(): void
    {
        $random = new ScriptedLootRandom([]);

        self::assertSame([], self::resolver($random)->prepare(self::context(
            new VanillaEntityIdentifier('minecraft:allay'),
        ))->drops());
        self::assertSame(0, $random->calls);
    }

    public function testPreparedDeathRollEvaluatesExactlyOnceAndPreservesEquipmentState(): void
    {
        $table = new CountingLootTable();
        $random = new ScriptedLootRandom([499_999]);
        $resolver = self::resolver($random);
        $resolver->register('minecraft:test_subject', $table);
        $nbt = ItemNbt::empty()->withString('bedriox:test', 'exact');
        $context = self::context(
            new VanillaEntityIdentifier('minecraft:test_subject'),
            equipment: [
                new EquippedLootItem(
                    EquipmentSlot::MAIN_HAND,
                    new ItemStack('minecraft:iron_sword', 1, 17, $nbt, 3),
                    0.5,
                ),
                new EquippedLootItem(
                    EquipmentSlot::HEAD,
                    new ItemStack('minecraft:iron_helmet', 1, 8),
                    0.0,
                ),
            ],
        );
        $prepared = $resolver->prepare($context);

        $first = $prepared->drops();
        $second = $prepared->drops();

        self::assertSame($first, $second);
        self::assertSame(1, $table->rolls);
        self::assertSame(1, $random->calls);
        self::assertCount(2, $first);
        self::assertSame('minecraft:iron_sword', $first[1]->identifier);
        self::assertSame(17, $first[1]->damage);
        self::assertSame(3, $first[1]->auxValue);
        self::assertSame($nbt, $first[1]->nbt);
    }

    public function testContextRejectsDuplicateEquipmentSlotsAndInvalidBounds(): void
    {
        $item = new EquippedLootItem(EquipmentSlot::HEAD, new ItemStack('minecraft:iron_helmet', 1), 1.0);

        foreach ([
            static fn() => self::context(VanillaEntityType::COW, equipment: [$item, $item]),
            static fn() => new LootContext(VanillaEntityType::COW, null, null, false, [], SpawnCause::NATURAL, 4),
            static fn() => new LootContext(VanillaEntityType::COW, null, null, false, [], SpawnCause::NATURAL, 2, 256),
            static fn() => new EquippedLootItem(EquipmentSlot::HEAD, new ItemStack('minecraft:iron_helmet', 1), NAN),
        ] as $case) {
            try {
                $case();
                self::fail('Invalid loot context was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @param list<EquippedLootItem> $equipment */
    private static function context(
        \Bedriox\Api\Entity\EntityType $type,
        bool $burning = false,
        array $equipment = [],
    ): LootContext {
        return new LootContext($type, null, null, $burning, $equipment, SpawnCause::NATURAL, 2);
    }

    private static function resolver(ScriptedLootRandom $random): EntityLootResolver
    {
        return EntityLootResolver::vanilla(new TestLootItemRegistry([
            'minecraft:beef' => 64,
            'minecraft:carrot' => 64,
            'minecraft:cooked_beef' => 64,
            'minecraft:iron_helmet' => 1,
            'minecraft:iron_ingot' => 64,
            'minecraft:iron_sword' => 1,
            'minecraft:leather' => 64,
            'minecraft:potato' => 64,
            'minecraft:rotten_flesh' => 64,
            'minecraft:stick' => 64,
        ]), $random);
    }
}

final class ScriptedLootRandom implements LootRandomSource
{
    public int $calls = 0;

    /** @param list<int> $values */
    public function __construct(private readonly array $values) {}

    public function nextInt(int $minimum, int $maximum): int
    {
        $value = $this->values[$this->calls] ?? throw new \RuntimeException('Random script is exhausted.');
        ++$this->calls;
        if ($value < $minimum || $value > $maximum) {
            throw new \RuntimeException('Random script value is outside the requested range.');
        }

        return $value;
    }
}

final readonly class TestLootItemRegistry implements LootItemRegistry
{
    /** @param array<string, int> $maximumStackSizes */
    public function __construct(private array $maximumStackSizes) {}

    public function maximumStackSize(string $identifier): ?int
    {
        return $this->maximumStackSizes[$identifier] ?? null;
    }
}

final class CountingLootTable implements LootTable
{
    public int $rolls = 0;

    public function roll(LootContext $context, LootRandomSource $random): array
    {
        ++$this->rolls;

        return [new ItemStack('minecraft:stick', 1)];
    }
}
