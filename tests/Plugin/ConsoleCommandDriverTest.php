<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Plugin;

use Bedriox\Api\Command\CommandDefinition;
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
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Runtime\RuntimeDriver;
use PHPUnit\Framework\TestCase;

final class ConsoleCommandDriverTest extends TestCase
{
    public function testInputAndExecutionAreBoundedPerPoll(): void
    {
        $plugins = new RecordingPluginControl();
        $ownership = new PluginOwnershipRegistry();
        $execution = new PluginExecutionContext();
        $actions = new PluginActionBuffer();
        $events = new EventDispatcher($plugins, $execution, $actions, $ownership);
        $registry = new CommandRegistry($plugins, $execution, $actions, $ownership, $events);
        $calls = 0;
        $registry->register('Tools', new CommandDefinition('run', 'Run', 'run'), static function () use (&$calls): CommandResult {
            ++$calls;
            return CommandResult::SUCCESS;
        });
        $logger = new ServerLogger(static function (): void {}, LogLevel::DEBUG, true, false, null);
        $input = new ArrayConsoleInput([['run', 'run', 'run'], []]);
        $runtime = new CountingDriver();
        $driver = new ConsoleCommandDriver($runtime, $input, $registry, new ServerConsoleCommandSender($logger), $logger, 3, 2);

        self::assertTrue($driver->poll());
        self::assertSame(2, $calls);
        self::assertTrue($driver->poll());
        self::assertSame(3, $calls);
        $driver->close();
        self::assertSame(1, $runtime->closes);
        self::assertTrue($input->closed);
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
