<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Entity;

use Bedriox\Api\Entity\CustomEntityType;
use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Entity\EntityDefinition;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\RegisteredEntityDefinition;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class EntityDefinitionRegistryTest extends TestCase
{
    public function testBaselineDefinitionsAreSharedAndFactoriesPreserveCanonicalType(): void
    {
        self::assertSame(VanillaEntityDefinitions::cow(), VanillaEntityDefinitions::cow());
        $registration = EntityDefinitionRegistry::baseline()->require(VanillaEntityType::COW);
        $entity = ($registration->factory)(
            '00000000-0000-4000-8000-000000000001',
            42,
            'world',
            new Position(1.0, 64.0, 2.0),
            90.0,
            10.0,
        );

        self::assertInstanceOf(CowEntity::class, $entity);
        self::assertSame(VanillaEntityType::COW, $entity->getType());
        self::assertSame(42, $entity->getRuntimeId());
    }

    public function testOwnedDefinitionsCannotReplaceAnotherOwnerAndCleanUpExactly(): void
    {
        $type = new CustomEntityType('example:guard');
        $definition = new EntityDefinition($type, EntityCategory::MONSTER, 'minecraft:zombie', 0.6, 1.95, 40.0);
        $factory = static fn(string $uuid, int $runtimeId, string $world, Position $position, float $yaw, float $pitch): CowEntity =>
            new CowEntity($uuid, $runtimeId, $world, $position, yaw: $yaw, pitch: $pitch);
        $registry = new EntityDefinitionRegistry([
            new RegisteredEntityDefinition($definition, $factory, 'FirstPlugin'),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $registry->register(new RegisteredEntityDefinition($definition, $factory, 'OtherPlugin'), true);
        } finally {
            self::assertSame(0, $registry->unregisterOwnedBy('OtherPlugin'));
            self::assertSame(1, $registry->unregisterOwnedBy('firstplugin'));
            self::assertNull($registry->get($type));
        }
    }

    public function testBuiltInDefinitionCannotBeReplacedByAPlugin(): void
    {
        $registry = EntityDefinitionRegistry::baseline();
        $registration = $registry->require(VanillaEntityType::COW);

        $this->expectException(\InvalidArgumentException::class);
        $registry->register(new RegisteredEntityDefinition(
            $registration->definition,
            $registration->factory,
            'ExamplePlugin',
        ), true);
    }

    public function testCurrentDataCatalogAdmitsMobSpawnEggsWithoutTreatingArbitraryActorsAsMobs(): void
    {
        $catalog = BedrockDataSet::bundled()->entityTypeRegistry();
        $registry = EntityDefinitionRegistry::fromData($catalog);

        foreach (['minecraft:cow', 'minecraft:zombie', 'minecraft:pig', 'minecraft:bee'] as $identifier) {
            self::assertNotNull($registry->get($identifier), $identifier);
        }
        foreach (['minecraft:arrow', 'minecraft:boat', 'minecraft:agent', 'minecraft:armor_stand', 'minecraft:npc'] as $identifier) {
            self::assertNull($registry->get($identifier), $identifier);
        }
    }
}
