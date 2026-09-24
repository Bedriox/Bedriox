<?php

declare(strict_types=1);

namespace Bedriox\Api\Command;

use BackedEnum;
use InvalidArgumentException;

final class CommandParameter
{
    /** @param list<string> $choices */
    private function __construct(
        private readonly string $name,
        private readonly CommandParameterType $type,
        private readonly array $choices = [],
        private readonly ?string $enumClass = null,
        private readonly ?CommandSoftEnum $softEnum = null,
        private readonly ?string $literal = null,
        private bool $optional = false,
        private bool $hasDefault = false,
        private mixed $default = null,
        private int|float|null $minimum = null,
        private int|float|null $maximum = null,
    ) {
        self::validateName($name);
    }

    public static function string(string $name): self
    {
        return new self($name, CommandParameterType::STRING);
    }

    public static function integer(string $name): self
    {
        return new self($name, CommandParameterType::INTEGER);
    }

    public static function float(string $name): self
    {
        return new self($name, CommandParameterType::FLOAT);
    }

    public static function boolean(string $name): self
    {
        return new self($name, CommandParameterType::BOOLEAN);
    }

    public static function onlinePlayer(string $name): self
    {
        return new self($name, CommandParameterType::ONLINE_PLAYER);
    }

    public static function players(string $name): self
    {
        return new self($name, CommandParameterType::PLAYERS);
    }

    /** @param list<string> $choices */
    public static function choice(string $name, array $choices): self
    {
        return new self($name, CommandParameterType::CHOICE, self::normalizeChoices($choices));
    }

    /** @param class-string<BackedEnum> $enumClass */
    public static function enum(string $name, string $enumClass): self
    {
        if (!is_subclass_of($enumClass, BackedEnum::class)) {
            throw new InvalidArgumentException('A command enum parameter must use a backed enum.');
        }

        $choices = [];
        foreach ($enumClass::cases() as $case) {
            $choices[] = (string) $case->value;
        }

        return new self($name, CommandParameterType::ENUM, self::normalizeChoices($choices), $enumClass);
    }

    public static function softEnum(string $name, CommandSoftEnum $softEnum): self
    {
        return new self($name, CommandParameterType::SOFT_ENUM, softEnum: $softEnum);
    }

    public static function position(string $name): self
    {
        return new self($name, CommandParameterType::POSITION);
    }

    public static function blockPosition(string $name): self
    {
        return new self($name, CommandParameterType::BLOCK_POSITION);
    }

    public static function message(string $name): self
    {
        return new self($name, CommandParameterType::MESSAGE);
    }

    public static function json(string $name): self
    {
        return new self($name, CommandParameterType::JSON);
    }

    public static function rawText(string $name): self
    {
        return new self($name, CommandParameterType::RAW_TEXT);
    }

    public static function literal(string $literal, ?string $name = null): self
    {
        if (preg_match('/^(?:[a-z][a-z0-9_-]{0,31}|--[a-z][a-z0-9-]{0,30})$/D', $literal) !== 1) {
            throw new InvalidArgumentException('A command literal must be a bounded lowercase identifier or long option.');
        }
        $name ??= str_replace('-', '_', ltrim($literal, '-'));

        return new self($name, CommandParameterType::LITERAL, literal: $literal);
    }

    public function optional(mixed $default = null): self
    {
        if ($this->type === CommandParameterType::LITERAL) {
            throw new InvalidArgumentException('Command literals cannot be optional. Use a separate overload instead.');
        }
        $parameter = clone $this;
        $parameter->optional = true;
        $parameter->hasDefault = func_num_args() === 1;
        $parameter->default = $default;
        $parameter->validateDefault();

        return $parameter;
    }

    public function minimum(int|float $minimum): self
    {
        $this->requireNumericConstraint();
        $parameter = clone $this;
        $parameter->minimum = $minimum;
        $parameter->validateRange();
        $parameter->validateDefault();

        return $parameter;
    }

    public function maximum(int|float $maximum): self
    {
        $this->requireNumericConstraint();
        $parameter = clone $this;
        $parameter->maximum = $maximum;
        $parameter->validateRange();
        $parameter->validateDefault();

        return $parameter;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): CommandParameterType
    {
        return $this->type;
    }

