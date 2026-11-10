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

namespace Bedriox\Server\Tests\Api;

use Bedriox\Api\Form\ActionForm;
use Bedriox\Api\Form\Component\Dropdown;
use Bedriox\Api\Form\Component\Slider;
use Bedriox\Api\Form\Component\TextField;
use Bedriox\Api\Form\Component\Toggle;
use Bedriox\Api\Form\FormButton;
use Bedriox\Api\Form\MessageForm;
use Bedriox\Api\Form\ModalForm;
use Bedriox\Api\Form\ModalFormResponse;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position;
use PHPUnit\Framework\TestCase;

final class FormApiTest extends TestCase
{
    public function testActionFormUsesStableButtonIdentity(): void
    {
        $selected = null;
        $form = (new ActionForm('Games'))->setBody('Choose a game')->addButton(
            (new FormButton('Play'))->setId('play_games')->setTexture('textures/ui/play'),
        )->onSubmit(static function (Player $player, FormButton $button) use (&$selected): void {
            $selected = [$player->uuid, $button->id()];
        });

        self::assertSame([
            'type' => 'form',
            'title' => 'Games',
            'content' => 'Choose a game',
            'buttons' => [['text' => 'Play', 'image' => ['type' => 'path', 'data' => 'textures/ui/play']]],
        ], json_decode($form->toJson(), true, 8, JSON_THROW_ON_ERROR));
        $form->submit(self::player(), 0);
        self::assertSame(['00000000-0000-0000-0000-000000000001', 'play_games'], $selected);
    }

    public function testMessageFormReturnsStableButton(): void
    {
        $selected = null;
        $form = (new MessageForm('Confirm', 'Continue?'))
            ->setFirstButton((new FormButton('Yes'))->setId('yes'))
            ->setSecondButton((new FormButton('No'))->setId('no'))
            ->onSubmit(static function (Player $player, FormButton $button) use (&$selected): void {
                $selected = $button->id();
            });
        $form->submit(self::player(), false);
        self::assertSame('no', $selected);
    }

    public function testModalFormNormalizesValuesByComponentId(): void
    {
        $response = null;
        $form = (new ModalForm('Profile'))
            ->add(new TextField('name', 'Name'))
            ->add(new Toggle('alerts', 'Alerts'))
            ->add(new Slider('volume', 'Volume', 0.0, 10.0))
            ->add(new Dropdown('world', 'World', ['Earth', 'Mars']))
            ->onSubmit(static function (Player $player, ModalFormResponse $value) use (&$response): void {
                $response = $value;
            });
        $form->submit(self::player(), ['Alex', true, 7, 1]);
        self::assertInstanceOf(ModalFormResponse::class, $response);
        self::assertSame('Alex', $response->getString('name'));
        self::assertTrue($response->getBool('alerts'));
        self::assertSame(7.0, $response->getFloat('volume'));
        self::assertSame('Mars', $response->getString('world'));
    }

    private static function player(): Player
    {
        return new Player('Player', '00000000-0000-0000-0000-000000000001', new Position(0.0, 64.0, 0.0), 0.0, 0.0, false, false, new Inventory(array_fill(0, 36, null), 0));
    }
}
