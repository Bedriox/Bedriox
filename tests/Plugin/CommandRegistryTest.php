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

use Bedriox\Api\Command\AllowedCommandSenders;
use Bedriox\Api\Command\Command;
use Bedriox\Api\Command\CommandArguments;
use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandParameter;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\Command\CommandSender;
use Bedriox\Api\Command\CommandSenderType;
use Bedriox\Api\Event\Command\CommandDispatchedEvent;
use Bedriox\Api\Event\Command\CommandPreDispatchEvent;
use Bedriox\Api\Event\EventPriority;
use Bedriox\Server\Plugin\Command\CommandLineParser;
use Bedriox\Server\Plugin\Command\CommandRegistry;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class CommandRegistryTest extends TestCase
{
    public function testDispatchUsesTypedValuesAliasesNamespacesDefaultsAndResultMessages(): void
    {
        [$registry] = $this->registry();
        $seen = [];
        $registry->register('Tools', new TestCommand(
            new CommandDefinition('build', 'Build a plugin', ['make'], 'tools.build', AllowedCommandSenders::CONSOLE_ONLY),
            CommandArguments::create()
                ->addArgument(CommandParameter::string('project'))
                ->addArgument(CommandParameter::integer('count')->minimum(1)->optional(default: 1)),
            static function (CommandContext $context) use (&$seen): CommandResult {
                $seen[] = [$context->label(), $context->values()->string('project'), $context->values()->integer('count')];

                return CommandResult::success('Created');
            },
        ));
        $sender = new RecordingCommandSender(CommandSenderType::CONSOLE, ['tools.build']);

        $first = $registry->dispatch($sender, 'make "Example Plugin"');
        self::assertTrue($first->isSuccess());
        self::assertSame(['make', 'Example Plugin', 1], $seen[0]);
        self::assertSame(['Created'], $sender->messages);

        $second = $registry->dispatch($sender, 'tools:build Another 2');
        self::assertTrue($second->isSuccess());
        self::assertSame(['tools:build', 'Another', 2], $seen[1]);
    }

    public function testBindingFailureSendsGeneratedUsageAndSkipsPluginCode(): void
    {
        [$registry] = $this->registry();
        $calls = 0;
        $registry->register('Tools', new TestCommand(
            new CommandDefinition('build', 'Build a plugin'),
            CommandArguments::create()
                ->addArgument(CommandParameter::string('project'))
                ->addArgument(CommandParameter::integer('count')->minimum(1)->optional(default: 1)),
            static function () use (&$calls): CommandResult {
                ++$calls;

                return CommandResult::success();
            },
        ));
        $sender = new RecordingCommandSender(CommandSenderType::CONSOLE);

        $result = $registry->dispatch($sender, 'build Example invalid');

        self::assertFalse($result->isSuccess());
        self::assertSame(0, $calls);
        self::assertSame([
            "Argument 'count' must be a whole number.",
            'Usage: /build <project> [count]',
        ], $sender->messages);
    }

    public function testSenderAndPermissionRestrictionsAreCheckedBeforeExecution(): void
    {
        [$registry] = $this->registry();
        $calls = 0;
        $registry->register('Tools', new TestCommand(
            new CommandDefinition(
                'secure',
                'Restricted command',
                permission: 'tools.secure',
                allowedSenders: AllowedCommandSenders::CONSOLE_ONLY,
            ),
            CommandArguments::none(),
            static function () use (&$calls): CommandResult {
                ++$calls;

                return CommandResult::success();
            },
        ));

        $wrongSender = new RecordingCommandSender(CommandSenderType::PLAYER, ['tools.secure']);
        $missingPermission = new RecordingCommandSender(CommandSenderType::CONSOLE);

        self::assertFalse($registry->dispatch($wrongSender, 'secure')->isSuccess());
        self::assertSame(['This command cannot be used by this sender.'], $wrongSender->messages);
        self::assertFalse($registry->dispatch($missingPermission, 'secure')->isSuccess());
        self::assertSame(['You do not have permission to use this command.'], $missingPermission->messages);
        self::assertSame(0, $calls);
    }

    public function testPreAndPostEventsReceiveTheSameTypedValues(): void
    {
        [$registry, $events] = $this->registry();
        $order = [];
        $events->register('Observer', CommandPreDispatchEvent::class, static function (CommandPreDispatchEvent $event) use (&$order): void {
            $value = $event->values->choice('action');
            $order[] = 'pre:' . $value;
            if ($value === 'cancel') {
                $event->cancel();
            }
        }, EventPriority::NORMAL);
        $events->register('Observer', CommandDispatchedEvent::class, static function (CommandDispatchedEvent $event) use (&$order): void {
            $order[] = 'post:' . $event->values->choice('action') . ':' . ($event->result->isSuccess() ? 'success' : 'failure');
        });
        $registry->register('Tools', new TestCommand(
            new CommandDefinition('run', 'Run an action'),
            CommandArguments::create()->addArgument(CommandParameter::choice('action', ['run', 'cancel'])),
            static function (CommandContext $context) use (&$order): CommandResult {
                $order[] = 'command:' . $context->values()->choice('action');

                return CommandResult::success();
            },
        ));
        $sender = new RecordingCommandSender(CommandSenderType::CONSOLE);

        self::assertFalse($registry->dispatch($sender, 'run cancel')->isSuccess());
        self::assertSame(['pre:cancel'], $order);
        self::assertTrue($registry->dispatch($sender, 'run run')->isSuccess());
        self::assertSame(['pre:cancel', 'pre:run', 'command:run', 'post:run:success'], $order);
    }

    public function testPluginFailureDisablesOnlyOwnerAndOwnershipCleanupRemovesCommand(): void
    {
        [$registry, , $plugins, $ownership] = $this->registry();
        $registry->register('Tools', new TestCommand(
            new CommandDefinition('explode', 'Explode'),
            CommandArguments::none(),
            static function (): CommandResult {
                throw new RuntimeException('failure');
            },
        ));

        $result = $registry->dispatch(new RecordingCommandSender(CommandSenderType::CONSOLE), 'explode');

        self::assertFalse($result->isSuccess());
        self::assertSame(['Tools'], $plugins->disabled);
        self::assertSame('command', $plugins->frames[0]?->operation);
        self::assertSame(1, $registry->count());
        $ownership->releaseAll('Tools');
        self::assertSame(0, $registry->count());
    }

    public function testRegistrationsAreBoundedAndManualUnregisterReleasesOwnership(): void
    {
        [$registry, , , $ownership] = $this->registry(1);
        $subscription = $registry->register('Tools', self::emptyCommand('first'));
        self::assertSame(1, $ownership->count('Tools'));

        try {
            $registry->register('Tools', self::emptyCommand('second'));
            self::fail('The registry accepted a command beyond its configured limit.');
        } catch (PluginException $failure) {
            self::assertStringContainsString('limit', $failure->getMessage());
        }

        $subscription->unregister();
        self::assertFalse($subscription->isRegistered());
        self::assertSame(0, $ownership->count('Tools'));
        self::assertSame(0, $registry->count());
    }

    public function testOwnedSoftEnumsBindCurrentValuesPublishUpdatesAndCleanUpWithTheirPlugin(): void
    {
        [$registry, , , $ownership] = $this->registry();
        $kits = $registry->registerSoftEnum('Tools', 'kits', ['starter', 'builder']);
        $seen = [];
        $registry->register('Tools', new TestCommand(
            new CommandDefinition('kit', 'Select a kit'),
            CommandArguments::create()->addArgument(CommandParameter::softEnum('kit', $kits)),
            static function (CommandContext $context) use (&$seen): CommandResult {
                $seen[] = $context->values()->string('kit');

                return CommandResult::success();
            },
        ));
        $sender = new RecordingCommandSender(CommandSenderType::CONSOLE);

        self::assertSame('bedriox:plugin:tools:kits', $kits->name());
        self::assertSame(['starter', 'builder'], $kits->values());
        self::assertTrue($registry->dispatch($sender, 'kit STARTER')->isSuccess());
        self::assertSame(['starter'], $seen);
        self::assertFalse($registry->dispatch($sender, 'kit vip')->isSuccess());

        self::assertTrue($kits->add('vip'));
        self::assertFalse($kits->add('VIP'));
        self::assertSame(['starter', 'builder', 'vip'], $kits->values());
        self::assertTrue($registry->dispatch($sender, 'kit VIP')->isSuccess());
        self::assertSame(['starter', 'vip'], $seen);
        self::assertEquals([
            new \Bedriox\Server\Plugin\Command\CommandSoftEnumUpdate(
                'bedriox:plugin:tools:kits',
                ['starter', 'builder', 'vip'],
            ),
        ], $registry->drainSoftEnumUpdates());
        self::assertSame([], $registry->drainSoftEnumUpdates());

        self::assertSame(2, $ownership->count('Tools'));
        $ownership->releaseAll('Tools');
        self::assertFalse($kits->isRegistered());
        self::assertSame(0, $registry->count());
    }

    public function testCommandsCannotBorrowAnotherOwnersSoftEnum(): void
    {
        [$registry] = $this->registry();
        $kits = $registry->registerSoftEnum('Tools', 'kits', ['starter']);

        $this->expectException(PluginException::class);
        $registry->register('Other', new TestCommand(
            new CommandDefinition('kit', 'Select a kit'),
            CommandArguments::create()->addArgument(CommandParameter::softEnum('kit', $kits)),
            static fn(): CommandResult => CommandResult::success(),
        ));
    }

    public function testAvailableCommandsApplySenderAndPermissionPolicy(): void
    {
        [$registry] = $this->registry();
        $registry->register('Tools', self::emptyCommand('public'));
        $registry->register('Tools', new TestCommand(
            new CommandDefinition('protected', 'Protected', permission: 'tools.use'),
            CommandArguments::none(),
            static fn(): CommandResult => CommandResult::success(),
        ));

        $sender = new RecordingCommandSender(CommandSenderType::PLAYER, ['tools.use']);
        self::assertSame(['public', 'protected'], array_map(
            static fn(CommandDefinition $definition): string => $definition->name,
            $registry->availableTo($sender),
        ));
    }

    public function testParserRejectsMalformedLines(): void
    {
        $parser = new CommandLineParser();
        self::assertSame(['run', 'two words'], $parser->parse("run 'two words'"));
        self::assertSame(
            ['data', '{"enabled": true, "label": "hello world"}'],
            $parser->parse('data {"enabled": true, "label": "hello world"}'),
        );

        $this->expectException(PluginException::class);
        $parser->parse('run "unterminated');
    }

    private static function emptyCommand(string $name): TestCommand
    {
        return new TestCommand(
            new CommandDefinition($name, ucfirst($name)),
            CommandArguments::none(),
            static fn(): CommandResult => CommandResult::success(),
        );
    }

    /** @return array{CommandRegistry, EventDispatcher, RecordingPluginControl, PluginOwnershipRegistry} */
    private function registry(int $maximumCommands = 1024): array
    {
        $plugins = new RecordingPluginControl();
        $ownership = new PluginOwnershipRegistry();
        $execution = new PluginExecutionContext();
        $actions = new PluginActionBuffer();
        $events = new EventDispatcher($plugins, $execution, $actions, $ownership);

        return [
            new CommandRegistry(
                $plugins,
                $execution,
                $actions,
                $ownership,
                $events,
                maximumCommands: $maximumCommands,
                maximumCommandsPerPlugin: $maximumCommands,
            ),
            $events,
            $plugins,
            $ownership,
        ];
    }
}

