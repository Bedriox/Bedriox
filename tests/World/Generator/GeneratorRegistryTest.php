<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\World\Generator;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Generator\BuiltInGeneratorDefinitions;
use Bedriox\Server\World\Generator\GeneratorContext;
use Bedriox\Server\World\Generator\GeneratorDefinition;
use Bedriox\Server\World\Generator\GeneratorIdentifier;
use Bedriox\Server\World\Generator\GeneratorOptions;
use Bedriox\Server\World\Generator\GeneratorRegistry;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\VersionedWorldGenerator;
use Bedriox\Server\World\WorldGeneratorFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GeneratorRegistryTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function invalidIdentifiers(): iterable
    {
        yield 'no namespace' => ['flat'];
        yield 'uppercase' => ['Example:flat'];
        yield 'empty name' => ['example:'];
        yield 'path delimiter' => ['example:../flat'];
    }

    #[DataProvider('invalidIdentifiers')]
    public function testIdentifierRequiresBoundedCanonicalNamespace(string $identifier): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GeneratorIdentifier($identifier);
    }

    public function testBuiltInsAreOwnerAttributedAndDeterministicallyOrdered(): void
    {
        $registry = new GeneratorRegistry();
        BuiltInGeneratorDefinitions::register($registry);

        self::assertSame(
            ['bedriox:default', 'bedriox:flat', 'bedriox:void'],
            array_map(
                static fn(GeneratorDefinition $definition): string => $definition->identifier->value,
                $registry->all(),
            ),
        );
        self::assertSame(BuiltInGeneratorDefinitions::OWNER, $registry->require('bedriox:void')->owner);
    }

    public function testBuiltInsCreateDeterministicGeneratorsFromAWorkerSafeContext(): void
    {
        $registry = new GeneratorRegistry();
        BuiltInGeneratorDefinitions::register($registry);
        $context = new GeneratorContext(
            -9_223,
            'minecraft:overworld',
            GeneratorOptions::fromJson('{"preset":"test"}'),
            $this->states(),
        );
        $position = new ChunkPosition(-3, 5);

        foreach ([BuiltInGeneratorDefinitions::DEFAULT, BuiltInGeneratorDefinitions::FLAT, BuiltInGeneratorDefinitions::VOID] as $identifier) {
            $first = $registry->create($identifier, $context);
            $second = $registry->create($identifier, $context);

            self::assertSame($identifier, $first->name());
            self::assertEquals($first->generate($position), $second->generate($position));
        }
    }

    public function testLegacyPersistedBuiltInNamesRemainStableAtTheFactoryBoundary(): void
    {
        foreach (['default', 'flat', 'void'] as $name) {
            self::assertSame($name, WorldGeneratorFactory::create($name, 11, $this->states())->name());
        }
    }

    public function testOnlyTheOwnerCanReplaceOrRemoveADefinition(): void
    {
        $registry = new GeneratorRegistry();
        $registry->register($this->definition('example:checkerboard', 'ExamplePlugin'));

        try {
            $registry->register($this->definition('example:checkerboard', 'OtherPlugin'), true);
            self::fail('Another owner replaced a registered generator.');
        } catch (RuntimeException) {
            self::assertSame('ExamplePlugin', $registry->require('example:checkerboard')->owner);
        }

        $registry->register($this->definition('example:checkerboard', 'ExamplePlugin', 2), true);
        self::assertSame(2, $registry->require('example:checkerboard')->version);

        $this->expectException(RuntimeException::class);
        $registry->unregister('example:checkerboard', 'OtherPlugin');
    }

    public function testOwnerCleanupDoesNotAffectOtherDefinitions(): void
    {
        $registry = new GeneratorRegistry();
        $registry->register($this->definition('alpha:first', 'Alpha'));
        $registry->register($this->definition('alpha:second', 'Alpha'));
        $registry->register($this->definition('beta:first', 'Beta'));

        self::assertSame(2, $registry->unregisterOwner('Alpha'));
        self::assertNull($registry->get('alpha:first'));
        self::assertSame('Beta', $registry->require('beta:first')->owner);
    }

    public function testDefinitionRejectsFactoryMetadataMismatch(): void
    {
        $definition = $this->definition('example:checkerboard', 'ExamplePlugin');
        $context = new GeneratorContext(7, 'minecraft:overworld', new GeneratorOptions(), $this->states());

        self::assertSame('example:checkerboard', $definition->create($context)->name());

        $invalid = new GeneratorDefinition(
            new GeneratorIdentifier('example:invalid'),
            1,
            'ExamplePlugin',
            static fn(GeneratorContext $context): VersionedWorldGenerator => new TestWorldGenerator(
                'example:different',
                1,
                $context->blockStates,
            ),
        );
        $this->expectException(RuntimeException::class);
        $invalid->create($context);
    }

    public function testDefinitionRejectsFactoriesBoundToLiveObjects(): void
    {
        $state = new \stdClass();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('worker-safe');
        new GeneratorDefinition(
            new GeneratorIdentifier('example:stateful'),
            1,
            'ExamplePlugin',
            static function (GeneratorContext $context) use ($state): VersionedWorldGenerator {
                unset($state);

                return new TestWorldGenerator('example:stateful', 1, $context->blockStates);
            },
        );
    }

    private function definition(string $identifier, string $owner, int $version = 1): GeneratorDefinition
    {
        return new GeneratorDefinition(
            new GeneratorIdentifier($identifier),
            $version,
            $owner,
            static fn(GeneratorContext $context): VersionedWorldGenerator => new TestWorldGenerator(
                $identifier,
                $version,
                $context->blockStates,
            ),
        );
    }

    private function states(): BlockStateRegistry
    {
        return new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
    }
}

final readonly class TestWorldGenerator implements VersionedWorldGenerator
{
    public function __construct(
        private string $identifier,
        private int $generatorVersion,
        private BlockStateRegistry $states,
    ) {}

    public function name(): string
    {
        return $this->identifier;
    }

    public function version(): int
    {
        return $this->generatorVersion;
    }

    public function generate(ChunkPosition $position): Chunk
    {
        return new Chunk(
            $position,
            $this->states->internalId(\Bedriox\Data\CanonicalBlockState::from('minecraft:air')),
            [],
        );
    }

    public function defaultSpawn(): SpawnPosition
    {
        return new SpawnPosition(0, 64, 0);
    }
}
