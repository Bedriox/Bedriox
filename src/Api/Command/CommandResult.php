<?php

declare(strict_types=1);

namespace Bedriox\Api\Command;

use InvalidArgumentException;

final readonly class CommandResult
{
    private function __construct(
        private bool $successful,
        private ?string $message,
    ) {
        if ($message !== null && ($message === '' || strlen($message) > 1_024 || preg_match('//u', $message) !== 1 || str_contains($message, "\0"))) {
            throw new InvalidArgumentException('A command result message must be valid, bounded text.');
        }
    }

    public static function success(?string $message = null): self
    {
        return new self(true, $message);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }

    public function isSuccess(): bool
    {
        return $this->successful;
    }

    public function message(): ?string
    {
        return $this->message;
    }
}
