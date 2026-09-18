<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/docs'));
$markdownFiles = [$root . '/README.md'];

foreach ($iterator as $file) {
    if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'md') {
        $markdownFiles[] = $file->getPathname();
    }
}

$errors = [];
foreach ($markdownFiles as $markdownFile) {
    $contents = file_get_contents($markdownFile);
    if ($contents === false) {
        $errors[] = "Unable to read {$markdownFile}";
        continue;
    }

    preg_match_all('/\[[^\]]*\]\((?!https?:|mailto:|#)([^)#]+)(?:#[^)]+)?\)/', $contents, $matches);
    foreach ($matches[1] as $relativeTarget) {
        $target = dirname($markdownFile) . DIRECTORY_SEPARATOR . rawurldecode($relativeTarget);
        if (!file_exists($target)) {
            $errors[] = sprintf('%s links to missing %s', substr($markdownFile, strlen($root) + 1), $relativeTarget);
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Documentation link validation passed." . PHP_EOL);
