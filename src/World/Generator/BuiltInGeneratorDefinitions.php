<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Generator;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\DefaultWorldGenerator;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\VoidWorldGenerator;

final class BuiltInGeneratorDefinitions
{
    public const string OWNER = 'bedriox';
    public const string DEFAULT = 'bedriox:default';
    public const string FLAT = 'bedriox:flat';
    public const string VOID = 'bedriox:void';

    private function __construct() {}

    public static function register(GeneratorRegistry $registry): void
    {
        $registry->register(new GeneratorDefinition(
            new GeneratorIdentifier(self::DEFAULT),
            DefaultWorldGenerator::VERSION,
            self::OWNER,
            static fn(GeneratorContext $context): DefinedWorldGenerator => new DefinedWorldGenerator(
                self::DEFAULT,
                DefaultWorldGenerator::VERSION,
                new DefaultWorldGenerator($context->seed, $context->blockStates),
            ),
        ));
        $registry->register(new GeneratorDefinition(
            new GeneratorIdentifier(self::FLAT),
            FlatWorldGenerator::VERSION,
            self::OWNER,
            static fn(GeneratorContext $context): DefinedWorldGenerator => new DefinedWorldGenerator(
                self::FLAT,
                FlatWorldGenerator::VERSION,
                new FlatWorldGenerator(FixedFlatBlockPalette::fromRegistry($context->blockStates)),
            ),
        ));
        $registry->register(new GeneratorDefinition(
            new GeneratorIdentifier(self::VOID),
            VoidWorldGenerator::VERSION,
            self::OWNER,
            static fn(GeneratorContext $context): VoidWorldGenerator => new VoidWorldGenerator(
                $context->blockStates->internalId(CanonicalBlockState::from('minecraft:air')),
            ),
        ));
    }
}
