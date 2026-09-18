<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Plugin;

use Bedriox\Api\Plugin\PluginContext;
use Bedriox\Api\Server;
use Bedriox\Server\Observability\LogLevel;
use Bedriox\Server\Observability\ServerLogger;
use Bedriox\Server\Plugin\Command\OwnedCommandRegistrar;
use Bedriox\Server\Plugin\Event\OwnedEventRegistrar;
use Bedriox\Server\Plugin\OwnedSourcePluginRegistrar;
use Bedriox\Server\Plugin\PluginHost;
use Bedriox\Server\Plugin\PluginManifest;
use Bedriox\Server\Plugin\ServerPluginLogger;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class PluginHostSourceAdmissionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-source-host-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root . DIRECTORY_SEPARATOR . 'plugins', 0o775, true));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testProviderStagesSourcePluginForNormalLifecycleAndCleanup(): void
    {
        $cleanupMarker = $this->root . DIRECTORY_SEPARATOR . 'released.txt';
        $providerSource = <<<'PHP'
<?php
declare(strict_types=1);
namespace Fixture\DevelopmentProvider;
use Bedriox\Api\Plugin\Plugin;
use Bedriox\Api\Plugin\PluginContext;
use Bedriox\Api\Plugin\SourcePluginDefinition;
final class Main extends Plugin{
    public function onLoad(): void{
        $this->context()->sourcePlugins()->register([new SourcePluginDefinition(
            1, 'SourceFixture', '1.0.0', '^0.1',
            'Fixture\\DevelopmentProvider\\Source\\Main',
            'Fixture\\DevelopmentProvider\\Source', [], [], [], 'WORLD_READY',
            static fn(PluginContext $context): Plugin => new \Fixture\DevelopmentProvider\Source\Main($context),
            static function (): void { file_put_contents(__CLEANUP__, 'released'); },
        )]);
    }
}
PHP;
        $providerSource = str_replace('__CLEANUP__', var_export($cleanupMarker, true), $providerSource);
        $sourceMain = <<<'PHP'
<?php
declare(strict_types=1);
namespace Fixture\DevelopmentProvider\Source;
use Bedriox\Api\Plugin\Plugin;
final class Main extends Plugin{}
PHP;
        $this->buildProviderPhar($providerSource, $sourceMain);
        $logger = new ServerLogger(static function (string $line): void {}, LogLevel::DEBUG, false, false, null);
        $host = new PluginHost(
            $this->root . DIRECTORY_SEPARATOR . 'plugins',
            $this->root . DIRECTORY_SEPARATOR . 'plugin_data',
            $logger,
            function (
                PluginManifest $manifest,
                string $dataFolder,
                OwnedEventRegistrar $events,
                OwnedCommandRegistrar $commands,
                OwnedSourcePluginRegistrar $sourcePlugins,
                ServerPluginLogger $pluginLogger,
            ): PluginContext {
                return new PluginContext(
                    $manifest->name,
                    $pluginLogger,
                    $events,
                    $commands,
                    $sourcePlugins,
                    $this->createStub(Server::class),
                    $dataFolder,
                );
            },
        );

        $host->start();

        self::assertTrue($host->manager()->isEnabled('DevelopmentProvider'));
        self::assertTrue($host->manager()->isEnabled('SourceFixture'));
        self::assertStringContainsString('Enabled source plugin SourceFixture 1.0.0', implode("\n", $logger->recentLines()));

        $host->stop();

        self::assertFileExists($cleanupMarker);
    }

    private function buildProviderPhar(string $providerSource, string $sourceMain): void
    {
        $manifest = json_encode([
            'schema' => 1,
            'name' => 'DevelopmentProvider',
            'version' => '1.0.0',
            'api' => '^0.1',
            'main' => 'Fixture\\DevelopmentProvider\\Main',
            'namespace' => 'Fixture\\DevelopmentProvider',
            'authors' => ['Bedriox Team'],
            'dependencies' => [],
            'softDependencies' => [],
            'load' => 'STARTUP',
        ], JSON_THROW_ON_ERROR);
        $code = <<<'PHP'
$phar = new Phar($argv[1]);
$phar->startBuffering();
$phar['plugin.json'] = $argv[2];
$phar['src/Main.php'] = $argv[3];
$phar['src/Source/Main.php'] = $argv[4];
$phar->setSignatureAlgorithm(Phar::SHA256);
$phar->setStub('<?php __HALT_COMPILER();');
$phar->stopBuffering();
PHP;
        $process = proc_open([
            PHP_BINARY,
            '-d',
            'phar.readonly=0',
            '-r',
            $code,
            $this->root . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'provider.phar',
            $manifest,
            $providerSource,
            $sourceMain,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stdout . $stderr);
    }
}
