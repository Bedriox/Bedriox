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

namespace Bedriox\Server\Tests\Effect;

use Bedriox\Api\Effect\EffectInstance;
use Bedriox\Api\Effect\EffectType;
use Bedriox\Protocol\Packet\MobEffectType;
use Bedriox\Server\Effect\ActiveEffectCollection;
use Bedriox\Server\Effect\VanillaEffectBehavior;
use Bedriox\Server\Runtime\BedrockEffectTranslator;
use PHPUnit\Framework\TestCase;

final class ActiveEffectCollectionTest extends TestCase
{
    public function testEveryCurrentEffectHasAuthoritativeClassificationAndWireIdentity(): void
    {
        foreach (EffectType::cases() as $type) {
            self::assertNotSame([], VanillaEffectBehavior::domains($type), $type->value);
            self::assertInstanceOf(MobEffectType::class, BedrockEffectTranslator::type($type));
        }
    }

    public function testStrongerShortEffectPromotesElapsedWeakerFallback(): void
    {
        $effects = new ActiveEffectCollection();
        $weak = new EffectInstance(EffectType::SPEED, 200, 0);
        $strong = new EffectInstance(EffectType::SPEED, 40, 1);

        self::assertTrue($effects->add($weak)->visibleStateChanged);
        self::assertTrue($effects->add($strong)->visibleStateChanged);
        self::assertSame($strong, $effects->get(EffectType::SPEED));

        $transitions = $effects->tick(40, static function (): void {});

        self::assertCount(1, $transitions);
        self::assertNotNull($transitions[0]->current);
        self::assertSame(160, $transitions[0]->current->durationTicks);
        self::assertSame(0, $transitions[0]->current->amplifier);
    }

    public function testWeakerShortEffectIsIgnored(): void
    {
        $effects = new ActiveEffectCollection();
        $strong = new EffectInstance(EffectType::STRENGTH, 100, 2);
        $effects->add($strong);

        $transition = $effects->add(new EffectInstance(EffectType::STRENGTH, 20, 0));

        self::assertFalse($transition->visibleStateChanged);
        self::assertSame($strong, $effects->get(EffectType::STRENGTH));
    }

    public function testLongerEqualAmplifierReplacesCurrentEffect(): void
    {
        $effects = new ActiveEffectCollection();
        $effects->add(new EffectInstance(EffectType::NIGHT_VISION, 100));

        $replacement = new EffectInstance(EffectType::NIGHT_VISION, 200, visible: false);
        self::assertTrue($effects->add($replacement)->visibleStateChanged);
        self::assertSame($replacement, $effects->get(EffectType::NIGHT_VISION));
    }

    public function testInfiniteEffectDoesNotExpire(): void
    {
        $effects = new ActiveEffectCollection();
        $effect = new EffectInstance(EffectType::FIRE_RESISTANCE, 1, infinite: true);
        $effects->add($effect);

        self::assertSame([], $effects->tick(1_200, static function (): void {}));
        self::assertSame($effect, $effects->get(EffectType::FIRE_RESISTANCE));
    }

    public function testInfinitePeriodicEffectsKeepDeterministicCadence(): void
    {
        foreach ([
            [EffectType::REGENERATION, 50],
            [EffectType::POISON, 25],
            [EffectType::WITHER, 40],
        ] as [$type, $interval]) {
            $effects = new ActiveEffectCollection();
            $effects->add(new EffectInstance($type, 0, infinite: true));
            $applications = 0;
            for ($tick = 0; $tick < $interval - 1; ++$tick) {
                $effects->tick(1, static function () use (&$applications): void {
                    ++$applications;
                });
            }
            self::assertSame(0, $applications, $type->value);
            $effects->tick(1, static function () use (&$applications): void {
                ++$applications;
            });
            self::assertSame(1, $applications, $type->value);
            $effects->tick($interval * 2, static function () use (&$applications): void {
                ++$applications;
            });
            self::assertSame(3, $applications, $type->value);
        }
    }

    public function testRepeatedWeakerEffectsRetainOnlyMeaningfulFallbacks(): void
    {
        $effects = new ActiveEffectCollection();
        $effects->add(new EffectInstance(EffectType::SPEED, 40, 255));
        for ($amplifier = 0; $amplifier < 255; ++$amplifier) {
            for ($duration = 100; $duration <= 1_000; $duration += 100) {
                $effects->add(new EffectInstance(EffectType::SPEED, $duration, $amplifier));
            }
        }

        $effects->tick(40, static function (): void {});
        $promoted = $effects->get(EffectType::SPEED);
        self::assertNotNull($promoted);
        self::assertSame(254, $promoted->amplifier);
        self::assertSame(960, $promoted->durationTicks);
    }