    public function isOptional(): bool
    {
        return $this->optional;
    }

    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    public function defaultValue(): mixed
    {
        if (!$this->hasDefault) {
            throw new InvalidArgumentException("Command parameter '{$this->name}' does not have a default value.");
        }

        return $this->default;
    }

    public function minimumValue(): int|float|null
    {
        return $this->minimum;
    }

    public function maximumValue(): int|float|null
    {
        return $this->maximum;
    }

    /** @return list<string> */
    public function choices(): array
    {
        return $this->choices;
    }

    /** @return class-string<BackedEnum>|null */
    public function enumClass(): ?string
    {
        /** @var class-string<BackedEnum>|null */
        return $this->enumClass;
    }

    public function softEnumValue(): ?CommandSoftEnum
    {
        return $this->softEnum;
    }

    public function literalValue(): ?string
    {
        return $this->literal;
    }

    public function usageToken(): string
    {
        if ($this->literal !== null) {
            return $this->literal;
        }

        $description = $this->name;
        if ($this->choices !== []) {
            $description .= ':' . implode('|', $this->choices);
        }

        return $this->optional ? "[{$description}]" : "<{$description}>";
    }

    public function signatureToken(): string
    {
        $choices = array_map(strtolower(...), $this->choices);
        sort($choices);

        return implode(':', [
            $this->type->value,
            $this->literal ?? '',
            $this->softEnum?->name() ?? '',
            implode(',', $choices),
            $this->optional ? 'optional' : 'required',
        ]);
    }

    private static function validateName(string $name): void
    {
        if (preg_match('/^[a-z][a-zA-Z0-9_]{0,31}$/D', $name) !== 1) {
            throw new InvalidArgumentException('Command parameter names must be bounded identifiers beginning with a lowercase letter.');
        }
    }

    /** @param list<string> $choices
     *  @return list<string>
     */
    private static function normalizeChoices(array $choices): array
    {
        if ($choices === [] || count($choices) > 128) {
            throw new InvalidArgumentException('A command choice parameter must have between 1 and 128 choices.');
        }

        $normalized = [];
        foreach ($choices as $choice) {
            if ($choice === '' || strlen($choice) > 64 || preg_match('//u', $choice) !== 1 || str_contains($choice, "\0")) {
                throw new InvalidArgumentException('Command choices must be valid, bounded text.');
            }
            $key = strtolower($choice);
            if (isset($normalized[$key])) {
                throw new InvalidArgumentException('Command choices must be unique ignoring case.');
            }
            $normalized[$key] = $choice;
        }

        return array_values($normalized);
    }

    private function requireNumericConstraint(): void
    {
        if (!$this->type->isNumeric()) {
            throw new InvalidArgumentException('Minimum and maximum constraints require a numeric command parameter.');
        }
    }

    private function validateRange(): void
    {
        if ($this->minimum !== null && $this->maximum !== null && $this->minimum > $this->maximum) {
            throw new InvalidArgumentException('A command parameter minimum cannot exceed its maximum.');
        }
    }

    private function validateDefault(): void
    {
        if (!$this->hasDefault) {
            return;
        }
        if ($this->default === null) {
            return;
        }

        $valid = match ($this->type) {
            CommandParameterType::STRING, CommandParameterType::MESSAGE, CommandParameterType::RAW_TEXT => is_string($this->default),
            CommandParameterType::INTEGER => is_int($this->default),
            CommandParameterType::FLOAT => is_float($this->default) || is_int($this->default),
            CommandParameterType::BOOLEAN => is_bool($this->default),
            CommandParameterType::CHOICE => is_string($this->default) && in_array($this->default, $this->choices, true),
            CommandParameterType::ENUM => $this->default instanceof BackedEnum && $this->default::class === $this->enumClass,
            CommandParameterType::SOFT_ENUM => is_string($this->default),
            CommandParameterType::LITERAL => $this->default === $this->literal,
            default => true,
        };
        if (!$valid) {
            throw new InvalidArgumentException("Default value for command parameter '{$this->name}' has the wrong type.");
        }
        if (($this->type === CommandParameterType::INTEGER || $this->type === CommandParameterType::FLOAT)
            && (($this->minimum !== null && $this->default < $this->minimum)
                || ($this->maximum !== null && $this->default > $this->maximum))) {
            throw new InvalidArgumentException("Default value for command parameter '{$this->name}' is outside its range.");
        }
    }
}
