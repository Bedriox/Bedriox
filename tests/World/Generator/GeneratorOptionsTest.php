<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\World\Generator;

use Bedriox\Server\World\Generator\GeneratorOptions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GeneratorOptionsTest extends TestCase
{
    public function testEquivalentMapsHaveOneCanonicalRepresentation(): void
    {
        $first = new GeneratorOptions([
            'terrain' => ['scale' => 2.0, 'features' => ['caves', 'lakes']],
            'spawnStructures' => true,
        ]);
        $second = new GeneratorOptions([
            'spawnStructures' => true,
            'terrain' => ['features' => ['caves', 'lakes'], 'scale' => 2.0],
        ]);

        self::assertSame($first->canonicalJson(), $second->canonicalJson());
        self::assertSame($first->hash(), $second->hash());
        self::assertSame($first->values(), GeneratorOptions::fromJson($first->canonicalJson())->values());
    }

    public function testSupportsTheCompleteBoundedJsonValueSet(): void
    {
        $options = new GeneratorOptions(['nullable' => null, 'enabled' => true, 'count' => 4, 'scale' => 1.5]);

        self::assertSame(
            ['count' => 4, 'enabled' => true, 'nullable' => null, 'scale' => 1.5],
            $options->values(),
        );
    }

    /** @return iterable<string, array{array<mixed, mixed>}> */
    public static function invalidOptions(): iterable
    {
        yield 'list root' => [['value']];
        yield 'invalid key' => [['not valid' => true]];
        yield 'non-finite number' => [['scale' => NAN]];
        yield 'object' => [['callback' => new \stdClass()]];
        yield 'too many entries' => [array_fill_keys(
            array_map(static fn(int $value): string => 'key' . $value, range(0, GeneratorOptions::MAXIMUM_ENTRIES)),
            true,
        )];
    }

    /** @param array<mixed, mixed> $options */
    #[DataProvider('invalidOptions')]
    public function testRejectsUnboundedOrNonTransferableValues(array $options): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GeneratorOptions($options);
    }
}
