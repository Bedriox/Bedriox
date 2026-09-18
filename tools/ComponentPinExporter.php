<?php

declare(strict_types=1);

namespace Bedriox\Tools;

use InvalidArgumentException;

final class ComponentPinExporter
{
    /** @var array<string, string> */
    private const array COMPONENT_PACKAGES = [
        'protocol' => 'bedriox/protocol',
        'raknet' => 'bedriox/raknet',
        'data' => 'bedriox/data',
    ];

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, string>
     */
    public static function export(array $manifest): array
    {
        if (($manifest['schema'] ?? null) !== 2) {
            throw new InvalidArgumentException('Manifest schema must be 2.');
        }

        $components = $manifest['components'] ?? null;
        if (!is_array($components) || array_is_list($components)) {
            throw new InvalidArgumentException('Manifest components must be an object.');
        }

        $expectedNames = array_keys(self::COMPONENT_PACKAGES);
        $actualNames = array_keys($components);
        sort($expectedNames);
        sort($actualNames);
        if ($actualNames !== $expectedNames) {
            throw new InvalidArgumentException('Manifest component set differs from the CI component set.');
        }

        $pins = [];
        foreach (self::COMPONENT_PACKAGES as $name => $package) {
            $component = $components[$name] ?? null;
            if (!is_array($component) || array_is_list($component) || ($component['package'] ?? null) !== $package) {
                throw new InvalidArgumentException("Manifest component {$name} does not have the expected package identity.");
            }
            $commit = $component['commit'] ?? null;
            if (!is_string($commit) || preg_match('/^[0-9a-f]{40}$/D', $commit) !== 1) {
                throw new InvalidArgumentException("Manifest component {$name} does not have a valid immutable commit.");
            }
            $pins["{$name}_commit"] = $commit;
        }

        return $pins;
    }
}
