<?php

namespace Tests\Unit;

use App\Console\Commands\V2boardInstall;
use Dotenv\Dotenv;
use Laravel\Horizon\ProvisioningPlan;
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

    /** @dataProvider workerBudgets */
    public function testProductionHasBoundedQueueWorkers(int $budget): void
    {
        $oldEnv = $_ENV['HORIZON_MAX_PROCESSES'] ?? null;
        $oldServer = $_SERVER['HORIZON_MAX_PROCESSES'] ?? null;
        $_ENV['HORIZON_MAX_PROCESSES'] = $_SERVER['HORIZON_MAX_PROCESSES'] = (string)$budget;
        try {
            $horizon = require dirname(__DIR__, 2) . '/config/horizon.php';
        } finally {
            unset($_ENV['HORIZON_MAX_PROCESSES'], $_SERVER['HORIZON_MAX_PROCESSES']);
            if ($oldEnv !== null) $_ENV['HORIZON_MAX_PROCESSES'] = $oldEnv;
            if ($oldServer !== null) $_SERVER['HORIZON_MAX_PROCESSES'] = $oldServer;
        }
        $this->assertSame($horizon['environments']['local'], $horizon['environments']['production']);
        // Exercise the locked Horizon version's real validation and pool mode.
        $plan = new ProvisioningPlan('deployment-test', $horizon['environments']);
        $total = 0;
        $queues = [];
        foreach ($horizon['environments']['production'] as $name => $supervisor) {
            $options = $plan->optionsFor('production', $name);
            $this->assertFalse($options->balancing());
            $this->assertGreaterThanOrEqual(1, $options->minProcesses);
            $total += $options->maxProcesses;
            $queues = array_merge($queues, explode(',', $options->queue));
        }
        $this->assertSame($budget, $total);
        $this->assertEqualsCanonicalizing(
            ['order_handle', 'traffic_fetch', 'stat', 'send_email', 'send_email_mass', 'send_telegram'],
            $queues
        );
    }

    public function workerBudgets(): array
    {
        return [[1], [2], [4], [8], [128]];
    }
}
