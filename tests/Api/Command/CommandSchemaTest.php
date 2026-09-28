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

use Bedriox\Api\Command\CommandArguments;
use Bedriox\Api\Command\CommandOverload;
use Bedriox\Api\Command\CommandParameter;
use Bedriox\Api\Command\CommandParameterType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommandSchemaTest extends TestCase
{
    public function testDirectArgumentsProduceOneOrderedOverloadAndUsage(): void
    {
        $integer = CommandParameter::integer('amount')->minimum(1)->maximum(64)->optional(default: 1);
        $arguments = CommandArguments::create()
            ->addArgument(CommandParameter::onlinePlayer('player'))
            ->addArgument($integer);

        self::assertSame(['/test <player> [amount]'], $arguments->usage('test'));
        self::assertSame(
            [CommandParameterType::ONLINE_PLAYER, CommandParameterType::INTEGER],
            array_map(static fn(CommandParameter $parameter): CommandParameterType => $parameter->type(), $arguments->overloads()[0]->parameters()),
        );
        self::assertTrue($integer->hasDefault());
        self::assertSame(1, $integer->defaultValue());
        self::assertSame(1, $integer->minimumValue());
        self::assertSame(64, $integer->maximumValue());
    }

    public function testParameterBuildersAreImmutable(): void
    {
        $base = CommandParameter::integer('amount');
        $bounded = $base->minimum(1)->maximum(4)->optional(default: 2);

        self::assertNull($base->minimumValue());
        self::assertFalse($base->isOptional());
        self::assertSame(1, $bounded->minimumValue());
        self::assertTrue($bounded->isOptional());
    }

    public function testExplicitOverloadsAllowDistinctLiteralBranches(): void
    {
        $arguments = CommandArguments::create()
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal('give'))
                ->addArgument(CommandParameter::onlinePlayer('player')))
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::literal('clear'))
                ->addArgument(CommandParameter::onlinePlayer('player')));

        self::assertSame(['/test give <player>', '/test clear <player>'], $arguments->usage('test'));
    }

    public function testLongOptionLiteralUsesAStableValueNameAndGeneratedUsage(): void
    {
        $arguments = CommandArguments::create()
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::string('project')))
            ->addOverload(CommandOverload::create()
                ->addArgument(CommandParameter::string('project'))
                ->addArgument(CommandParameter::literal('--overwrite')));

        self::assertSame(
            ['/makeplugin <project>', '/makeplugin <project> --overwrite'],
            $arguments->usage('makeplugin'),
        );
        self::assertSame('overwrite', $arguments->overloads()[1]->parameters()[1]->name());
    }

    /** @return iterable<string, array{callable(): void}> */
    public static function invalidSchemaProvider(): iterable
    {
        yield 'duplicate names' => [static function (): void {
            CommandOverload::create()
                ->addArgument(CommandParameter::string('value'))
                ->addArgument(CommandParameter::integer('value'));
        }];
        yield 'required after optional' => [static function (): void {
            CommandOverload::create()
                ->addArgument(CommandParameter::string('first')->optional())
                ->addArgument(CommandParameter::string('second'));
        }];
        yield 'argument after greedy' => [static function (): void {
            CommandOverload::create()
                ->addArgument(CommandParameter::message('message'))
                ->addArgument(CommandParameter::string('later'));
        }];
        yield 'duplicate choices ignoring case' => [static function (): void {
            CommandParameter::choice('mode', ['Creative', 'creative']);
        }];
        yield 'invalid range' => [static function (): void {
            CommandParameter::integer('amount')->minimum(2)->maximum(1);
        }];
        yield 'default outside range' => [static function (): void {
            CommandParameter::integer('amount')->minimum(1)->maximum(4)->optional(default: 5);
        }];
        yield 'indistinguishable overloads' => [static function (): void {
            $overload = CommandOverload::create()->addArgument(CommandParameter::string('first'));
            CommandArguments::create()->addOverload($overload)->addOverload(
                CommandOverload::create()->addArgument(CommandParameter::string('second')),
            );
        }];
        yield 'indistinguishable reordered choices' => [static function (): void {
            CommandArguments::create()
                ->addOverload(CommandOverload::create()->addArgument(CommandParameter::choice('first', ['one', 'two'])))
                ->addOverload(CommandOverload::create()->addArgument(CommandParameter::choice('second', ['TWO', 'ONE'])));
        }];
        yield 'mixed direct and explicit overloads' => [static function (): void {
            CommandArguments::create()
                ->addArgument(CommandParameter::string('value'))
                ->addOverload(CommandOverload::create());
        }];
    }

    #[DataProvider('invalidSchemaProvider')]
    public function testInvalidSchemasFailDuringDefinition(callable $define): void
    {
        $this->expectException(InvalidArgumentException::class);
        $define();
    }
}
