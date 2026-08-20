<?php

namespace Tests\Unit;

use App\Services\RuntimeConfigService;
use Tests\TestCase;

class RuntimeConfigServiceTest extends TestCase
{
    private $configPath;
    private $originalConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = base_path('config/v2board.php');
        $this->originalConfig = is_file($this->configPath)
            ? file_get_contents($this->configPath)
            : null;
    }

    protected function tearDown(): void
    {
        if ($this->originalConfig === null) {
            @unlink($this->configPath);
        } else {
            file_put_contents($this->configPath, $this->originalConfig, LOCK_EX);
        }

        foreach (glob(dirname($this->configPath) . '/.v2board-config-*') ?: [] as $temporaryFile) {
            @unlink($temporaryFile);
        }

        parent::tearDown();
    }

    public function testItPersistsAndReloadsV2boardConfiguration(): void
    {
        $service = $this->app->make(RuntimeConfigService::class);
        $expected = [
            'stop_register' => '1',
            'reset_traffic_method' => '3',
        ];

        $service->saveV2boardConfig($expected);
        config(['v2board' => ['stop_register' => '0']]);

        $this->assertSame($expected, $service->refreshV2boardConfig());
        $this->assertSame('1', config('v2board.stop_register'));
        $this->assertSame('3', config('v2board.reset_traffic_method'));
        $this->assertSame([], glob(dirname($this->configPath) . '/.v2board-config-*') ?: []);
    }
}
