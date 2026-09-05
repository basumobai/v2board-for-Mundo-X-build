<?php

namespace Tests\Unit;

use App\Services\RuntimeConfigService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class RuntimeConfigCacheTest extends TestCase
{
    private $path;
    private $load;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'v2board-test-');
        $this->load = new ReflectionMethod(RuntimeConfigService::class, 'loadArrayFile');
        $this->load->setAccessible(true);
        $GLOBALS['v2board_config_evaluations'] = 0;
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        unset($GLOBALS['v2board_config_evaluations']);
    }

    private function read(array $fallback = []): array
    {
        // A new service instance must still use the worker-local cache.
        return $this->load->invoke(new RuntimeConfigService(), $this->path, $fallback);
    }

    private function contents(string $value): string
    {
        return "<?php \$GLOBALS['v2board_config_evaluations']++; return ['value' => '{$value}'];\n";
    }

    public function testUnchangedFileIsOnlyEvaluatedOnce(): void
    {
        file_put_contents($this->path, $this->contents('one'));
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame(['value' => 'one'], $this->read());
        }
        $this->assertSame(1, $GLOBALS['v2board_config_evaluations']);
    }

    public function testSameSizeAndTimestampEditIsVisibleImmediately(): void
    {
        file_put_contents($this->path, $this->contents('one'));
        $mtime = filemtime($this->path);
        $this->read();
        file_put_contents($this->path, $this->contents('two'));
        touch($this->path, $mtime);
        $this->assertSame(['value' => 'two'], $this->read());
        $this->assertSame(2, $GLOBALS['v2board_config_evaluations']);
    }

    public function testAtomicReplacementDeletionAndRecreation(): void
    {
        file_put_contents($this->path, $this->contents('one'));
        $this->read();
        $replacement = tempnam(dirname($this->path), 'v2board-replace-');
        file_put_contents($replacement, $this->contents('two'));
        rename($replacement, $this->path);
        $this->assertSame(['value' => 'two'], $this->read());
        unlink($this->path);
        $this->assertSame(['fallback' => true], $this->read(['fallback' => true]));
        file_put_contents($this->path, $this->contents('two'));
        $this->assertSame(['value' => 'two'], $this->read());
        $this->assertSame(3, $GLOBALS['v2board_config_evaluations']);
    }

    public function testInvalidArrayNeverCachesACallersFallback(): void
    {
        file_put_contents($this->path, '<?php return false;');
        $this->assertSame(['a'], $this->read(['a']));
        $this->assertSame(['b'], $this->read(['b']));
    }
}
