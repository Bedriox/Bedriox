<?php

declare(strict_types=1);

namespace Bedriox\Api\Command;

use InvalidArgumentException;

final class CommandOverload
{
    /** @param list<CommandParameter> $parameters */
    private function __construct(private array $parameters = []) {}

    public static function create(): self
    {
        return new self();
    }

    public function addArgument(CommandParameter $parameter): self
    {
        if (count($this->parameters) >= 32) {
            throw new InvalidArgumentException('A command overload may have at most 32 parameters.');
        }

        $previous = $this->parameters === []
            ? null
            : $this->parameters[array_key_last($this->parameters)];
        if ($previous?->type()->isGreedy() === true) {
            throw new InvalidArgumentException('A greedy command parameter must be the final parameter.');
        }
        if ($previous?->isOptional() === true && !$parameter->isOptional()) {
            throw new InvalidArgumentException('A required command parameter cannot follow an optional parameter.');
        }
        foreach ($this->parameters as $existing) {
            if (strtolower($existing->name()) === strtolower($parameter->name())) {
                throw new InvalidArgumentException("Duplicate command parameter '{$parameter->name()}'.");
            }
        }

        $overload = clone $this;
        $overload->parameters[] = $parameter;

        return $overload;
    }

    /** @return list<CommandParameter> */
    public function parameters(): array
    {
        return $this->parameters;
    }

    public function usage(string $commandName): string
    {
        CommandDefinition::validateName($commandName);
        $suffix = implode(' ', array_map(
            static fn(CommandParameter $parameter): string => $parameter->usageToken(),
            $this->parameters,
        ));

        return '/' . $commandName . ($suffix === '' ? '' : ' ' . $suffix);
    }

    public function signature(): string
    {
        return implode(' ', array_map(
            static fn(CommandParameter $parameter): string => $parameter->signatureToken(),
            $this->parameters,
        ));
    }
}
