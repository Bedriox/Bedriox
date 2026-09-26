<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Api;

use Bedriox\Api\Entity\EntityController;
use Bedriox\Api\Entity\EntityEquipment;
use Bedriox\Api\Inventory\EquipmentSlot;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class EntityControllerApiTest extends TestCase
{
    public function testEquipmentContractUsesTypedFiniteSlots(): void
    {
        $equipment = $this->createStub(EntityEquipment::class);
        $equipment->method('getContents')->willReturn(array_fill_keys(
            array_map(static fn(EquipmentSlot $slot): string => $slot->value, EquipmentSlot::cases()),
            null,
        ));

        self::assertSame(
            ['head', 'chest', 'legs', 'feet', 'main_hand', 'off_hand'],
            array_keys($equipment->getContents()),
        );
    }

    public function testControllerExposesTheExpectedBoundedMutationSurface(): void
    {
        foreach ([
            'teleport',
            'setRotation',
            'setVelocity',
            'setNameTag',
            'setNameTagVisible',
            'setImmobile',
            'setInvisible',
            'setGlowing',
            'setScale',
            'setGravityEnabled',
            'setOnFire',
            'extinguish',
            'despawn',
        ] as $method) {
            self::assertTrue((new ReflectionMethod(EntityController::class, $method))->isPublic());
        }
    }
}
