<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Api\Command;

use Bedriox\Api\Command\AbstractCommand;
use Bedriox\Api\Command\AllowedCommandSenders;
use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AbstractCommandTest extends TestCase
{
    public function testBaseClassBuildsDefinitionAndProvidesNoArgumentDefault(): void
    {
        $command = new ExampleCommand();
        $definition = $command->definition();

        self::assertSame('example', $definition->name);
        self::assertSame('Example command', $definition->description);
        self::assertSame(['sample'], $definition->aliases);
        self::assertSame('example.command', $definition->permission);
        self::assertSame(AllowedCommandSenders::PLAYER_ONLY, $definition->allowedSenders);
        self::assertSame(['/example'], $command->defineArguments()->usage('example'));
    }

    public function testResultFactoriesCarryStatusAndOptionalOutput(): void
    {
        $success = CommandResult::success('Done');
        $failure = CommandResult::failure('Unable to complete the command.');

        self::assertTrue($success->isSuccess());
        self::assertSame('Done', $success->message());
        self::assertFalse($failure->isSuccess());
        self::assertSame('Unable to complete the command.', $failure->message());
    }

    public function testEmptyResultMessageIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CommandResult::failure('');
    }
}

final class ExampleCommand extends AbstractCommand
{
    public function __construct()
    {
        parent::__construct('example', 'Example command');
    }

    protected function aliases(): array
    {
        return ['sample'];
    }

    protected function permission(): string
    {
        return 'example.command';
    }

    protected function allowedSenders(): AllowedCommandSenders
    {
        return AllowedCommandSenders::PLAYER_ONLY;
    }

    public function execute(CommandContext $context): CommandResult
    {
        return $this->success();
    }
}
