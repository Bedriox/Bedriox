<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\World;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\World\Biome;
use Bedriox\Server\World\BiomeRuntimeIdMap;
use PHPUnit\Framework\TestCase;

final class BiomeRuntimeIdMapTest extends TestCase
{
    public function testEveryPinnedBiomeDefinitionIsAdmittedWithoutHardcodedGaps(): void
    {
        $definitions = BedrockDataSet::bundled()->biomeDefinitions();
        $runtimeIds = BedrockDataSet::bundled()->biomeRuntimeIds();
        $map = new BiomeRuntimeIdMap($runtimeIds);

        self::assertCount(89, $definitions);
        self::assertCount(count($definitions), $map->identifiersById());
        foreach ($definitions as $definition) {
            self::assertTrue($map->contains($definition['name']));
            self::assertSame($runtimeIds[$definition['name']], $map->id(new Biome($definition['name'])));
        }
        self::assertSame(0, $map->id(new Biome('minecraft:ocean')));
        self::assertSame(1, $map->id(new Biome('minecraft:plains')));
        self::assertSame(24, $map->id(new Biome('minecraft:deep_ocean')));
        self::assertSame(195, $map->id(new Biome('minecraft:dappled_forest')));
    }
}
