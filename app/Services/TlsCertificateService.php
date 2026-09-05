<?php

namespace App\Services;

use RuntimeException;

class TlsCertificateService
{
    /**
     * Ensure that remote certificate mode has a valid key pair and both
     * client pin formats used by Xray and sing-box.
     */
    public function ensureRemoteCertificate(array $tlsSettings, string $fallbackServerName = ''): array
    {
        if (!function_exists('openssl_pkey_new')) {
            throw new RuntimeException('OpenSSL extension is required to generate a remote TLS certificate');
        }

        $certificatePem = trim((string)($tlsSettings['tls_cert'] ?? ''));
        $privateKeyPem = trim((string)($tlsSettings['tls_key'] ?? ''));

        if (($certificatePem === '') xor ($privateKeyPem === '')) {
            throw new RuntimeException('Remote TLS certificate and private key must be provided together');
        }

        if ($certificatePem === '') {
            list($certificatePem, $privateKeyPem) = $this->generateKeyPair(
                $this->resolveCommonName($tlsSettings, $fallbackServerName)
            );
        }

        $certificate = openssl_x509_read($certificatePem);
        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if ($certificate === false || $privateKey === false) {
            throw new RuntimeException('Unable to parse the remote TLS certificate or private key');
        }
        if (!openssl_x509_check_private_key($certificate, $privateKey)) {
            throw new RuntimeException('Remote TLS certificate does not match its private key');
        }

        $publicKey = openssl_pkey_get_public($certificate);
        $publicKeyDetails = $publicKey === false ? false : openssl_pkey_get_details($publicKey);
        if ($publicKeyDetails === false || empty($publicKeyDetails['key'])) {
            throw new RuntimeException('Unable to read the remote TLS certificate public key');
        }

        $certificateDer = $this->pemToDer($certificatePem, 'CERTIFICATE');
        $publicKeyDer = $this->pemToDer($publicKeyDetails['key'], 'PUBLIC KEY');

        $tlsSettings['tls_cert'] = $certificatePem . "\n";
        $tlsSettings['tls_key'] = $privateKeyPem . "\n";
        // Xray URI `pcs`: SHA-256 of the complete certificate DER, as hex.
        $tlsSettings['pinned_peer_cert_sha256'] = hash('sha256', $certificateDer);
        // sing-box 1.13+: SHA-256 of SubjectPublicKeyInfo DER, as base64.
        $tlsSettings['certificate_public_key_sha256'] = base64_encode(hash('sha256', $publicKeyDer, true));

        return $tlsSettings;
    }

    private function generateKeyPair(string $commonName): array
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($privateKey === false) {
            throw new RuntimeException('Unable to create the remote TLS private key');
        }

        $csr = openssl_csr_new([
            'commonName' => $commonName,
        ], $privateKey, [
            'digest_alg' => 'sha256',
        ]);
        if ($csr === false) {
            throw new RuntimeException('Unable to create the remote TLS certificate request');
        }

        $certificate = openssl_csr_sign($csr, null, $privateKey, 3650, [
            'digest_alg' => 'sha256',
        ]);
        if ($certificate === false) {
            throw new RuntimeException('Unable to create the remote TLS certificate');
        }

        if (!openssl_pkey_export($privateKey, $privateKeyPem)) {
            throw new RuntimeException('Unable to export the remote TLS private key');
        }
        if (!openssl_x509_export($certificate, $certificatePem)) {
            throw new RuntimeException('Unable to export the remote TLS certificate');
        }

        return [$certificatePem, $privateKeyPem];
    }

    private function resolveCommonName(array $tlsSettings, string $fallbackServerName): string
    {
        $serverName = trim((string)($tlsSettings['server_name'] ?? ''));
        if ($serverName !== '') {
            return $serverName;
        }

        $fallbackServerName = trim($fallbackServerName);
        return $fallbackServerName !== '' ? $fallbackServerName : 'example.com';
    }

    private function pemToDer(string $pem, string $label): string
    {
        $encoded = preg_replace(
            '/-----BEGIN ' . preg_quote($label, '/') . '-----|-----END ' . preg_quote($label, '/') . '-----|\s+/',
            '',
            $pem
        );
        $der = $encoded === null ? false : base64_decode($encoded, true);
        if ($der === false || $der === '') {
            throw new RuntimeException('Unable to decode remote TLS ' . strtolower($label));
        }

        return $der;
    }
}
