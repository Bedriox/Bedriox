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

namespace Bedriox\Server\Tests\Plugin;

use Bedriox\Api\Form\ActionForm;
use Bedriox\Api\Form\FormButton;
use Bedriox\Api\Form\FormCancelReason;
use Bedriox\Api\Form\FormSendMode;
use Bedriox\Api\Form\FormState;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position;
use Bedriox\Protocol\Packet\ClientboundCloseFormPacket;
use Bedriox\Protocol\Packet\ModalFormRequestPacket;
use Bedriox\Protocol\Packet\ModalFormResponsePacket;
use Bedriox\Server\Plugin\Form\FormRegistry;
use Bedriox\Server\Plugin\Form\FormResponseDisposition;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginManager;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use PHPUnit\Framework\TestCase;

final class FormRegistryTest extends TestCase
{
    public function testResponsesAreAtMostOnceAndQueueAdvances(): void
    {
        $packets = [];
        $registry = self::registry();
        $registry->connect('identity', 'session', static function ($packet, bool $immediate) use (&$packets): bool {
            $packets[] = $packet;
            return true;
        });
        $selected = [];
        $first = $registry->send('identity', 'session', self::form('first', $selected));
        $second = $registry->send('identity', 'session', self::form('second', $selected));
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame(FormState::SENT, $first->state());
        self::assertSame(FormState::QUEUED, $second->state());
        self::assertCount(1, $packets);
        self::assertInstanceOf(ModalFormRequestPacket::class, $packets[0]);

        $response = new ModalFormResponsePacket($first->id, '0', null);
        self::assertSame(FormResponseDisposition::HANDLED, $registry->respond('identity', 'session', $response, self::player()));
        self::assertSame(['first'], $selected);
        self::assertSame(FormState::COMPLETED, $first->state());
        self::assertSame(FormState::SENT, $second->state());
        self::assertCount(2, $packets);
        self::assertSame(FormResponseDisposition::SEMANTIC_VIOLATION, $registry->respond('identity', 'session', $response, self::player()));
        self::assertSame(['first'], $selected);
    }

    public function testUnknownIdIsIgnoredOnceThenBecomesViolation(): void
    {
        $registry = self::registry();
        $registry->connect('identity', 'session', static fn($packet, bool $immediate): bool => true);
        $response = new ModalFormResponsePacket(123, '0', null);
        self::assertSame(FormResponseDisposition::IGNORED_STALE, $registry->respond('identity', 'session', $response, self::player()));
        self::assertSame(FormResponseDisposition::SEMANTIC_VIOLATION, $registry->respond('identity', 'session', $response, self::player()));
    }

    public function testReplaceClosesActiveFormAndSendsReplacement(): void
    {
        $packets = [];
        $selected = [];
        $registry = self::registry();
        $registry->connect('identity', 'session', static function ($packet, bool $immediate) use (&$packets): bool {
            $packets[] = $packet;
            return true;
        });
        $first = $registry->send('identity', 'session', self::form('first', $selected));
        $second = $registry->send('identity', 'session', self::form('second', $selected), FormSendMode::REPLACE);
        self::assertSame(FormState::CANCELLED, $first?->state());
        self::assertSame(FormState::SENT, $second?->state());
        self::assertInstanceOf(ClientboundCloseFormPacket::class, $packets[1]);
        self::assertInstanceOf(ModalFormRequestPacket::class, $packets[2]);
    }

    public function testQueuedAndMalformedResponsesCannotRunCallbacks(): void
    {
        $selected = [];
        $registry = self::registry();
        $registry->connect('identity', 'session', static fn($packet, bool $immediate): bool => true);
        $first = $registry->send('identity', 'session', self::form('first', $selected));
        $second = $registry->send('identity', 'session', self::form('second', $selected));
        self::assertNotNull($first);
        self::assertNotNull($second);

        self::assertSame(
            FormResponseDisposition::SEMANTIC_VIOLATION,
            $registry->respond('identity', 'session', new ModalFormResponsePacket($second->id, '0', null), self::player()),
        );
        self::assertSame([], $selected);
        self::assertSame(FormState::SENT, $first->state());
        self::assertSame(FormState::QUEUED, $second->state());

        self::assertSame(
            FormResponseDisposition::SEMANTIC_VIOLATION,
            $registry->respond('identity', 'session', new ModalFormResponsePacket($first->id, '{', null), self::player()),
        );
        self::assertSame([], $selected);
        self::assertSame(FormState::INVALID, $first->state());
        self::assertSame(FormState::SENT, $second->state());
    }

    public function testFormSpecificLimitIsAppliedBeforeJsonDecoding(): void
    {
        $selected = [];
        $registry = self::registry();
        $registry->connect('identity', 'session', static fn($packet, bool $immediate): bool => true);
        $handle = $registry->send('identity', 'session', self::form('limited', $selected));
        self::assertNotNull($handle);

        $response = new ModalFormResponsePacket($handle->id, str_repeat(' ', 16) . '0', null);
        self::assertSame(
            FormResponseDisposition::SEMANTIC_VIOLATION,
            $registry->respond('identity', 'session', $response, self::player()),
        );
        self::assertSame(FormState::INVALID, $handle->state());
        self::assertSame([], $selected);
    }

