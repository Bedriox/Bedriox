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

use Bedriox\Server\Bedriox;
use Bedriox\Tools\CiWorkflowValidator;
use Bedriox\Tools\IntegrationManifestValidator;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/CiWorkflowValidator.php';
require __DIR__ . '/IntegrationManifestValidator.php';

$root = dirname(__DIR__);

/** @return array<string, mixed> */
function readJsonObject(string $path, string $label): array
{
    $json = file_get_contents($path);
    if ($json === false) {
        throw new RuntimeException("Unable to read {$label}.");
    }
    try {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException("Invalid {$label}: " . $exception->getMessage(), previous: $exception);
    }
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new RuntimeException("{$label} root must be an object.");
    }
    /** @var array<string, mixed> $decoded */
    return $decoded;
}

try {
    $manifest = readJsonObject($root . '/bedriox.lock.json', 'bedriox.lock.json');
    $composer = readJsonObject($root . '/composer.json', 'composer.json');
    $composerLock = readJsonObject($root . '/composer.lock', 'composer.lock');
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

$siblingHeads = [];
foreach (['protocol' => 'Protocol', 'raknet' => 'RakNet', 'data' => 'Data'] as $component => $directory) {
    $sibling = dirname($root) . DIRECTORY_SEPARATOR . $directory;
    $gitMetadata = $sibling . DIRECTORY_SEPARATOR . '.git';
    if (!is_dir($sibling) || (!is_dir($gitMetadata) && !is_file($gitMetadata))) {
        continue;
    }
    $process = proc_open(
        ['git', '-C', $sibling, 'rev-parse', 'HEAD'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        $siblingHeads[$component] = '<unavailable>';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $siblingHeads[$component] = $exitCode === 0 && is_string($stdout)
        ? trim($stdout)
        : '<unavailable:' . (is_string($stderr) ? trim($stderr) : '') . '>';
}

$errors = IntegrationManifestValidator::validate(
    $manifest,
    $composer,
    $composerLock,
    Bedriox::VERSION,
    $siblingHeads,
);
$workflow = file_get_contents($root . '/.github/workflows/ci.yml');
if ($workflow === false) {
    $errors[] = 'Unable to read .github/workflows/ci.yml.';
} else {
    $errors = array_merge($errors, CiWorkflowValidator::validate($workflow));
}
if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Integration manifest validation passed.' . PHP_EOL);
