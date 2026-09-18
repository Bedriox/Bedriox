<?php

declare(strict_types=1);

namespace Bedriox\Tools;

final class IntegrationManifestValidator
{
    /** @var list<string> */
    private const array QUALIFIED_CLIENT_VERSIONS = ['1.26.50', '1.26.51'];

    /** @var list<int> */
    private const array QUALIFIED_NETWORK_PROTOCOLS = [2193];

    private const string QUALIFIED_RUNTIME_PHP = '8.4.25';

    /** @var list<string> */
    private const array REQUIRED_RUNTIME_EXTENSIONS = [
        'Core', 'ctype', 'curl', 'date', 'dom', 'fileinfo', 'filter', 'gmp', 'hash', 'iconv', 'json', 'leveldb',
        'libxml', 'mbstring', 'openssl', 'pcre', 'PDO', 'pdo_sqlite', 'Phar', 'random', 'Reflection', 'session', 'SimpleXML',
        'sockets', 'sodium', 'SPL', 'sqlite3', 'standard', 'tokenizer', 'xml', 'xmlreader', 'xmlwriter',
        'Zend OPcache', 'zip', 'zlib',
    ];

    /** @var list<string> */
    private const array REQUIRED_UNIX_RUNTIME_EXTENSIONS = ['pcntl', 'posix'];

    /** @var array<string, array{filename: string, threadSafe: bool}> */
    private const array RUNTIME_TARGETS = [
        'windows-x86_64' => ['filename' => 'bedriox-runtime-windows-x86_64.zip', 'threadSafe' => true],
        'linux-x86_64' => ['filename' => 'bedriox-runtime-linux-x86_64.tar.gz', 'threadSafe' => false],
        'linux-arm64' => ['filename' => 'bedriox-runtime-linux-arm64.tar.gz', 'threadSafe' => false],
        'macos-arm64' => ['filename' => 'bedriox-runtime-macos-arm64.tar.gz', 'threadSafe' => false],
        'macos-x86_64' => ['filename' => 'bedriox-runtime-macos-x86_64.tar.gz', 'threadSafe' => false],
    ];

