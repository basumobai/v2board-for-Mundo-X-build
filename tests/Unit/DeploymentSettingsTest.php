<?php

namespace Tests\Unit;

use App\Support\DeploymentSettings;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DeploymentSettingsTest extends TestCase
{
    /** @dataProvider invalidIntegers */
    public function testInvalidNumbersAreRejected($value): void
    {
        $this->expectException(InvalidArgumentException::class);
        DeploymentSettings::positiveInteger('port', $value, 65535);
    }

    public function invalidIntegers(): array
    {
        return [['0'], ['-1'], ['65536'], ['6600;echo bad'], ['1.5'], [''], ['06600'], ['999999999999999999999']];
    }

    public function testValidBoundaries(): void
    {
        $this->assertSame(1, DeploymentSettings::positiveInteger('port', '1', 65535));
        $this->assertSame(65535, DeploymentSettings::positiveInteger('port', 65535, 65535));
    }

    public function testOccupiedPortIsRejectedAndProbeSocketsAreReleased(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        try {
            DeploymentSettings::assertPortsAvailable([$port]);
            $this->fail('Expected occupied port to be rejected');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString((string)$port, $exception->getMessage());
        } finally {
            fclose($socket);
        }
        DeploymentSettings::assertPortsAvailable([$port]);
        DeploymentSettings::assertPortsAvailable([$port]);
    }

    public function testRuntimeReadsCustomPortsAndRejectsEqualPorts(): void
    {
        $original = $_ENV;
        try {
            $_ENV['WEB_PORT'] = '16600';
            $_ENV['GATEWAY_PORT'] = '17001';
            $_ENV['WEB_WORKERS'] = '3';
            $_ENV['WEB_MAX_REQUESTS'] = '500';
            $this->assertSame(['WEB_PORT' => 16600, 'GATEWAY_PORT' => 17001,
                'WEB_WORKERS' => 3, 'WEB_MAX_REQUESTS' => 500], DeploymentSettings::fromEnvironment());
            $_ENV['GATEWAY_PORT'] = '16600';
            $this->expectException(InvalidArgumentException::class);
            DeploymentSettings::fromEnvironment();
        } finally {
            $_ENV = $original;
        }
    }
}