final readonly class TestCommand implements Command
{
    /** @var Closure(CommandContext): CommandResult */
    private Closure $handler;

    /** @param callable(CommandContext): CommandResult $handler */
    public function __construct(
        private CommandDefinition $commandDefinition,
        private CommandArguments $arguments,
        callable $handler,
    ) {
        $this->handler = Closure::fromCallable($handler);
    }

    public function definition(): CommandDefinition
    {
        return $this->commandDefinition;
    }

    public function defineArguments(): CommandArguments
    {
        return $this->arguments;
    }

    public function execute(CommandContext $context): CommandResult
    {
        return ($this->handler)($context);
    }
}

final class RecordingCommandSender implements CommandSender
{
    /** @var list<string> */
    public array $messages = [];

    /** @param list<string> $permissions */
    public function __construct(
        private readonly CommandSenderType $senderType,
        private readonly array $permissions = [],
    ) {}

    public function type(): CommandSenderType
    {
        return $this->senderType;
    }

    public function name(): string
    {
        return 'test';
    }

    public function sendMessage(string $message): void
    {
        $this->messages[] = $message;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}

final class RecordingPluginControl implements PluginRuntimeControl
{
    /** @var list<string> */
    public array $disabled = [];
    /** @var list<PluginExecutionFrame|null> */
    public array $frames = [];

    public function isEnabled(string $plugin): bool
    {
        return !in_array($plugin, $this->disabled, true);
    }

    public function version(string $plugin): string
    {
        return '1.0.0';
    }

    public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void
    {
        $this->disabled[] = $plugin;
        $this->frames[] = $frame;
    }
}
