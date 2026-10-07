<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Api\Entity\Value\ArmadilloState;
use Bedriox\Api\Entity\Value\CatVariant;
use Bedriox\Api\Entity\Value\LlamaVariant;
use Bedriox\Api\Entity\Value\MooshroomVariant;
use Bedriox\Api\Entity\Value\PandaActivity;
use Bedriox\Api\Entity\Value\PandaGene;
use Bedriox\Api\Entity\Value\SlimeSize;
use Bedriox\Api\Entity\Value\WolfVariant;
use Bedriox\Api\Entity\Value\WoolColor;
use Bedriox\Protocol\Packet\ActorFlag;
use Bedriox\Protocol\Packet\ActorMetadata;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\ArmadilloEntity;
use Bedriox\Server\Entity\Vanilla\CatEntity;
use Bedriox\Server\Entity\Vanilla\CreeperEntity;
use Bedriox\Server\Entity\Vanilla\FoxEntity;
use Bedriox\Server\Entity\Vanilla\GoatEntity;
use Bedriox\Server\Entity\Vanilla\LlamaEntity;
use Bedriox\Server\Entity\Vanilla\MagmaCubeEntity;
use Bedriox\Server\Entity\Vanilla\MooshroomEntity;
use Bedriox\Server\Entity\Vanilla\OcelotEntity;
use Bedriox\Server\Entity\Vanilla\PandaEntity;
use Bedriox\Server\Entity\Vanilla\PolarBearEntity;
use Bedriox\Server\Entity\Vanilla\SheepEntity;
use Bedriox\Server\Entity\Vanilla\SkeletonEntity;
use Bedriox\Server\Entity\Vanilla\SlimeEntity;
use Bedriox\Server\Entity\Vanilla\SpiderEntity;
use Bedriox\Server\Entity\Vanilla\WolfEntity;
use Bedriox\Server\Runtime\BedrockLivingActorProjector;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class LandMobActorMetadataTest extends TestCase
{
    public function testSheepProjectsColorShearedBabyAndBabyDimensions(): void
    {
        $sheep = new SheepEntity(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.0, 64.0, 0.0),
            baby: true,
            woolColor: WoolColor::RED,
        );
        $projector = new BedrockLivingActorProjector();
        $metadata = $projector->metadata($sheep);
        self::assertSame(14, self::integer($metadata, 3));
        self::assertNotSame(0, self::integer($metadata, 0) & ActorFlag::Baby->mask());
        self::assertSame(0.5, self::float($metadata, 38));
        self::assertSame(0.45, self::float($metadata, 53));
        self::assertSame(0.65, self::float($metadata, 54));

        $sheep->setBaby(false);
        $sheep->setSheared(true);
        $metadata = $projector->metadata($sheep);
        self::assertNotSame(0, self::integer($metadata, 0) & ActorFlag::Sheared->mask());
        self::assertSame(0, self::integer($metadata, 0) & ActorFlag::Baby->mask());
        self::assertSame(1.0, self::float($metadata, 38));
        self::assertSame(0.9, self::float($metadata, 53));
        self::assertSame(1.3, self::float($metadata, 54));
    }

    public function testSkeletonUsesExactBaselineWithoutSpeciesOnlyFlags(): void
    {
        $skeleton = new SkeletonEntity(EntityUuid::random(), 2, 'world', new Position(0.0, 64.0, 0.0));
        $metadata = (new BedrockLivingActorProjector())->metadata($skeleton);
        $flags = self::integer($metadata, 0);

        self::assertSame(0, $flags & ActorFlag::Baby->mask());
        self::assertSame(0, $flags & ActorFlag::Sheared->mask());
        self::assertSame(0.6, self::float($metadata, 53));
        self::assertSame(1.99, self::float($metadata, 54));
    }

    public function testSpecialHostilesProjectTheirBoundedSpeciesState(): void
    {
        $projector = new BedrockLivingActorProjector();
        $spider = new SpiderEntity(EntityUuid::random(), 3, 'world', new Position(0.0, 64.0, 0.0));
        self::assertNotSame(0, self::integer($projector->metadata($spider), 0) & ActorFlag::CanClimb->mask());

        $slime = new SlimeEntity(EntityUuid::random(), 4, 'world', new Position(0.0, 64.0, 0.0), SlimeSize::MEDIUM);
        self::assertSame(2, self::integer($projector->metadata($slime), 2));

        $creeper = new CreeperEntity(EntityUuid::random(), 5, 'world', new Position(0.0, 64.0, 0.0));
        $creeper->setCharged(true);
        $creeper->setIgnited(true);
        $flags = self::integer($projector->metadata($creeper), 0);
        self::assertNotSame(0, $flags & ActorFlag::Powered->mask());
        self::assertSame(0, $flags & ActorFlag::Charged->mask());
        self::assertNotSame(0, $flags & ActorFlag::Ignited->mask());

        $magma = new MagmaCubeEntity(EntityUuid::random(), 6, 'world', new Position(0.0, 64.0, 0.0));
        self::assertNotSame(0, self::integer($projector->metadata($magma), 0) & ActorFlag::FireImmune->mask());
    }

    public function testTamedSittingAndAngryStateProjectsWithCollarAndVariant(): void
    {
        $wolf = new WolfEntity(
            EntityUuid::random(),
            7,
            'world',
            new Position(0.0, 64.0, 0.0),
            variant: WolfVariant::SNOWY,
            collarColor: WoolColor::BLUE,
            ownerUniqueId: EntityUuid::random(),
            sitting: true,
            angerTargetUniqueId: EntityUuid::random(),
            angerTicks: 200,
        );
        $metadata = (new BedrockLivingActorProjector())->metadata($wolf);
        $flags = self::integer($metadata, 0);

        self::assertNotSame(0, $flags & ActorFlag::Tamed->mask());
        self::assertNotSame(0, $flags & ActorFlag::Sitting->mask());
        self::assertNotSame(0, $flags & ActorFlag::Angry->mask());
        self::assertSame(5, self::integer($metadata, 2));
        self::assertSame(11, self::integer($metadata, 3));
    }

    public function testCatVariantsUseCurrentBedrockValues(): void
    {
        self::assertSame(0, CatVariant::WHITE->value);
        self::assertSame(8, CatVariant::TABBY->value);
        self::assertSame(9, CatVariant::ALL_BLACK->value);
        self::assertSame(10, CatVariant::JELLIE->value);

        $cat = new CatEntity(
            EntityUuid::random(),
            8,
            'world',
            new Position(0.0, 64.0, 0.0),
            variant: CatVariant::TABBY,
        );
        self::assertSame(8, self::integer((new BedrockLivingActorProjector())->metadata($cat), 2));
    }

    public function testTrustedOcelotProjectsTrustingInTheSecondFlagWord(): void
    {
        $ocelot = new OcelotEntity(
            EntityUuid::random(),
            9,
            'world',
            new Position(0.0, 64.0, 0.0),
            trustedPlayerUniqueId: EntityUuid::random(),
        );
        $flags2 = self::integer((new BedrockLivingActorProjector())->metadata($ocelot), 92);

        self::assertNotSame(0, $flags2 & ActorFlag::Trusting->mask());
    }

    public function testSleepingTrustedFoxProjectsBothSecondWordFlags(): void
    {
        $fox = new FoxEntity(
            EntityUuid::random(),
            10,
            'world',
            new Position(0.0, 64.0, 0.0),
            primaryTrustedPlayerUniqueId: EntityUuid::random(),
            sleeping: true,
        );
        $flags2 = self::integer((new BedrockLivingActorProjector())->metadata($fox), 92);

        self::assertNotSame(0, $flags2 & ActorFlag::Trusting->mask());
        self::assertNotSame(0, $flags2 & ActorFlag::Sleeping->mask());

        $fox->beginPounce();
        $pouncing = self::integer((new BedrockLivingActorProjector())->metadata($fox), 92);
        self::assertNotSame(0, $pouncing & ActorFlag::JumpGoalJump->mask());
        self::assertSame(0, $pouncing & ActorFlag::Sleeping->mask());

        $fox->beginFaceplant();
        $faceplanted = self::integer((new BedrockLivingActorProjector())->metadata($fox), 92);
        self::assertNotSame(0, $faceplanted & ActorFlag::Stunned->mask());
        self::assertSame(0, $faceplanted & ActorFlag::JumpGoalJump->mask());
    }

    public function testPandaProjectsExpressedGeneAndActivityAcrossBothFlagWords(): void
    {
        $panda = new PandaEntity(
            EntityUuid::random(),
            11,
            'world',
            new Position(0.0, 64.0, 0.0),
            mainGene: PandaGene::BROWN,
            hiddenGene: PandaGene::BROWN,
            activity: PandaActivity::ROLLING,
        );
        $metadata = (new BedrockLivingActorProjector())->metadata($panda);

        self::assertSame(PandaGene::BROWN->value, self::integer($metadata, 2));
        self::assertNotSame(0, self::integer($metadata, 92) & ActorFlag::Rolling->mask());
    }

    public function testLlamaProjectsCarpetStrengthAndChestedState(): void
    {
        $llama = new LlamaEntity(
            EntityUuid::random(),
            12,
            'world',
            new Position(0.0, 64.0, 0.0),
            strength: 4,
            chested: true,
            carpetColor: WoolColor::LIME,
            variant: LlamaVariant::BROWN,
        );
        $metadata = (new BedrockLivingActorProjector())->metadata($llama);

        self::assertSame(5, self::integer($metadata, 3));
        self::assertSame(LlamaVariant::BROWN->value, self::integer($metadata, 2));
        self::assertSame(4, self::integer($metadata, 75));
        self::assertSame(5, self::integer($metadata, 76));
        self::assertNotSame(0, self::integer($metadata, 0) & ActorFlag::Chested->mask());
    }

    public function testGoatProjectsHornCountAndRamState(): void
    {
        $goat = new GoatEntity(
            EntityUuid::random(),
            13,
            'world',
            new Position(0.0, 64.0, 0.0),
            leftHorn: false,
            ramming: true,
            ramTicks: 100,
            ramTargetUniqueId: EntityUuid::random(),
        );
        $metadata = (new BedrockLivingActorProjector())->metadata($goat);

        self::assertSame(1, self::integer($metadata, 122));
        self::assertNotSame(0, self::integer($metadata, 92) & ActorFlag::RamAttack->mask());
    }

    public function testFreezingStrengthIsProjectedForSpawnAndSubsequentMetadata(): void
    {
        $goat = new GoatEntity(
            EntityUuid::random(),
            64,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
        for ($tick = 1; $tick <= 70; ++$tick) {
            $goat->advanceFreezingState(true, true, $tick);
        }

        self::assertSame(0.5, self::float((new BedrockLivingActorProjector())->metadata($goat), 120));
    }

    public function testMetadataIsUniqueSortedAndClearsDetachedLeash(): void
    {
        $llama = new LlamaEntity(EntityUuid::random(), 14, 'world', new Position(0.0, 64.0, 0.0));
        $projector = new BedrockLivingActorProjector();
        $holder = EntityUuid::random();
        $llama->setLeashHolder($holder, 900);

        $attached = $projector->metadata($llama);
        $attachedIds = array_map(static fn(ActorMetadata $entry): int => $entry->id, $attached);
        self::assertSame($attachedIds, array_values(array_unique($attachedIds)));
        $sorted = $attachedIds;
        sort($sorted);
        self::assertSame($sorted, $attachedIds);
        self::assertSame(900, self::integer($attached, 37));
        self::assertNotSame(0, self::integer($attached, 0) & ActorFlag::Leashed->mask());

        $llama->setLeashHolder(null, null);
        $detached = $projector->metadata($llama);
        self::assertSame(-1, self::integer($detached, 37));
        self::assertSame(0, self::integer($detached, 0) & ActorFlag::Leashed->mask());
    }

    public function testMooshroomVariantUsesVariantMetadata(): void
    {
        $projector = new BedrockLivingActorProjector();
        $mooshroom = new MooshroomEntity(
            EntityUuid::random(),
            19,
            'world',
            new Position(0.0, 64.0, 0.0),
            variant: MooshroomVariant::BROWN,
        );

        self::assertSame(MooshroomVariant::BROWN->value, self::integer($projector->metadata($mooshroom), 2));
    }

    public function testPolarBearStandingAndArmadilloStateUseTheirCurrentWireChannels(): void
    {
        $projector = new BedrockLivingActorProjector();
        $polarBear = new PolarBearEntity(EntityUuid::random(), 15, 'world', new Position(0.0, 64.0, 0.0));
        $polarBear->setStanding(true);
        self::assertNotSame(
            0,
            self::integer($projector->metadata($polarBear), 0) & ActorFlag::Standing->mask(),
        );

        foreach (ArmadilloState::cases() as $expected => $state) {
            $armadillo = new ArmadilloEntity(
                EntityUuid::random(),
                20 + $expected,
                'world',
                new Position(0.0, 64.0, 0.0),
                state: $state,
            );
            $properties = $projector->properties($armadillo);
            self::assertCount(1, $properties->integers);
            self::assertSame(0, $properties->integers[0]->index);
            self::assertSame($expected, $properties->integers[0]->value);
        }
    }

    /** @param list<ActorMetadata> $metadata */
    private static function integer(array $metadata, int $id): int
    {
        foreach ($metadata as $entry) {
            if ($entry->id === $id && is_int($entry->value)) {
                return $entry->value;
            }
        }
        self::fail("Missing integer actor metadata {$id}.");
    }

    /** @param list<ActorMetadata> $metadata */
    private static function float(array $metadata, int $id): float
    {
        foreach ($metadata as $entry) {
            if ($entry->id === $id && is_float($entry->value)) {
                return $entry->value;
            }
        }
        self::fail("Missing float actor metadata {$id}.");
    }
}