    public function testUnknownFormPayloadIsNotDecoded(): void
    {
        $registry = self::registry();
        $registry->connect('identity', 'session', static fn($packet, bool $immediate): bool => true);

        self::assertSame(
            FormResponseDisposition::IGNORED_STALE,
            $registry->respond(
                'identity',
                'session',
                new ModalFormResponsePacket(123, str_repeat('[', ModalFormResponsePacket::MAXIMUM_RESPONSE_BYTES), null),
                self::player(),
            ),
        );
    }

    public function testConflictingResponseFieldsAreRejectedAndConsumedOnce(): void
    {
        $selected = [];
        $registry = self::registry();
        $registry->connect('identity', 'session', static fn($packet, bool $immediate): bool => true);
        $handle = $registry->send('identity', 'session', self::form('first', $selected));
        self::assertNotNull($handle);
        $response = new ModalFormResponsePacket($handle->id, '0', 0);

        self::assertSame(FormResponseDisposition::SEMANTIC_VIOLATION, $registry->respond('identity', 'session', $response, self::player()));
        self::assertSame(FormState::INVALID, $handle->state());
        self::assertSame([], $selected);
        self::assertSame(FormResponseDisposition::SEMANTIC_VIOLATION, $registry->respond('identity', 'session', $response, self::player()));
        self::assertSame([], $selected);
    }

    public function testDisconnectInvalidatesEveryOutstandingFormAndOldSession(): void
    {
        $selected = [];
        $registry = self::registry();
        $registry->connect('identity', 'session', static fn($packet, bool $immediate): bool => true);
        $first = $registry->send('identity', 'session', self::form('first', $selected));
        $second = $registry->send('identity', 'session', self::form('second', $selected));
        self::assertNotNull($first);
        self::assertNotNull($second);

        $registry->disconnect('identity', 'session');
        self::assertSame(FormState::CANCELLED, $first->state());
        self::assertSame(FormState::CANCELLED, $second->state());
        self::assertSame(
            FormResponseDisposition::IGNORED_STALE,
            $registry->respond('identity', 'session', new ModalFormResponsePacket($first->id, '0', null), self::player()),
        );
        self::assertSame([], $selected);
    }

    public function testFutureCancellationReasonIsContainedAsUnknown(): void
    {
        $reason = null;
        $registry = self::registry();
        $registry->connect('identity', 'session', static fn($packet, bool $immediate): bool => true);
        $form = (new ActionForm('Test'))->onClose(static function (Player $player, FormCancelReason $cancelReason) use (&$reason): void {
            $reason = $cancelReason;
        });
        $handle = $registry->send('identity', 'session', $form);
        self::assertNotNull($handle);

        self::assertSame(
            FormResponseDisposition::HANDLED,
            $registry->respond('identity', 'session', new ModalFormResponsePacket($handle->id, null, 255), self::player()),
        );
        self::assertSame(FormCancelReason::UNKNOWN, $reason);
        self::assertSame(FormState::CANCELLED, $handle->state());
    }

    public function testQueueIsStrictlyBounded(): void
    {
        $selected = [];
        $registry = self::registry();
        $registry->connect('identity', 'session', static fn($packet, bool $immediate): bool => true);
        for ($index = 0; $index <= 16; ++$index) {
            self::assertNotNull($registry->send('identity', 'session', self::form('form-' . $index, $selected)));
        }
        self::assertNull($registry->send('identity', 'session', self::form('overflow', $selected)));
    }

    public function testResponseFloodCannotConsumeTheActiveForm(): void
    {
        $selected = [];
        $registry = self::registry();
        $registry->connect('identity', 'session', static fn($packet, bool $immediate): bool => true);
        $handle = $registry->send('identity', 'session', self::form('valid', $selected));
        self::assertNotNull($handle);

        for ($index = 0; $index < 17; ++$index) {
            $registry->respond(
                'identity',
                'session',
                new ModalFormResponsePacket($handle->id + 1 + $index, '0', null),
                self::player(),
            );
        }

        self::assertSame(FormState::SENT, $handle->state());
        self::assertSame([], $selected);
        self::assertSame(
            FormResponseDisposition::SEMANTIC_VIOLATION,
            $registry->respond('identity', 'session', new ModalFormResponsePacket($handle->id, '0', null), self::player()),
        );
        self::assertSame(FormState::SENT, $handle->state());
        self::assertSame([], $selected);
    }

    private static function registry(): FormRegistry
    {
        $execution = new PluginExecutionContext();
        $ownership = new PluginOwnershipRegistry();
        return new FormRegistry(new PluginManager($execution, $ownership), $execution, $ownership);
    }
    /** @param list<string> $selected */
    private static function form(string $id, array &$selected): ActionForm
    {
        return (new ActionForm('Test'))->addButton((new FormButton('Select'))->setId($id))->onSubmit(static function (Player $player, FormButton $button) use (&$selected): void {
            $selected[] = $button->id();
        });
    }
    private static function player(): Player
    {
        return new Player('Player', '00000000-0000-0000-0000-000000000001', new Position(0.0, 64.0, 0.0), 0.0, 0.0, false, false, new Inventory(array_fill(0, 36, null), 0));
    }
}
