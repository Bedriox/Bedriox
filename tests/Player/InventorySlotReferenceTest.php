<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Player;

use Bedriox\Protocol\Packet\FullContainerName;
use Bedriox\Server\Player\InventoryContainer;
use Bedriox\Server\Player\InventorySlotReference;
use PHPUnit\Framework\TestCase;

final class InventorySlotReferenceTest extends TestCase
{
    public function testInternalAndResponseSlotsRemainIndependent(): void
    {
        $reference = new InventorySlotReference(
            InventoryContainer::Offhand,
            0,
            7,
            FullContainerName::OFFHAND,
            responseSlot: 40,
        );

        self::assertSame('offhand:0', $reference->key());
        self::assertSame('offhand:' . FullContainerName::OFFHAND . ':-1:40', $reference->responseKey());
        self::assertSame(40, $reference->responseSlotId());
    }

    public function testResponseSlotDefaultsToTheAuthoritativeSlot(): void
    {
        $reference = new InventorySlotReference(InventoryContainer::Armor, 2, 9, FullContainerName::ARMOR);

        self::assertSame(2, $reference->responseSlotId());
        self::assertSame('armor:' . FullContainerName::ARMOR . ':-1:2', $reference->responseKey());
    }

    public function testOpenedContainerResponseIdentityIncludesItsDynamicWindow(): void
    {
        $reference = new InventorySlotReference(
            InventoryContainer::OpenedContainer,
            4,
            12,
            FullContainerName::DYNAMIC,
            responseContainerDynamicId: 7,
        );

        self::assertSame('opened_container:4', $reference->key());
        self::assertSame(
            'opened_container:' . FullContainerName::DYNAMIC . ':7:4',
            $reference->responseKey(),
        );
    }
}
