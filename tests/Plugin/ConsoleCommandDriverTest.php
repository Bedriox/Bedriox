<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Plugin;

use Bedriox\Api\Command\AbstractCommand;
use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Server\Observability\LogLevel;
use Bedriox\Server\Observability\ServerLogger;
use Bedriox\Server\Plugin\Command\CommandRegistry;
use Bedriox\Server\Plugin\Command\ConsoleCommandDriver;
use Bedriox\Server\Plugin\Command\ConsoleInput;
use Bedriox\Server\Plugin\Command\ServerConsoleCommandSender;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\Runtime\RuntimeDriver;
use Closure;
use PHPUnit\Framework\TestCase;
use Throwable;

final class ConsoleCommandDriverTest extends TestCase
{
    public function testInputAndTypedCommandExecutionAreBoundedPerPoll(): void
    {
        $plugins = new ConsolePluginControl();
        $ownership = new PluginOwnershipRegistry();
        $execution = new PluginExecutionContext();
        $actions = new PluginActionBuffer();
        $events = new EventDispatcher($plugins, $execution, $actions, $ownership);
        $registry = new CommandRegistry($plugins, $execution, $actions, $ownership, $events);
        $calls = 0;
        $registry->register('Tools', new CountingConsoleCommand(static function () use (&$calls): void {
            ++$calls;
        }));
        $logger = new ServerLogger(static function (): void {}, LogLevel::DEBUG, true, false, null);
        $input = new ArrayConsoleInput([['run', 'run', 'run'], []]);
        $runtime = new CountingDriver();
        $driver = new ConsoleCommandDriver(
            $runtime,
            $input,
            $registry,
            new ServerConsoleCommandSender($logger),
            $logger,
            maximumQueuedCommands: 3,
            maximumCommandsPerPoll: 2,
        );

        self::assertTrue($driver->poll());
        self::assertSame(2, $calls);
        self::assertTrue($driver->poll());
        self::assertSame(3, $calls);
        $driver->close();
        self::assertSame(1, $runtime->closes);
        self::assertTrue($input->closed);
    }
}

final class CountingConsoleCommand extends AbstractCommand
{
    /** @param Closure(): void $called */
    public function __construct(private readonly Closure $called)
    {
        parent::__construct('run', 'Run');
    }

    public function execute(CommandContext $context): CommandResult
    {
        ($this->called)();

        return $this->success();
    }
}

final class ArrayConsoleInput implements ConsoleInput
{
    public bool $closed = false;

    /** @param list<list<string>> $polls */
    public function __construct(private array $polls) {}

    public function readAvailable(): array
    {
        return array_shift($this->polls) ?? [];
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

final class CountingDriver implements RuntimeDriver
{
    public int $closes = 0;

    public function poll(): bool
    {
        return true;
    }

    public function close(): void
    {
        ++$this->closes;
    }
}

final class ConsolePluginControl implements PluginRuntimeControl
{
    public function isEnabled(string $plugin): bool
    {
        return true;
    }

    public function version(string $plugin): string
    {
        return '1.0.0';
    }

    public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void {}
}
