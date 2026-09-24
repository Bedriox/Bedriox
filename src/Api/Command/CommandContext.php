<?php

declare(strict_types=1);

namespace Bedriox\Api\Command;

final readonly class CommandContext
{
    public function __construct(
        private CommandSender $sender,
        private string $label,
        private CommandValues $values,
    ) {}

    public function sender(): CommandSender
    {
        return $this->sender;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function values(): CommandValues
    {
        return $this->values;
    }
}