    public function testPersistenceStateRestoresHiddenFallbackAndInfiniteCadence(): void
    {
        $source = new ActiveEffectCollection();
        $source->add(new EffectInstance(EffectType::SPEED, 200));
        $source->add(new EffectInstance(EffectType::SPEED, 40, 1));
        $source->add(new EffectInstance(EffectType::REGENERATION, 0, infinite: true));
        $source->tick(17, static function (): void {});

        $restored = new ActiveEffectCollection();
        $restored->restorePersistenceState($source->persistenceState());
        $applications = 0;
        $restored->tick(23, static function () use (&$applications): void {
            ++$applications;
        });
        self::assertSame(0, $applications);
        $promoted = $restored->get(EffectType::SPEED);
        self::assertNotNull($promoted);
        self::assertSame(160, $promoted->durationTicks);
        self::assertSame(0, $promoted->amplifier);

        $restored->tick(10, static function () use (&$applications): void {
            ++$applications;
        });
        self::assertSame(1, $applications);
    }

    public function testPeriodicApplicationsAreBoundedAcrossElapsedTicks(): void
    {
        $effects = new ActiveEffectCollection();
        $effects->add(new EffectInstance(EffectType::REGENERATION, 100));
        $applications = 0;

        $effects->tick(100, static function () use (&$applications): void {
            ++$applications;
        });

        self::assertSame(2, $applications);
    }

    public function testExpirationObserverCannotRetainAnExpiredEffect(): void
    {
        $effects = new ActiveEffectCollection();
        $effects->add(new EffectInstance(EffectType::SPEED, 1));

        $observed = false;
        self::assertCount(1, $effects->tick(
            1,
            static function (): void {},
            static function () use (&$observed): void {
                $observed = true;
            },
        ));
        self::assertTrue($observed);
        self::assertNull($effects->get(EffectType::SPEED));
    }

    public function testVanillaAttributeRulesComposeByType(): void
    {
        $active = [
            EffectType::SPEED->value => new EffectInstance(EffectType::SPEED, 100, 1),
            EffectType::SLOWNESS->value => new EffectInstance(EffectType::SLOWNESS, 100, 0),
            EffectType::RESISTANCE->value => new EffectInstance(EffectType::RESISTANCE, 100, 1),
            EffectType::STRENGTH->value => new EffectInstance(EffectType::STRENGTH, 100, 0),
            EffectType::WEAKNESS->value => new EffectInstance(EffectType::WEAKNESS, 100, 0),
        ];

        self::assertEqualsWithDelta(1.19, VanillaEffectBehavior::movementMultiplier($active), 0.00001);
        self::assertEqualsWithDelta(0.6, VanillaEffectBehavior::incomingDamageMultiplier($active), 0.00001);
        self::assertEqualsWithDelta(0.4, VanillaEffectBehavior::attackDamageModifier($active, 4.0), 0.00001);
    }

    public function testAuthoritativeMovementMiningAndSurvivalRulesUseEffectLevels(): void
    {
        $effects = [
            EffectType::HASTE->value => new EffectInstance(EffectType::HASTE, 100, 1),
            EffectType::MINING_FATIGUE->value => new EffectInstance(EffectType::MINING_FATIGUE, 100, 2),
            EffectType::JUMP_BOOST->value => new EffectInstance(EffectType::JUMP_BOOST, 100, 1),
            EffectType::FIRE_RESISTANCE->value => new EffectInstance(EffectType::FIRE_RESISTANCE, 100),
            EffectType::WATER_BREATHING->value => new EffectInstance(EffectType::WATER_BREATHING, 100),
            EffectType::HEALTH_BOOST->value => new EffectInstance(EffectType::HEALTH_BOOST, 100, 1),
            EffectType::ABSORPTION->value => new EffectInstance(EffectType::ABSORPTION, 100, 2),
            EffectType::LEVITATION->value => new EffectInstance(EffectType::LEVITATION, 100, 0),
        ];

        self::assertSame(2, VanillaEffectBehavior::miningHasteLevel($effects));
        self::assertSame(3, VanillaEffectBehavior::miningFatigueLevel($effects));
        self::assertSame(5.0, VanillaEffectBehavior::fallDamage($effects, 10.0));
        self::assertTrue(VanillaEffectBehavior::hasFireResistance($effects));
        self::assertTrue(VanillaEffectBehavior::canBreatheUnderwater($effects));
        self::assertSame(28.0, VanillaEffectBehavior::maximumHealth($effects));
        self::assertSame(12.0, VanillaEffectBehavior::absorptionCapacity($effects));
        self::assertEqualsWithDelta(0.01, VanillaEffectBehavior::levitationVelocity($effects, 0.0), 0.000001);

        $effects[EffectType::SLOW_FALLING->value] = new EffectInstance(EffectType::SLOW_FALLING, 100);
        self::assertSame(0.0, VanillaEffectBehavior::fallDamage($effects, 100.0));
    }
}
