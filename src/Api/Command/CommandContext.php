<?php

declare(strict_types=1);

namespace Bedriox\Api\Command;

final readonly class CommandContext
{
    /** @param list<string> $arguments */
    public function __construct(
        private CommandSender $sender,
        private string $label,
        private array $arguments,
    ) {}

    public function sender(): CommandSender
    {
        return $this->sender;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** @return list<string> */
    public function arguments(): array
    {
        return $this->arguments;
    }
}
