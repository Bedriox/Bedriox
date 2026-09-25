<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$lockContents = file_get_contents($root . '/composer.lock');
if ($lockContents === false) {
    fwrite(STDERR, 'Unable to read composer.lock.' . PHP_EOL);
    exit(1);
}

try {
    /** @var mixed $lock */
    $lock = json_decode($lockContents, true, flags: JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fwrite(STDERR, 'Invalid composer.lock: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
if (!is_array($lock)) {
    fwrite(STDERR, 'Composer lock root must be an object.' . PHP_EOL);
    exit(1);
}

$allowedLicenses = ['Apache-2.0', 'BSD-2-Clause', 'BSD-3-Clause', 'GPL-3.0-only', 'ISC', 'MIT', 'Zlib'];
$approvedProduction = [
    'firebase/php-jwt' => [
        'version' => 'v7.1.1',
        'reference' => '9bc93bd7e3ee5bead4cd23c365ec12f3c1fb0a6a',
        'license' => 'BSD-3-Clause',
    ],
    'bedriox/protocol' => [
        'version' => '0.1.0-alpha.1',
        'reference' => '76e96d6404b65052b4cc1df87aeef45895a1c70b',
        'license' => 'GPL-3.0-only',
    ],
    'bedriox/data' => [
        'version' => '0.1.0-alpha.1',
        'reference' => 'c1887897ba56ef1e9cd978822a6eb5bb99ee8d1b',
        'license' => 'GPL-3.0-only',
    ],
    'bedriox/raknet' => [
        'version' => '0.1.0-alpha.1',
        'reference' => 'eddc38670977c8f01cb46802293e4bd9fe9eb7d5',
        'license' => 'GPL-3.0-only',
    ],
];
$errors = [];
$productionNames = [];

foreach (['packages', 'packages-dev'] as $section) {
    $packages = $lock[$section] ?? [];
    if (!is_array($packages)) {
        $errors[] = "Invalid {$section} section.";
        continue;
    }

    foreach ($packages as $package) {
        if (!is_array($package) || !isset($package['name']) || !is_string($package['name'])) {
            $errors[] = 'Package entry without a valid name.';
            continue;
        }
        $name = $package['name'];
        $licenses = $package['license'] ?? [];
        if (!is_array($licenses) || $licenses === []) {
            $errors[] = "{$name} has no declared license.";
            continue;
        }
        foreach ($licenses as $license) {
            if (!is_string($license) || !in_array($license, $allowedLicenses, true)) {
                $errors[] = sprintf('%s declares unreviewed license %s', $name, is_string($license) ? $license : '<invalid>');
            }
        }

        if ($section !== 'packages') {
            continue;
        }
        $productionNames[] = $name;
        $approved = $approvedProduction[$name] ?? null;
        if ($approved === null) {
            $errors[] = "Production dependency {$name} is not explicitly approved.";
            continue;
        }
        if (($package['version'] ?? null) !== $approved['version']) {
            $errors[] = "Production dependency {$name} has an unapproved version.";
        }
        if (!in_array($approved['license'], $licenses, true)) {
            $errors[] = "Production dependency {$name} does not declare its approved license.";
        }
        $source = $package['source'] ?? null;
        $dist = $package['dist'] ?? null;
        $reference = is_array($source) && isset($source['reference'])
            ? $source['reference']
            : (is_array($dist) ? ($dist['reference'] ?? null) : null);
        if ($reference !== $approved['reference']) {
            $errors[] = "Production dependency {$name} has an unapproved source reference.";
        }
    }
}

sort($productionNames);
$approvedNames = array_keys($approvedProduction);
sort($approvedNames);
if ($productionNames !== $approvedNames) {
    $errors[] = 'Locked production dependency set differs from the explicit approval list.';
}

$notices = file_get_contents($root . '/THIRD_PARTY_NOTICES.md');
if ($notices === false) {
    $errors[] = 'Third-party notices cannot be read.';
} else {
    foreach (['firebase/php-jwt', 'Copyright (c) 2011, Neuman Vong', 'Redistribution and use in source and binary forms'] as $requiredNotice) {
        if (!str_contains($notices, $requiredNotice)) {
            $errors[] = "Third-party notices lack required firebase/php-jwt text: {$requiredNotice}";
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Dependency license and notice validation passed.' . PHP_EOL);
