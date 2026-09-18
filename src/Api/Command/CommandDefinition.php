<?php

declare(strict_types=1);

namespace Bedriox\Api\Command;

use InvalidArgumentException;

final readonly class CommandDefinition
{
    /** @var list<string> */
    public array $aliases;

    /** @param list<string> $aliases */
    public function __construct(
        public string $name,
        public string $description,
        public string $usage,
        array $aliases = [],
        public ?string $permission = null,
        public AllowedCommandSenders $allowedSenders = AllowedCommandSenders::ANY,
    ) {
        self::validateName($name);
        self::validateText($description, 'description', 256);
        self::validateText($usage, 'usage', 256);
        if ($permission !== null) {
            self::validateIdentifier($permission, 'permission', 128);
        }
        if (count($aliases) > 16) {
            throw new InvalidArgumentException('A command may have at most 16 aliases.');
        }
        $normalized = [];
        foreach ($aliases as $alias) {
            self::validateName($alias);
            $key = strtolower($alias);
            if ($key === strtolower($name) || isset($normalized[$key])) {
                throw new InvalidArgumentException('Command aliases must be unique.');
            }
            $normalized[$key] = $alias;
        }
        $this->aliases = array_values($normalized);
    }

    public static function validateName(string $name): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $name) !== 1) {
            throw new InvalidArgumentException('Command names must use lowercase ASCII letters, digits, underscores, or hyphens.');
        }
    }

    private static function validateIdentifier(string $value, string $label, int $maximum): void
    {
        if (strlen($value) > $maximum || preg_match('/^[a-z][a-z0-9_.-]*$/D', $value) !== 1) {
            throw new InvalidArgumentException("Command {$label} is invalid.");
        }
    }

    private static function validateText(string $value, string $label, int $maximum): void
    {
        if ($value === '' || strlen($value) > $maximum || preg_match('//u', $value) !== 1 || str_contains($value, "\0")) {
            throw new InvalidArgumentException("Command {$label} must be valid, bounded text.");
        }
    }
}
