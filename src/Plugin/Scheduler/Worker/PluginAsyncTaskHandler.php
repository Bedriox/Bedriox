<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Scheduler\Worker;

use Bedriox\Api\Scheduler\AsyncTask;
use Bedriox\Server\Worker\WorkerTaskHandler;
use Phar;
use Throwable;

/** @internal Executes one validated PHAR task in a plugin-only worker process. */
final class PluginAsyncTaskHandler implements WorkerTaskHandler
{
    public function execute(string $payload): string
    {
        $codec = new PluginAsyncTaskCodec();
        $request = $codec->decodeRequest($payload);
        try {
            $identity = $request['package'];
            $package = realpath($identity->path);
            $size = $package === false ? false : filesize($package);
            if ($package === false || $size === false || $size > 16_777_216 || strtolower(pathinfo($package, PATHINFO_EXTENSION)) !== 'phar') {
                throw new \RuntimeException('Plugin package is unavailable.');
            }
            $digest = hash_file('sha256', $package);
            if (!is_string($digest) || !hash_equals($identity->sha256, $digest)) {
                throw new \RuntimeException('Plugin package digest no longer matches its admitted identity.');
            }
            $archive = new Phar($package);
            $signature = $archive->getSignature();
            if (!in_array($signature['hash_type'], ['SHA-256', 'SHA-512', 'OpenSSL', 'OpenSSL_SHA256', 'OpenSSL_SHA512'], true)) {
                throw new \RuntimeException('Plugin package signature is unsupported.');
            }
            if ($signature['hash_type'] !== $identity->signatureType
                || !hash_equals($identity->signatureHash, strtolower($signature['hash']))) {
                throw new \RuntimeException('Plugin package signature no longer matches its admitted identity.');
            }
            $root = 'phar://' . str_replace('\\', '/', $package) . '/';
            $manifestBytes = file_get_contents($root . 'plugin.json');
            $manifest = is_string($manifestBytes) ? json_decode($manifestBytes, true, 8, JSON_THROW_ON_ERROR) : null;
            $namespace = is_array($manifest) ? ($manifest['namespace'] ?? null) : null;
            if (!is_array($manifest) || ($manifest['name'] ?? null) !== $identity->owner
                || ($manifest['version'] ?? null) !== $identity->version) {
                throw new \RuntimeException('Plugin manifest no longer matches its admitted identity.');
            }
            if (!is_string($namespace) || !preg_match('/^[A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)*$/D', $namespace)) {
                throw new \RuntimeException('Plugin namespace is invalid.');
            }
            $prefix = $namespace . '\\';
            if (!str_starts_with($request['class'], $prefix)) {
                throw new \RuntimeException('Async task is outside the plugin namespace.');
            }
            $source = $root . 'src/';
            $autoloader = static function (string $class) use ($prefix, $source): void {
                if (!str_starts_with($class, $prefix)) {
                    return;
                }
                $relative = substr($class, strlen($prefix));
                if ($relative === '' || preg_match('/^[A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)*$/D', $relative) !== 1) {
                    return;
                }
                $path = $source . str_replace('\\', '/', $relative) . '.php';
                if (is_file($path)) {
                    require $path;
                }
            };
            spl_autoload_register($autoloader, true, true);
            try {
                if (!class_exists($request['class']) || !is_a($request['class'], AsyncTask::class, true)) {
                    throw new \RuntimeException('Async task class is unavailable.');
                }
                $task = new ($request['class'])();
                $result = $task->onRun($request['input']);
            } finally {
                spl_autoload_unregister($autoloader);
            }

            return $codec->encodeSuccess($request['owner'], $request['generation'], $result);
        } catch (Throwable $failure) {
            return $codec->encodeFailure($request['owner'], $request['generation'], $failure::class);
        }
    }
}
