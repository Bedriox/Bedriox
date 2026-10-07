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

namespace Bedriox\Server\Tests\Entity;

use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\SnifferEntity;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SnifferEntityQualificationTest extends TestCase
{
    public function testBabyCannotStartDiggingAndAdultCompletionIsExactOnce(): void
    {
        $baby = self::sniffer(baby: true);
        for ($step = 0; $step < 20; ++$step) {
            self::assertFalse($baby->advanceSniffing(20, true));
        }
        self::assertFalse($baby->isDigging());

        $adult = self::sniffer();
        self::assertFalse($adult->advanceSniffing(20, true));
        self::assertTrue($adult->isDigging());
        for ($step = 0; $step < 5; ++$step) {
            self::assertFalse($adult->advanceSniffing(20, true));
        }
        self::assertTrue($adult->advanceSniffing(20, true));
        self::assertFalse($adult->isDigging());
        self::assertFalse($adult->advanceSniffing(20, true));
    }

    public function testInvalidGroundInterruptsDigAndAppliesBoundedRetryCooldown(): void
    {
        $sniffer = self::sniffer();
        self::assertFalse($sniffer->advanceSniffing(20, true));
        self::assertTrue($sniffer->isDigging());
        self::assertFalse($sniffer->advanceSniffing(20, false));
        self::assertFalse($sniffer->isDigging());

        for ($step = 0; $step < 9; ++$step) {
            self::assertFalse($sniffer->advanceSniffing(20, true));
            self::assertFalse($sniffer->isDigging());
        }
        self::assertFalse($sniffer->advanceSniffing(20, true));
        self::assertTrue($sniffer->isDigging());
    }

    public function testRememberedSitesAreUniqueBoundedOldestFirstAndPersisted(): void
    {
        $sniffer = self::sniffer();
        for ($x = 0; $x < 20; ++$x) {
            $sniffer->rememberDigSite($x, 63, -$x);
        }
        $sniffer->rememberDigSite(5, 63, -5);
        self::assertSame(20, $sniffer->getRememberedDigSiteCount());

        $sniffer->rememberDigSite(20, 63, -20);
        self::assertSame(20, $sniffer->getRememberedDigSiteCount());
        self::assertFalse($sniffer->hasDugAt(0, 63, 0));
        self::assertTrue($sniffer->hasDugAt(5, 63, -5));
        self::assertTrue($sniffer->hasDugAt(20, 63, -20));

        $restored = self::sniffer();
        $restored->restorePersistenceState(
            $sniffer->persistenceVariant(),
            $sniffer->persistenceSchemaVersion(),
            $sniffer->persistenceData(),
        );
        self::assertSame(20, $restored->getRememberedDigSiteCount());
        self::assertFalse($restored->hasDugAt(0, 63, 0));
        self::assertTrue($restored->hasDugAt(5, 63, -5));
        self::assertTrue($restored->hasDugAt(20, 63, -20));
    }

    #[DataProvider('malformedPersistenceProvider')]
    public function testMalformedPersistenceFailsClosed(int|string|null $variant, int $schema, string $payload): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::sniffer()->restorePersistenceState($variant, $schema, $payload);
    }

    /** @return iterable<string, array{int|string|null, int, string}> */
    public static function malformedPersistenceProvider(): iterable
    {
        $valid = json_decode(self::sniffer()->persistenceData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($valid);

        yield 'variant' => [1, 1, json_encode($valid, JSON_THROW_ON_ERROR)];
        yield 'schema' => [null, 2, json_encode($valid, JSON_THROW_ON_ERROR)];
        yield 'missing field' => [null, 1, json_encode(array_diff_key($valid, ['diggingTicks' => true]), JSON_THROW_ON_ERROR)];
        yield 'wrong digging type' => [null, 1, json_encode([...$valid, 'digging' => 1], JSON_THROW_ON_ERROR)];
        yield 'negative timer' => [null, 1, json_encode([...$valid, 'diggingTicks' => -1], JSON_THROW_ON_ERROR)];
        yield 'oversized timer' => [null, 1, json_encode([...$valid, 'sniffCooldownTicks' => 12_001], JSON_THROW_ON_ERROR)];
        yield 'duplicate site' => [null, 1, json_encode([...$valid, 'rememberedDigSites' => '1,63,1;1,63,1'], JSON_THROW_ON_ERROR)];
        yield 'noncanonical site' => [null, 1, json_encode([...$valid, 'rememberedDigSites' => '01,63,1'], JSON_THROW_ON_ERROR)];
        yield 'out of bounds site' => [null, 1, json_encode([...$valid, 'rememberedDigSites' => '30000001,63,1'], JSON_THROW_ON_ERROR)];
        yield 'too many sites' => [null, 1, json_encode([
            ...$valid,
            'rememberedDigSites' => implode(';', array_map(
                static fn(int $x): string => $x . ',63,0',
                range(0, 20),
            )),
        ], JSON_THROW_ON_ERROR)];
    }

    public function testTimerAndCoordinateInputsAreBounded(): void
    {
        $rejections = 0;
        foreach ([0, 21] as $ticks) {
            try {
                self::sniffer()->advanceSniffing($ticks, true);
                self::fail("Sniffer accepted an invalid {$ticks}-tick activity advance.");
            } catch (InvalidArgumentException) {
                ++$rejections;
            }
        }

        foreach ([[30_000_001, 63, 0], [0, -2_049, 0], [0, 2_049, 0]] as [$x, $y, $z]) {
            try {
                self::sniffer()->rememberDigSite($x, $y, $z);
                self::fail('Sniffer accepted an out-of-world dig site.');
            } catch (InvalidArgumentException) {
                ++$rejections;
            }
        }
        self::assertSame(5, $rejections);
    }

    private static function sniffer(bool $baby = false): SnifferEntity
    {
        return new SnifferEntity(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.5, 64.0, 0.5),
            baby: $baby,
        );
    }
}