    /** @var array<string, array{directory: string, package: string, url: string}> */
    private const array COMPONENTS = [
        'protocol' => ['directory' => 'Protocol', 'package' => 'bedriox/protocol', 'url' => '../Protocol'],
        'raknet' => ['directory' => 'RakNet', 'package' => 'bedriox/raknet', 'url' => '../RakNet'],
        'data' => ['directory' => 'Data', 'package' => 'bedriox/data', 'url' => '../Data'],
    ];

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $composer
     * @param array<string, mixed> $composerLock
     * @param array<string, string> $siblingHeads keyed by manifest component name
     * @return list<string>
     */
    public static function validate(array $manifest, array $composer, array $composerLock, string $applicationVersion, array $siblingHeads = []): array
    {
        $errors = [];
        if (($manifest['schema'] ?? null) !== 2) {
            $errors[] = 'Manifest schema must be 2.';
        }
        if (($manifest['server'] ?? null) !== $applicationVersion) {
            $errors[] = 'Manifest and Application versions differ.';
        }

        $requirements = $composer['require'] ?? null;
        if (!is_array($requirements) || array_is_list($requirements)) {
            $errors[] = 'Composer requirements must be an object.';
            $requirements = [];
        }
        if (($manifest['php'] ?? null) !== ($requirements['php'] ?? null)) {
            $errors[] = 'Manifest and Composer PHP requirements differ.';
        }

        $bedrock = $manifest['bedrock'] ?? null;
        if (!is_array($bedrock) || array_is_list($bedrock)) {
            $errors[] = 'Manifest must contain Bedrock compatibility metadata.';
        } elseif (!self::sameValue($bedrock['clientVersions'] ?? null, self::QUALIFIED_CLIENT_VERSIONS)
            || !self::sameValue($bedrock['networkProtocols'] ?? null, self::QUALIFIED_NETWORK_PROTOCOLS)) {
            $errors[] = 'Bedrock compatibility metadata must match the qualified client and protocol set.';
        }

        $components = $manifest['components'] ?? null;
        if (!is_array($components) || array_is_list($components)) {
            $errors[] = 'Manifest components must be an object.';
            $components = [];
        }
        $expectedComponentNames = array_keys(self::COMPONENTS);
        $actualComponentNames = array_keys($components);
        sort($expectedComponentNames);
        sort($actualComponentNames);
        if ($actualComponentNames !== $expectedComponentNames) {
            $errors[] = 'Manifest component set differs from the expected integration components.';
        }
        $repositories = $composer['repositories'] ?? null;
        if (!is_array($repositories) || !array_is_list($repositories)) {
            $errors[] = 'Composer repositories must be a list.';
            $repositories = [];
        }
        if (count($repositories) !== count(self::COMPONENTS)) {
            $errors[] = 'Composer must declare exactly the approved component repositories.';
        }
        /** @var array<string, array<string, mixed>> $repositoriesByUrl */
        $repositoriesByUrl = [];
        foreach ($repositories as $repository) {
            if (!is_array($repository) || array_is_list($repository) || !is_string($repository['url'] ?? null)) {
                $errors[] = 'Composer contains an invalid component repository entry.';
                continue;
            }
            $url = $repository['url'];
            if (isset($repositoriesByUrl[$url])) {
                $errors[] = "Composer repository {$url} is duplicated.";
                continue;
            }
            $repositoriesByUrl[$url] = $repository;
        }

        /** @var array<string, array<string, mixed>> $lockedByName */
        $lockedByName = [];
        $lockedPackages = $composerLock['packages'] ?? null;
        if (!is_array($lockedPackages) || !array_is_list($lockedPackages)) {
            $errors[] = 'Composer lock packages must be a list.';
            $lockedPackages = [];
        }
        foreach ($lockedPackages as $package) {
            if (!is_array($package) || array_is_list($package) || !is_string($package['name'] ?? null)) {
                $errors[] = 'Composer lock contains an invalid package entry.';
                continue;
            }
            $name = $package['name'];
            if (isset($lockedByName[$name])) {
                $errors[] = "Composer lock package {$name} is duplicated.";
                continue;
            }
            $lockedByName[$name] = $package;
        }

        foreach (self::COMPONENTS as $componentName => $definition) {
            $component = $components[$componentName] ?? null;
            if (!is_array($component) || array_is_list($component)) {
                $errors[] = "Manifest component {$componentName} must be pinned.";
                continue;
            }
            if (!self::hasExactKeys($component, ['package', 'version', 'commit'])) {
                $errors[] = "Manifest component {$componentName} has unexpected or missing fields.";
            }
            $packageName = $component['package'] ?? null;
            $version = $component['version'] ?? null;
            $commit = $component['commit'] ?? null;
            if ($packageName !== $definition['package']) {
                $errors[] = "Manifest component {$componentName} must map to {$definition['package']}.";
            }
            if (!is_string($version) || $version === '' || !is_string($commit)
                || preg_match('/^[0-9a-f]{40}$/D', $commit) !== 1) {
                $errors[] = "Manifest component {$componentName} has invalid version or commit metadata.";
                continue;
            }
            if (($requirements[$definition['package']] ?? null) !== $version) {
                $errors[] = "Composer requirement for {$definition['package']} does not match {$version}.";
            }

            $expectedRepository = [
                'type' => 'path',
                'url' => $definition['url'],
                'options' => ['symlink' => false, 'versions' => [$definition['package'] => $version]],
            ];
            if (!self::sameValue($repositoriesByUrl[$definition['url']] ?? null, $expectedRepository)) {
                $errors[] = "Composer repository for {$definition['package']} is not the exact approved sibling path entry.";
            }

            $locked = $lockedByName[$definition['package']] ?? null;
            if ($locked === null) {
                $errors[] = "Composer lock does not contain {$definition['package']}.";
                continue;
            }
            if (($locked['version'] ?? null) !== $version) {
                $errors[] = "Composer lock version for {$definition['package']} does not match {$version}.";
            }
            $expectedDist = ['type' => 'path', 'url' => $definition['url'], 'reference' => $commit];
            if (!self::sameValue($locked['dist'] ?? null, $expectedDist)) {
                $errors[] = "Composer lock dist for {$definition['package']} is not the exact approved path and reference.";
            }
            if (array_key_exists('source', $locked)) {
                $errors[] = "Composer lock path package {$definition['package']} must not declare a source override.";
            }
            if (isset($siblingHeads[$componentName]) && $siblingHeads[$componentName] !== $commit) {
                $errors[] = "Sibling {$definition['directory']} HEAD does not match manifest commit {$commit}.";
            }
        }

        self::validateRuntime($manifest['runtime'] ?? null, $errors);

        return $errors;
    }

