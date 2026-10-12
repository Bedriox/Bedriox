<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace Bedriox\Tools;

use InvalidArgumentException;

final class VanillaCatalogGenerator
{
    private const string IDENTIFIER_PATTERN = '/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D';

    /** @var array<string, string> Canonical Bedrock identifiers whose historical names need a clear API name. */
    private const array CONSTANT_NAME_OVERRIDES = [
        'minecraft:wooden_door' => 'OAK_DOOR',
    ];

    /**
     * @param list<string> $identifiers
     */
    public static function render(string $namespace, string $className, array $identifiers): string
    {
        if (preg_match('/^(?:[A-Z][A-Za-z0-9]*\\\\)*[A-Z][A-Za-z0-9]*$/D', $namespace) !== 1) {
            throw new InvalidArgumentException('Catalog namespace is invalid.');
        }
        if (preg_match('/^[A-Z][A-Za-z0-9]*$/D', $className) !== 1) {
            throw new InvalidArgumentException('Catalog class name is invalid.');
        }

        $identifiers = array_values(array_unique($identifiers));
        sort($identifiers, SORT_STRING);
        if ($identifiers === []) {
            throw new InvalidArgumentException('Catalog identifiers must not be empty.');
        }

        /** @var array<string, string> $constants */
        $constants = [];
        foreach ($identifiers as $identifier) {
            if (preg_match(self::IDENTIFIER_PATTERN, $identifier) !== 1) {
                throw new InvalidArgumentException("Catalog identifier '{$identifier}' is not canonical.");
            }
            $constant = self::constantName($identifier);
            if (isset($constants[$constant]) && $constants[$constant] !== $identifier) {
                throw new InvalidArgumentException(
                    "Catalog identifiers '{$constants[$constant]}' and '{$identifier}' both map to {$constant}.",
                );
            }
            $constants[$constant] = $identifier;
        }
        ksort($constants, SORT_STRING);

        $declarations = '';
        foreach ($constants as $constant => $identifier) {
            $declarations .= "    public const string {$constant} = '{$identifier}';\n";
        }

        return <<<'PHP'
<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

PHP
            . "\nnamespace {$namespace};\n\n"
            . "/** Exact canonical identifiers generated from the active Bedrock data set. */\n"
            . "final class {$className}\n{\n"
            . $declarations
            . "}\n";
    }

    public static function isCurrent(string $path, string $expected): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $actual = file_get_contents($path);
        return is_string($actual) && hash_equals($expected, $actual);
    }

    private static function constantName(string $identifier): string
    {
        if (isset(self::CONSTANT_NAME_OVERRIDES[$identifier])) {
            return self::CONSTANT_NAME_OVERRIDES[$identifier];
        }
        [$namespace, $path] = explode(':', $identifier, 2);
        $name = $namespace === 'minecraft' ? $path : $namespace . '_' . $path;
        $name = strtoupper((string) preg_replace('/[^a-z0-9]+/', '_', $name));
        $name = trim($name, '_');
        if ($name === '') {
            throw new InvalidArgumentException("Catalog identifier '{$identifier}' has no usable constant name.");
        }
        if (ctype_digit($name[0])) {
            $name = '_' . $name;
        }

        return $name;
    }
}
