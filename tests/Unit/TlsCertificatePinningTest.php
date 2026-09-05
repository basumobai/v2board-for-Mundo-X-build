<?php

namespace Tests\Unit;

use App\Protocols\Singbox\Singbox;
use App\Services\ServerService;
use App\Services\TlsCertificateService;
use App\Utils\Helper;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class TlsCertificatePinningTest extends TestCase
{
    public function testRemoteModeGeneratesAValidKeyPairAndBothFingerprintFormats(): void
    {
        $settings = (new TlsCertificateService())->ensureRemoteCertificate([
            'cert_mode' => 'remote',
            'server_name' => 'node.example.com',
        ]);

        $this->assertStringContainsString('BEGIN CERTIFICATE', $settings['tls_cert']);
        $this->assertStringContainsString('BEGIN PRIVATE KEY', $settings['tls_key']);
        $this->assertTrue(openssl_x509_check_private_key($settings['tls_cert'], $settings['tls_key']));

        $certificateDer = $this->pemToDer($settings['tls_cert'], 'CERTIFICATE');
        $this->assertSame(hash('sha256', $certificateDer), $settings['pinned_peer_cert_sha256']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $settings['pinned_peer_cert_sha256']);

        $publicKey = openssl_pkey_get_public($settings['tls_cert']);
        $publicKeyDetails = openssl_pkey_get_details($publicKey);
        $publicKeyDer = $this->pemToDer($publicKeyDetails['key'], 'PUBLIC KEY');
        $this->assertSame(
            base64_encode(hash('sha256', $publicKeyDer, true)),
            $settings['certificate_public_key_sha256']
        );
    }

    public function testExistingRemoteCertificateIsReusedAndStalePinsAreRepaired(): void
    {
        $service = new TlsCertificateService();
        $first = $service->ensureRemoteCertificate(['server_name' => 'node.example.com']);
        $input = $first;
        $input['pinned_peer_cert_sha256'] = str_repeat('0', 64);
        $input['certificate_public_key_sha256'] = 'stale';

        $second = $service->ensureRemoteCertificate($input);

        $this->assertSame($first['tls_cert'], $second['tls_cert']);
        $this->assertSame($first['tls_key'], $second['tls_key']);
        $this->assertSame($first['pinned_peer_cert_sha256'], $second['pinned_peer_cert_sha256']);
        $this->assertSame($first['certificate_public_key_sha256'], $second['certificate_public_key_sha256']);
    }

    public function testMismatchedRemoteCertificateAndPrivateKeyAreRejected(): void
    {
        $service = new TlsCertificateService();
        $first = $service->ensureRemoteCertificate(['server_name' => 'one.example.com']);
        $second = $service->ensureRemoteCertificate(['server_name' => 'two.example.com']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match');
        $service->ensureRemoteCertificate([
            'tls_cert' => $first['tls_cert'],
            'tls_key' => $second['tls_key'],
        ]);
    }

    public function testRawSubscriptionUrisOnlyContainPcsWhenPinningIsConfigured(): void
    {
        $pin = str_repeat('a', 64);
        $server = [
            'name' => 'Pinned node',
            'host' => 'node.example.com',
            'port' => '443',
            'network' => 'tcp',
            'network_settings' => [],
            'tls' => 1,
            'flow' => null,
            'insecure' => 0,
            'disable_sni' => 0,
            'congestion_control' => 'cubic',
            'udp_relay_mode' => 'native',
            'tls_settings' => [
                'server_name' => 'node.example.com',
                'allow_insecure' => 0,
                'pinned_peer_cert_sha256' => $pin,
            ],
        ];

        $vmess = json_decode(base64_decode(trim(substr(Helper::buildVmessUri('uuid', $server), 8))), true);
        $this->assertSame($pin, $vmess['pcs']);

        $builders = [
            'vless' => 'buildVlessUri',
            'trojan' => 'buildTrojanUri',
            'hysteria2' => 'buildHysteria2Uri',
            'tuic' => 'buildTuicUri',
            'anytls' => 'buildAnytlsUri',
        ];
        foreach ($builders as $name => $method) {
            $uri = Helper::$method('uuid', $server);
            $this->assertStringContainsString('pcs=' . $pin, $uri, $name);
        }

        unset($server['tls_settings']['pinned_peer_cert_sha256']);
        $vmess = json_decode(base64_decode(trim(substr(Helper::buildVmessUri('uuid', $server), 8))), true);
        $this->assertArrayNotHasKey('pcs', $vmess);
        foreach ($builders as $name => $method) {
            $this->assertStringNotContainsString('pcs=', Helper::$method('uuid', $server), $name);
        }
    }

    public function testSingboxPublicKeyPinIsVersionGated(): void
    {
        $server = [
            'name' => 'Pinned node',
            'host' => 'node.example.com',
            'port' => 443,
            'network' => 'tcp',
            'network_settings' => [],
            'tls' => 1,
            'tls_settings' => [
                'server_name' => 'node.example.com',
                'allow_insecure' => 0,
                'certificate_public_key_sha256' => 'c3BraS1oYXNo',
            ],
        ];
        $buildVmess = new ReflectionMethod(Singbox::class, 'buildVmess');
        $buildVmess->setAccessible(true);

        $legacy = new Singbox([], [], ['version' => '1.12.9']);
        $legacyConfig = $buildVmess->invoke($legacy, 'uuid', $server);
        $this->assertArrayNotHasKey('certificate_public_key_sha256', $legacyConfig['tls']);

        $supported = new Singbox([], [], ['version' => '1.13.0']);
        $supportedConfig = $buildVmess->invoke($supported, 'uuid', $server);
        $this->assertSame(
            ['c3BraS1oYXNo'],
            $supportedConfig['tls']['certificate_public_key_sha256']
        );
    }

    public function testSubscriptionSanitizerKeepsPinsButRemovesServerOnlyCertificateMaterial(): void
    {
        $settings = ServerService::sanitizeTlsSettingsForSubscription([
            'private_key' => 'reality-secret',
            'ech_key' => 'ech-secret',
            'tls_key' => 'tls-secret',
            'tls_cert' => 'certificate-body',
            'pinned_peer_cert_sha256' => 'certificate-pin',
            'certificate_public_key_sha256' => 'public-key-pin',
            'server_name' => 'node.example.com',
        ]);

        $this->assertArrayNotHasKey('private_key', $settings);
        $this->assertArrayNotHasKey('ech_key', $settings);
        $this->assertArrayNotHasKey('tls_key', $settings);
        $this->assertArrayNotHasKey('tls_cert', $settings);
        $this->assertSame('certificate-pin', $settings['pinned_peer_cert_sha256']);
        $this->assertSame('public-key-pin', $settings['certificate_public_key_sha256']);
    }

    private function pemToDer(string $pem, string $label): string
    {
        $encoded = preg_replace(
            '/-----BEGIN ' . preg_quote($label, '/') . '-----|-----END ' . preg_quote($label, '/') . '-----|\s+/',
            '',
            $pem
        );

        return base64_decode($encoded, true);
    }
}
