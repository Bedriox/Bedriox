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

const SOURCE_HEADER = <<<'HEADER'
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
HEADER;

$root = dirname(__DIR__);
$files = [$root . '/.php-cs-fixer.php', $root . '/bin/bedriox'];
foreach (['bootstrap', 'src', 'tests', 'tools'] as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
sort($files, SORT_STRING);

$errors = [];
$expected = "<?php\n\n" . trim(SOURCE_HEADER, "\n") . "\n\ndeclare(strict_types=1);";
$expectedExecutable = "#!/usr/bin/env php\n" . $expected;
foreach (array_unique($files) as $path) {
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        $errors[] = 'Unable to read ' . str_replace('\\', '/', substr($path, strlen($root) + 1));
        continue;
    }
    $normalized = str_replace("\r\n", "\n", $contents);
    $required = str_starts_with($normalized, "#!/usr/bin/env php\n") ? $expectedExecutable : $expected;
    if (!str_starts_with($normalized, $required)) {
        $errors[] = 'Missing or invalid source header: ' . str_replace('\\', '/', substr($path, strlen($root) + 1));
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, sprintf("Source header validation passed for %d PHP files.%s", count(array_unique($files)), PHP_EOL));
