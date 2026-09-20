<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Plugin;

use Bedriox\Api\Command\AllowedCommandSenders;
use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandJob;
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
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class CommandRegistryTest extends TestCase
{
    public function testDispatchParsesQuotesAliasesNamespacePermissionsAndUsage(): void
    {
        [$registry] = $this->registry();
        $seen = null;
        $registry->register('Tools', new CommandDefinition(
            'build',
            'Build a plugin',
            'build <name>',
            ['make'],
            'tools.build',
            AllowedCommandSenders::CONSOLE_ONLY,
        ), static function (CommandContext $context) use (&$seen): CommandResult {
            $seen = [$context->label(), $context->arguments()];

            return CommandResult::USAGE;
        });
        $sender = new RecordingCommandSender(CommandSenderType::CONSOLE, ['tools.build']);

        self::assertSame(CommandResult::USAGE, $registry->dispatch($sender, 'make "Example Plugin" --overwrite'));
        self::assertSame(['make', ['Example Plugin', '--overwrite']], $seen);
        self::assertSame(['Usage: build <name>'], $sender->messages);
        self::assertSame(CommandResult::USAGE, $registry->dispatch($sender, 'tools:build Example'));
    }

    public function testSenderAndPermissionRestrictionsAreCentral(): void
    {
        [$registry] = $this->registry();
        $calls = 0;
        $registry->register('Tools', new CommandDefinition(
            'secure',
            'Restricted',
            'secure',
            permission: 'tools.secure',
            allowedSenders: AllowedCommandSenders::CONSOLE_ONLY,
        ), static function () use (&$calls): CommandResult {
            ++$calls;

            return CommandResult::SUCCESS;
        });

        self::assertSame(CommandResult::FAILURE, $registry->dispatch(new RecordingCommandSender(CommandSenderType::PLAYER), 'secure'));
        self::assertSame(CommandResult::FAILURE, $registry->dispatch(new RecordingCommandSender(CommandSenderType::CONSOLE), 'secure'));
        self::assertSame(0, $calls);
    }

    public function testPreCancellationAndPostEventOrdering(): void
    {
        [$registry, $events] = $this->registry();
        $order = [];
        $events->register('Observer', CommandPreDispatchEvent::class, static function (CommandPreDispatchEvent $event) use (&$order): void {
            $order[] = 'pre';
            if ($event->arguments === ['cancel']) {
                $event->cancel();
            }
        }, EventPriority::NORMAL);
        $events->register('Observer', CommandDispatchedEvent::class, static function () use (&$order): void {
            $order[] = 'post';
        });
        $registry->register('Tools', new CommandDefinition('run', 'Run', 'run'), static function () use (&$order): CommandResult {
            $order[] = 'command';

            return CommandResult::SUCCESS;
        });
        $sender = new RecordingCommandSender(CommandSenderType::CONSOLE);

        self::assertSame(CommandResult::FAILURE, $registry->dispatch($sender, 'run cancel'));
        self::assertSame(['pre'], $order);
        self::assertSame(CommandResult::SUCCESS, $registry->dispatch($sender, 'run now'));
        self::assertSame(['pre', 'pre', 'command', 'post'], $order);
    }

    public function testFailureDisablesOnlyCommandOwnerAndCleanupRemovesCommand(): void
    {
        [$registry, , $plugins, $ownership] = $this->registry();
        $registry->register('Tools', new CommandDefinition('explode', 'Explode', 'explode'), static function (): CommandResult {
            throw new RuntimeException('failure');
        });

        self::assertSame(CommandResult::FAILURE, $registry->dispatch(new RecordingCommandSender(CommandSenderType::CONSOLE), 'explode'));
        self::assertSame(['Tools'], $plugins->disabled);
        self::assertSame('command', $plugins->frames[0]?->operation);
        $ownership->releaseAll('Tools');
        self::assertSame(0, $registry->count());
    }

    public function testCooperativeJobsAreBoundedAndCancelledWithOwner(): void
    {
        [$registry, , , $ownership] = $this->registry();
        $job = new CountingCommandJob(2);
        $subscription = $registry->submitJob('Tools', $job);

        $registry->pollJobs(1);
        self::assertTrue($subscription->isRunning());
        $registry->pollJobs(1);
        self::assertFalse($subscription->isRunning());
        self::assertFalse($job->cancelled);

        $second = new CountingCommandJob(10);
        $registry->submitJob('Tools', $second);
        $ownership->releaseAll('Tools');
        self::assertTrue($second->cancelled);
    }

    public function testJobPollingRotatesWithoutStarvingLaterJobs(): void
    {
        [$registry] = $this->registry();
        $jobs = [];
        for ($index = 0; $index < 5; ++$index) {
            $jobs[] = new CountingCommandJob(10);
            $registry->submitJob('Tools', $jobs[$index]);
        }

        $registry->pollJobs(2);
        $registry->pollJobs(2);
        $registry->pollJobs(2);

        self::assertSame([2, 1, 1, 1, 1], array_map(static fn(CountingCommandJob $job): int => $job->polls, $jobs));
    }

    public function testParserRejectsMalformedOrUnboundedLines(): void
    {
        $parser = new CommandLineParser();
        self::assertSame(['run', 'two words'], $parser->parse("run 'two words'"));
        $this->expectException(\Bedriox\Server\Plugin\PluginException::class);
        $parser->parse('run "unterminated');
    }

    public function testRegistrationsAreBoundedAndManualUnregisterReleasesOwnership(): void
    {
        [$registry, , , $ownership] = $this->registry(1);
        $subscription = $registry->register('Tools', new CommandDefinition('first', 'First', 'first'), static fn(): CommandResult => CommandResult::SUCCESS);
        self::assertSame(1, $ownership->count('Tools'));

        try {
            $registry->register('Tools', new CommandDefinition('second', 'Second', 'second'), static fn(): CommandResult => CommandResult::SUCCESS);
            self::fail('The registry accepted a command beyond its configured limit.');
        } catch (\Bedriox\Server\Plugin\PluginException $failure) {
            self::assertStringContainsString('limit', $failure->getMessage());
        }

        $subscription->unregister();
        self::assertSame(0, $ownership->count('Tools'));
    }

    public function testServerCommandsShareDispatchWithoutPluginLifecycleOwnership(): void
    {
        [$registry, , $plugins] = $this->registry();
        $subscription = $registry->registerServer(
            new CommandDefinition('version', 'Version', 'version', permission: 'bedriox.command.version'),
            static function (CommandContext $context): CommandResult {
                $context->sender()->sendMessage('Bedriox test');
                return CommandResult::SUCCESS;
            },
        );
        $denied = new RecordingCommandSender(CommandSenderType::PLAYER);
        $allowed = new RecordingCommandSender(CommandSenderType::PLAYER, ['bedriox.command.version']);

        self::assertSame(CommandResult::FAILURE, $registry->dispatch($denied, '/version'));
        self::assertSame(CommandResult::SUCCESS, $registry->dispatch($allowed, '/bedriox:version'));
        self::assertSame(['Bedriox test'], $allowed->messages);
        self::assertSame(['version'], array_map(
            static fn(CommandDefinition $definition): string => $definition->name,
            $registry->availableTo($allowed),
        ));
        self::assertSame([], $plugins->disabled);
        $subscription->unregister();
        self::assertSame(0, $registry->count());
    }

    /** @return array{CommandRegistry, EventDispatcher, RecordingPluginControl, PluginOwnershipRegistry} */
    private function registry(int $maximumCommands = 1024): array
    {
        $plugins = new RecordingPluginControl();
        $ownership = new PluginOwnershipRegistry();
        $execution = new PluginExecutionContext();
        $actions = new PluginActionBuffer();
        $events = new EventDispatcher($plugins, $execution, $actions, $ownership);

        return [new CommandRegistry($plugins, $execution, $actions, $ownership, $events, maximumCommands: $maximumCommands, maximumCommandsPerPlugin: $maximumCommands), $events, $plugins, $ownership];
    }
}

final class RecordingCommandSender implements CommandSender
{
    /** @var list<string> */
    public array $messages = [];
    /** @param list<string> $permissions */
    public function __construct(private readonly CommandSenderType $senderType, private readonly array $permissions = []) {}
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

final class CountingCommandJob implements CommandJob
{
    public bool $cancelled = false;
    public int $polls = 0;
    public function __construct(private readonly int $finishAfter) {}
    public function poll(): bool
    {
        return ++$this->polls >= $this->finishAfter;
    }
    public function cancel(): void
    {
        $this->cancelled = true;
    }
}
