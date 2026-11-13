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

namespace Bedriox\Server\Tests\Runtime\PlayerRoster;

use Bedriox\Protocol\Packet\PlayerListAddEntry;
use Bedriox\Protocol\Packet\PlayerSkin;
use Bedriox\Protocol\Value\BuildPlatform;
use Bedriox\Server\Runtime\PlayerRoster\OnlinePlayerRoster;
use LogicException;
use PHPUnit\Framework\TestCase;

final class OnlinePlayerRosterTest extends TestCase
{
    public function testEveryViewerConvergesOnOneServerWideRoster(): void
    {
        $roster = new OnlinePlayerRoster(4);
        $first = self::entry('00000000-0000-0000-0000-000000000001', 1, 'First', 'skin-first');
        $second = self::entry('00000000-0000-0000-0000-000000000002', 2, 'Second', 'skin-second');

        $firstRevision = $roster->admit('first-session', $first);
        $roster->acknowledgeOwner('first-session', $first->uuid, $firstRevision);
        self::assertSame(['additions' => [], 'removals' => []], $roster->plan('first-session'));

        $secondRevision = $roster->admit('second-session', $second);
        $roster->acknowledgeOwner('second-session', $second->uuid, $secondRevision);

        $forFirst = $roster->plan('first-session');
        self::assertSame([$second->uuid], array_column($forFirst['additions'], 'identity'));
        self::assertSame([], $forFirst['removals']);
        $roster->acknowledgeAdditions('first-session', $forFirst['additions']);

        $forSecond = $roster->plan('second-session');
        self::assertSame([$first->uuid], array_column($forSecond['additions'], 'identity'));
        self::assertSame([], $forSecond['removals']);
        $roster->acknowledgeAdditions('second-session', $forSecond['additions']);

        for ($transfer = 0; $transfer < 1_000; ++$transfer) {
            self::assertSame(['additions' => [], 'removals' => []], $roster->plan('first-session'));
            self::assertSame(['additions' => [], 'removals' => []], $roster->plan('second-session'));
        }
    }

    public function testSkinReplacementIsOneRevisionAndDoesNotDuplicateTheOwner(): void
    {
        $roster = new OnlinePlayerRoster(2);
        $first = self::entry('00000000-0000-0000-0000-000000000001', 1, 'First', 'skin-first');
        $second = self::entry('00000000-0000-0000-0000-000000000002', 2, 'Second', 'skin-second');
        $firstRevision = $roster->admit('first-session', $first);
        $roster->acknowledgeOwner('first-session', $first->uuid, $firstRevision);
        $secondRevision = $roster->admit('second-session', $second);
        $roster->acknowledgeOwner('second-session', $second->uuid, $secondRevision);
        $forFirst = $roster->plan('first-session');
        $roster->acknowledgeAdditions('first-session', $forFirst['additions']);
        $forSecond = $roster->plan('second-session');
        $roster->acknowledgeAdditions('second-session', $forSecond['additions']);

        $replacement = self::entry($first->uuid, 1, 'First', 'skin-replacement');
        $replacementRevision = $roster->replace('first-session', $replacement);
        $roster->acknowledgeOwner('first-session', $first->uuid, $replacementRevision);

        self::assertSame(['additions' => [], 'removals' => []], $roster->plan('first-session'));
        $peerPlan = $roster->plan('second-session');
        self::assertCount(1, $peerPlan['additions']);
        self::assertSame($first->uuid, $peerPlan['additions'][0]['identity']);
        self::assertSame('skin-replacement', $peerPlan['additions'][0]['entry']->skin->skinId);
        $roster->acknowledgeAdditions('second-session', $peerPlan['additions']);
        self::assertSame(['additions' => [], 'removals' => []], $roster->plan('second-session'));
    }

    public function testActualDepartureProducesOneRemovalForEveryRemainingViewer(): void
    {
        $roster = new OnlinePlayerRoster(3);
        $entries = [
            'first-session' => self::entry('00000000-0000-0000-0000-000000000001', 1, 'First', 'skin-first'),
            'second-session' => self::entry('00000000-0000-0000-0000-000000000002', 2, 'Second', 'skin-second'),
            'third-session' => self::entry('00000000-0000-0000-0000-000000000003', 3, 'Third', 'skin-third'),
        ];
        foreach ($entries as $sessionId => $entry) {
            $revision = $roster->admit($sessionId, $entry);
            $roster->acknowledgeOwner($sessionId, $entry->uuid, $revision);
        }
        foreach (array_keys($entries) as $sessionId) {
            $plan = $roster->plan($sessionId);
            $roster->acknowledgeAdditions($sessionId, $plan['additions']);
        }

        self::assertTrue($roster->remove('second-session', $entries['second-session']->uuid));
        $roster->forgetViewer('second-session');
        foreach (['first-session', 'third-session'] as $sessionId) {
            $plan = $roster->plan($sessionId);
            self::assertSame([], $plan['additions']);
            self::assertSame([$entries['second-session']->uuid], $plan['removals']);
            $roster->acknowledgeRemovals($sessionId, $plan['removals']);
            self::assertSame(['additions' => [], 'removals' => []], $roster->plan($sessionId));
        }
    }