    /**
     * @param list<string> $errors
     */
    private static function validateRuntime(mixed $runtime, array &$errors): void
    {
        if (!is_array($runtime) || array_is_list($runtime)) {
            $errors[] = 'Manifest runtime must be an object.';
            return;
        }
        if (!self::hasExactKeys($runtime, ['commit', 'manifestSchema', 'phpVersion', 'extensions', 'artifacts'])) {
            $errors[] = 'Manifest runtime has unexpected or missing fields.';
        }
        $commit = $runtime['commit'] ?? null;
        if (!is_string($commit) || preg_match('/^[0-9a-f]{40}$/D', $commit) !== 1) {
            $errors[] = 'Manifest runtime commit must be an immutable lowercase SHA-1.';
        }
        if (($runtime['manifestSchema'] ?? null) !== 2) {
            $errors[] = 'Manifest runtime package schema must be 2.';
        }
        if (($runtime['phpVersion'] ?? null) !== self::QUALIFIED_RUNTIME_PHP) {
            $errors[] = 'Manifest runtime PHP version is not the qualified version.';
        }
        $extensions = $runtime['extensions'] ?? null;
        if (!is_array($extensions) || array_is_list($extensions)
            || !self::hasExactKeys($extensions, ['all', 'unix'])
            || !self::sameValue($extensions['all'] ?? null, self::REQUIRED_RUNTIME_EXTENSIONS)
            || !self::sameValue($extensions['unix'] ?? null, self::REQUIRED_UNIX_RUNTIME_EXTENSIONS)) {
            $errors[] = 'Manifest runtime extensions must match the qualified common and Unix sets.';
        }

        $artifacts = $runtime['artifacts'] ?? null;
        if (!is_array($artifacts) || array_is_list($artifacts)) {
            $errors[] = 'Manifest runtime artifacts must be an object.';
            return;
        }
        $expectedTargets = array_keys(self::RUNTIME_TARGETS);
        $actualTargets = array_keys($artifacts);
        sort($expectedTargets);
        sort($actualTargets);
        if ($actualTargets !== $expectedTargets) {
            $errors[] = 'Manifest runtime artifact set differs from the qualified target set.';
        }
        foreach (self::RUNTIME_TARGETS as $target => $expected) {
            $artifact = $artifacts[$target] ?? null;
            if (!is_array($artifact) || array_is_list($artifact)) {
                $errors[] = "Manifest runtime artifact {$target} must be pinned.";
                continue;
            }
            if (!self::hasExactKeys($artifact, ['filename', 'size', 'sha256', 'manifestSha256', 'threadSafe'])) {
                $errors[] = "Manifest runtime artifact {$target} has unexpected or missing fields.";
            }
            if (($artifact['filename'] ?? null) !== $expected['filename']) {
                $errors[] = "Manifest runtime artifact {$target} has an invalid filename.";
            }
            if (!is_int($artifact['size'] ?? null) || $artifact['size'] < 1) {
                $errors[] = "Manifest runtime artifact {$target} has an invalid size.";
            }
            foreach (['sha256', 'manifestSha256'] as $digestField) {
                $digest = $artifact[$digestField] ?? null;
                if (!is_string($digest) || preg_match('/^[0-9a-f]{64}$/D', $digest) !== 1) {
                    $errors[] = "Manifest runtime artifact {$target} has an invalid {$digestField}.";
                }
            }
            if (($artifact['threadSafe'] ?? null) !== $expected['threadSafe']) {
                $errors[] = "Manifest runtime artifact {$target} has an invalid thread-safety ABI.";
            }
        }
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string> $expected
     */
    private static function hasExactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        return $actual === $expected;
    }

    private static function sameValue(mixed $left, mixed $right): bool
    {
        if (!is_array($left)) {
            return !is_array($right) && get_debug_type($left) === get_debug_type($right) && $left === $right;
        }
        if (!is_array($right)) {
            return false;
        }
        if (array_is_list($left) !== array_is_list($right) || count($left) !== count($right)) {
            return false;
        }
        if (!array_is_list($left)) {
            $leftKeys = array_keys($left);
            $rightKeys = array_keys($right);
            sort($leftKeys);
            sort($rightKeys);
            if ($leftKeys !== $rightKeys) {
                return false;
            }
        }
        foreach ($left as $key => $value) {
            if (!array_key_exists($key, $right) || !self::sameValue($value, $right[$key])) {
                return false;
            }
        }
        return true;
    }
}
