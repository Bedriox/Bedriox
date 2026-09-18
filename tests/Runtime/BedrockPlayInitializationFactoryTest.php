<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Identity\VerifiedClientData;
use Bedriox\Protocol\Packet\AvailableActorIdentifiersPacket;
use Bedriox\Protocol\Packet\BiomeDefinitionListPacket;
use Bedriox\Protocol\Packet\ChunkRadiusUpdatedPacket;
use Bedriox\Protocol\Packet\CraftingDataPacket;
use Bedriox\Protocol\Packet\CreativeContentPacket;
use Bedriox\Protocol\Packet\GameRulesChangedPacket;
use Bedriox\Protocol\Packet\InventoryContentPacket;
use Bedriox\Protocol\Packet\ItemRegistryPacket;
use Bedriox\Protocol\Packet\JigsawStructureDataPacket;
use Bedriox\Protocol\Packet\MobEquipmentPacket;
use Bedriox\Protocol\Packet\PlayerListAddPacket;
use Bedriox\Protocol\Packet\SetActorDataPacket;
use Bedriox\Protocol\Packet\SetCommandsEnabledPacket;
use Bedriox\Protocol\Packet\SetDifficultyPacket;
use Bedriox\Protocol\Packet\SetLocalPlayerAsInitializedPacket;
use Bedriox\Protocol\Packet\SetSpawnPositionPacket;
use Bedriox\Protocol\Packet\SetTimePacket;
use Bedriox\Protocol\Packet\StartGamePacket;
use Bedriox\Protocol\Packet\TrimDataPacket;
use Bedriox\Protocol\Packet\UpdateAbilitiesPacket;
use Bedriox\Protocol\Packet\UpdateAdventureSettingsPacket;
use Bedriox\Protocol\Packet\UpdateAttributesPacket;
use Bedriox\Protocol\Packet\VoxelShapesPacket;
use Bedriox\Protocol\Security\OpenSslEphemeralKeyFactory;
use Bedriox\Protocol\Value\BuildPlatform;
use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\Server\Login\AuthenticatedLogin;
use Bedriox\Server\Runtime\BedrockPlayInitializationFactory;
use Bedriox\Server\Runtime\RuntimeLimits;
use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class BedrockPlayInitializationFactoryTest extends TestCase
{
    public function testAuthoritativeWorldDataDrivesInitializationMetadataAndTime(): void
    {
        $login = $this->login();
        $worldData = new WorldData(
            new WorldMetadata('Persisted World', -912),
            'flat',
            new SpawnPosition(19, 77, -6),
            3456,
            3,
        );
        $packets = BedrockPlayInitializationFactory::forWorld(
            BedrockDataSet::bundled(),
            new RuntimeLimits(),
            $worldData,
        )->create($login, UnsignedLong::fromInt(7));
        $expected = (new BedrockPlayInitializationFactory(
            BedrockDataSet::bundled(),
            new RuntimeLimits(),
            'Persisted World',
            new SpawnPosition(19, 77, -6),
            3,
            -912,
            3456,
        ))->create($login, UnsignedLong::fromInt(7));

        self::assertSame($expected[2]->encode(), $packets[2]->encode());
        self::assertInstanceOf(SetSpawnPositionPacket::class, $packets[7]);
        self::assertSame([19, 77, -6], [$packets[7]->x, $packets[7]->y, $packets[7]->z]);
        self::assertInstanceOf(SetTimePacket::class, $packets[8]);
        self::assertSame(3456, $packets[8]->time);
        self::assertInstanceOf(SetDifficultyPacket::class, $packets[9]);
        self::assertSame(3, $packets[9]->difficulty);
    }

    public function testExactOrderGoldenRegistryAndDefersTerrainToRuntimeStreamer(): void
    {
        $login = $this->login();
        $packets = (new BedrockPlayInitializationFactory(BedrockDataSet::bundled()))
            ->create($login, UnsignedLong::fromInt(7));

        self::assertCount(24, $packets);
        self::assertInstanceOf(JigsawStructureDataPacket::class, $packets[0]);
        self::assertInstanceOf(VoxelShapesPacket::class, $packets[1]);
        self::assertInstanceOf(StartGamePacket::class, $packets[2]);
        self::assertSame(76_835, strlen($packets[2]->encode()));
        self::assertSame('f3c1650fac98ba4653d3bb2a26d20e7f86e400af65847c286fc8cc7347d7d634', hash('sha256', $packets[2]->encode()));
        self::assertInstanceOf(ItemRegistryPacket::class, $packets[3]);
        self::assertSame(166_607, strlen($packets[3]->encode()));
        self::assertSame('0cbe4e9e93a3003e8f8cf4f322ed36a18a373b2000fbaac8c164c5320ba2e7af', hash('sha256', $packets[3]->encode()));
        self::assertInstanceOf(ChunkRadiusUpdatedPacket::class, $packets[4]);
        self::assertSame(1, $packets[4]->radius);
        self::assertInstanceOf(BiomeDefinitionListPacket::class, $packets[5]);
        self::assertInstanceOf(AvailableActorIdentifiersPacket::class, $packets[6]);
        self::assertInstanceOf(SetSpawnPositionPacket::class, $packets[7]);
        self::assertSame([0, 64, 0], [$packets[7]->x, $packets[7]->y, $packets[7]->z]);
        self::assertInstanceOf(SetTimePacket::class, $packets[8]);
        self::assertInstanceOf(SetDifficultyPacket::class, $packets[9]);
        self::assertSame(2, $packets[9]->difficulty);
        self::assertInstanceOf(SetCommandsEnabledPacket::class, $packets[10]);
        self::assertInstanceOf(UpdateAbilitiesPacket::class, $packets[11]);
        self::assertInstanceOf(UpdateAdventureSettingsPacket::class, $packets[12]);
        self::assertInstanceOf(GameRulesChangedPacket::class, $packets[13]);
        self::assertInstanceOf(PlayerListAddPacket::class, $packets[14]);
        self::assertSame(BuildPlatform::Unknown, $packets[14]->entries[0]->buildPlatform);
        self::assertInstanceOf(UpdateAttributesPacket::class, $packets[15]);
        self::assertInstanceOf(CreativeContentPacket::class, $packets[16]);
        foreach ([[17, 0, 36], [18, 120, 4], [19, 119, 1]] as [$index, $windowId, $slotCount]) {
            self::assertInstanceOf(InventoryContentPacket::class, $packets[$index]);
            self::assertSame($windowId, $packets[$index]->windowId);
            self::assertCount($slotCount, $packets[$index]->items);
        }
        $mainInventory = $packets[17];
        self::assertInstanceOf(InventoryContentPacket::class, $mainInventory);
        self::assertSame(2, $mainInventory->items[0]->runtimeId);
        self::assertSame(64, $mainInventory->items[0]->count);
        self::assertSame(1, $mainInventory->items[0]->stackNetworkId);
        self::assertSame(14_944, $mainInventory->items[0]->blockRuntimeId);
        foreach (array_slice($mainInventory->items, 1) as $empty) {
            self::assertSame(0, $empty->runtimeId);
            self::assertSame(1, $empty->count);
        }
        $equipment = $packets[20];
        self::assertInstanceOf(MobEquipmentPacket::class, $equipment);
        self::assertEquals($mainInventory->items[0], $equipment->item);
        self::assertInstanceOf(TrimDataPacket::class, $packets[21]);
        self::assertInstanceOf(CraftingDataPacket::class, $packets[22]);
        self::assertInstanceOf(SetActorDataPacket::class, $packets[23]);
        self::assertSame(400, $packets[23]->metadata[4]->value);
        self::assertSame(400, $packets[23]->metadata[7]->value);
        foreach ($packets as $packet) {
            self::assertNotInstanceOf(SetLocalPlayerAsInitializedPacket::class, $packet);
        }
    }

    private function login(): AuthenticatedLogin
    {
        $key = (new OpenSslEphemeralKeyFactory(dirname(__DIR__) . '/Fixtures/openssl.cnf'))->generate()->publicKey;

        return new AuthenticatedLogin(
            'Player',
            '00000000-0000-0000-0000-000000000001',
            '1',
            $key,
            new VerifiedClientData(
                64,
                32,
                str_repeat("\0", 64 * 32 * 4),
                0,
                0,
                '',
                '{}',
                [],
                skinId: 'skin',
                skinResourcePatchJson: '{"geometry":{"default":"geometry.humanoid.custom"}}',
            ),
        );
    }
}