    public function testDuplicateIdentityAndSessionOwnershipFailClosed(): void
    {
        $roster = new OnlinePlayerRoster(2);
        $entry = self::entry('00000000-0000-0000-0000-000000000001', 1, 'First', 'skin-first');
        $roster->admit('first-session', $entry);

        $this->expectException(LogicException::class);
        $roster->admit('second-session', self::entry(strtoupper($entry->uuid), 2, 'Duplicate', 'skin-duplicate'));
    }

    public function testOneHundredFiftyPlayersRemainExactThroughTransferSkinAndDepartureChurn(): void
    {
        $roster = new OnlinePlayerRoster(150);
        $entries = [];
        for ($index = 1; $index <= 150; ++$index) {
            $sessionId = 'session-' . $index;
            $entry = self::entry(
                sprintf('00000000-0000-0000-0000-%012d', $index),
                $index,
                'Player' . $index,
                'skin-' . $index,
            );
            $entries[$sessionId] = $entry;
            $revision = $roster->admit($sessionId, $entry);
            $roster->acknowledgeOwner($sessionId, $entry->uuid, $revision);
        }

        foreach (array_keys($entries) as $sessionId) {
            $plan = $roster->plan($sessionId);
            self::assertCount(149, $plan['additions']);
            self::assertSame([], $plan['removals']);
            $roster->acknowledgeAdditions($sessionId, $plan['additions']);
        }

        // World transfers never mutate this server-wide state, even under sustained churn.
        for ($transfer = 0; $transfer < 100; ++$transfer) {
            foreach (array_keys($entries) as $sessionId) {
                self::assertSame(['additions' => [], 'removals' => []], $roster->plan($sessionId));
            }
        }

        for ($index = 1; $index <= 25; ++$index) {
            $sessionId = 'session-' . $index;
            $current = $entries[$sessionId];
            $replacement = self::entry(
                $current->uuid,
                $current->uniqueEntityId,
                $current->name,
                'replacement-' . $index,
            );
            $revision = $roster->replace($sessionId, $replacement);
            $roster->acknowledgeOwner($sessionId, $replacement->uuid, $revision);
        }
        foreach (array_keys($entries) as $sessionId) {
            $plan = $roster->plan($sessionId);
            $expected = str_starts_with($sessionId, 'session-')
                && (int) substr($sessionId, strlen('session-')) <= 25 ? 24 : 25;
            self::assertCount($expected, $plan['additions']);
            self::assertSame([], $plan['removals']);
            $roster->acknowledgeAdditions($sessionId, $plan['additions']);
        }

        for ($index = 126; $index <= 150; ++$index) {
            $sessionId = 'session-' . $index;
            self::assertTrue($roster->remove($sessionId, $entries[$sessionId]->uuid));
            $roster->forgetViewer($sessionId);
        }
        self::assertSame(125, $roster->count());
        for ($index = 1; $index <= 125; ++$index) {
            $sessionId = 'session-' . $index;
            $plan = $roster->plan($sessionId);
            self::assertSame([], $plan['additions']);
            self::assertCount(25, $plan['removals']);
            $roster->acknowledgeRemovals($sessionId, $plan['removals']);
            self::assertSame(['additions' => [], 'removals' => []], $roster->plan($sessionId));
        }
    }

    private static function entry(string $uuid, int $actorId, string $name, string $skinId): PlayerListAddEntry
    {
        return new PlayerListAddEntry(
            strtolower($uuid),
            $actorId,
            $name,
            (string) $actorId,
            '',
            BuildPlatform::Unknown,
            new PlayerSkin(
                64,
                64,
                str_repeat("\0", 64 * 64 * 4),
                0,
                0,
                '',
                '{}',
                [],
                skinId: $skinId,
                playFabId: '',
                resourcePatchJson: '{"geometry":{"default":"geometry.humanoid.custom"}}',
            ),
            colorArgb: 0xffffffff,
        );
    }
}
