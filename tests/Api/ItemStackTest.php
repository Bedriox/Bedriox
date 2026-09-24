<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Api;

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Inventory\ItemStack;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ItemStackTest extends TestCase
{
    public function testAuxiliaryVariantIsPreservedIndependentlyFromDurabilityAndNbt(): void
    {
        $nbt = ItemNbt::empty()->withString('example:variant', 'retained');
        $stack = new ItemStack('minecraft:arrow', 7, damage: 12, nbt: $nbt, auxValue: 32_767);

        self::assertSame('minecraft:arrow', $stack->identifier);
        self::assertSame(7, $stack->count);
        self::assertSame(12, $stack->damage);
        self::assertSame('retained', $stack->nbt?->string('example:variant'));
        self::assertSame(32_767, $stack->auxValue);
    }

    #[DataProvider('invalidAuxiliaryValues')]
    public function testRejectsAuxiliaryVariantOutsideTheBedrockRange(int $auxValue): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ItemStack('minecraft:arrow', 1, auxValue: $auxValue);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidAuxiliaryValues(): iterable
    {
        yield 'negative' => [-1];
        yield 'above signed short' => [32_768];
    }
}
