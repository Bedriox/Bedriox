<?php

declare(strict_types=1);

namespace Bedriox\Api\Command;

use InvalidArgumentException;

final class CommandArguments
{
    /**
     * @param list<CommandParameter> $defaultParameters
     * @param list<CommandOverload>  $explicitOverloads
     */
    private function __construct(
        private array $defaultParameters = [],
        private array $explicitOverloads = [],
        private bool $usesDefaultOverload = false,
    ) {}

    public static function create(): self
    {
        return new self();
    }

    public static function none(): self
    {
        return new self(usesDefaultOverload: true);
    }

    public function addArgument(CommandParameter $parameter): self
    {
        if ($this->explicitOverloads !== []) {
            throw new InvalidArgumentException('Direct command arguments cannot be combined with explicit overloads.');
        }

        $overload = CommandOverload::create();
        foreach ($this->defaultParameters as $existing) {
            $overload = $overload->addArgument($existing);
        }
        $overload = $overload->addArgument($parameter);

        $arguments = clone $this;
        $arguments->defaultParameters = $overload->parameters();
        $arguments->usesDefaultOverload = true;

        return $arguments;
    }

    public function addOverload(CommandOverload $overload): self
    {
        if ($this->usesDefaultOverload) {
            throw new InvalidArgumentException('Explicit overloads cannot be combined with direct command arguments.');
        }
        if (count($this->explicitOverloads) >= 32) {
            throw new InvalidArgumentException('A command may have at most 32 overloads.');
        }

        $signature = $overload->signature();
        foreach ($this->explicitOverloads as $existing) {
            if ($existing->signature() === $signature) {
                throw new InvalidArgumentException('A command cannot declare duplicate or indistinguishable overloads.');
            }
        }

        $arguments = clone $this;
        $arguments->explicitOverloads[] = $overload;

        return $arguments;
    }

    /** @return non-empty-list<CommandOverload> */
    public function overloads(): array
    {
        if ($this->explicitOverloads !== []) {
            return $this->explicitOverloads;
        }

        $overload = CommandOverload::create();
        foreach ($this->defaultParameters as $parameter) {
            $overload = $overload->addArgument($parameter);
        }

        return [$overload];
    }

    /** @return non-empty-list<string> */
    public function usage(string $commandName): array
    {
        return array_map(
            static fn(CommandOverload $overload): string => $overload->usage($commandName),
            $this->overloads(),
        );
    }
}
