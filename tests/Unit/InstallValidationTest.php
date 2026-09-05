<?php

namespace Tests\Unit;

use App\Console\Commands\V2boardInstall;
use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class InstallValidationTest extends TestCase
{
    private function invoke(string $method, string $value)
    {
        $reflection = new ReflectionMethod(V2boardInstall::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke(new V2boardInstall(), $value);
    }

    /** @dataProvider invalidUrls */
    public function testInvalidUrlsAreRejected(string $value): void
    {
        $this->expectException(\RuntimeException::class);
        $this->invoke('normalizeAppUrl', $value);
    }

    public function invalidUrls(): array
    {
        return [['panel.example.com'], ['ftp://panel.example.com'], ['https://user:pass@panel.example.com'],
            ['https://panel.example.com/subpath'], ['https://panel.example.com/?a=b'], ['https://panel.example.com/#a']];
    }

    public function testValidUrlWithCustomPort(): void
    {
        $this->assertSame('http://127.0.0.1:7002', $this->invoke('normalizeAppUrl', ' http://127.0.0.1:7002/ '));
    }

    public function testSecretsRoundTripThroughDotenv(): void
    {
        foreach (['space and #hash', 'single\'quote', 'double"quote', 'dollar${APP_NAME}$end', 'back\\slash'] as $secret) {
            $encoded = $this->invoke('formatEnvValue', $secret);
            $parsed = Dotenv::parse('DB_PASSWORD=' . $encoded);
            $this->assertSame($secret, $parsed['DB_PASSWORD']);
        }
    }

    public function testProductionHasBoundedQueueWorkers(): void
    {
        $horizon = require dirname(__DIR__, 2) . '/config/horizon.php';
        $this->assertSame($horizon['environments']['local'], $horizon['environments']['production']);
        $supervisor = $horizon['environments']['production']['V2board'];
        $this->assertContains('traffic_fetch', $supervisor['queue']);
        $this->assertContains('stat', $supervisor['queue']);
        $this->assertSame(0, $supervisor['minProcesses']);
        $this->assertGreaterThanOrEqual(1, $supervisor['maxProcesses']);
        $this->assertLessThanOrEqual(128, $supervisor['maxProcesses']);
    }
}
