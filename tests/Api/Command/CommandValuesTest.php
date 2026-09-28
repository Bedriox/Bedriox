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

namespace Bedriox\Server\Tests\Api\Command;

use Bedriox\Api\Command\CommandValues;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\Position;
use LogicException;
use PHPUnit\Framework\TestCase;

final class CommandValuesTest extends TestCase
{
    public function testTypedValuesAreRetrievedByParameterName(): void
    {
        $player = self::player();
        $position = new Position(1.5, 64.0, -2.5);
        $blockPosition = new BlockPosition(1, 64, -3);
        $values = new CommandValues([
            'name' => 'Alex',
            'amount' => 4,
            'distance' => 2,
            'enabled' => true,
            'player' => $player,
            'targets' => [$player],
            'mode' => ExampleMode::CREATIVE,
            'position' => $position,
            'block' => $blockPosition,
            'message' => 'hello world',
            'data' => ['key' => 'value'],
        ]);

        self::assertSame('Alex', $values->string('name'));
        self::assertSame(4, $values->integer('amount'));
        self::assertSame(2.0, $values->float('distance'));
        self::assertTrue($values->boolean('enabled'));
        self::assertSame($player, $values->player('player'));
        self::assertSame([$player], $values->players('targets'));
        self::assertSame(ExampleMode::CREATIVE, $values->enum('mode', ExampleMode::class));
        self::assertSame($position, $values->position('position'));
        self::assertSame($blockPosition, $values->blockPosition('block'));
        self::assertSame('hello world', $values->message('message'));
        self::assertSame(['key' => 'value'], $values->json('data'));
        self::assertTrue($values->has('name'));
        self::assertFalse($values->has('missing'));
    }

    public function testMissingAndWronglyTypedValuesFailClearly(): void
    {
        $values = new CommandValues(['amount' => 4]);

        try {
            $values->string('missing');
            self::fail('A missing command value was accepted.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('not present', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $values->string('amount');
    }

    private static function player(): Player
    {
        return new Player(
            'Alex',
            '00000000-0000-0000-0000-000000000001',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
        );
    }
}

enum ExampleMode: string
{
    case SURVIVAL = 'survival';
    case CREATIVE = 'creative';
}
