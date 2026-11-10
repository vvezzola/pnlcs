<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OpenSSLCertificate;
use Throwable;

/**
 * Rebuilds the intermediate chain of an issued certificate.
 *
 * Certificate authorities and resellers hand the chain back in every shape:
 * missing an intermediate, in the wrong order, with the root included, with
 * the leaf repeated, or not at all. Starting from the leaf, each issuer is
 * looked for among the certificates the provider sent and, failing that,
 * downloaded from the "CA Issuers" address the certificate itself carries
 * (Authority Information Access), until a self-signed root is reached. The
 * root is left out, as web servers expect.
 */
class SslChainBuilder
{
    private const MAX_DEPTH = 8;

    private const MAX_DOWNLOAD_BYTES = 65536;

    /**
     * Returns the ordered PEM set, or null when no complete path to a root
     * could be found - the caller then keeps what the provider sent.
     *
     * @return array{cert: string, ca_cert: string|null, fullchain: string}|null
     */
    public function build(string $certificate, ?string $chain = null): ?array
    {
        $given = $this->readCertificates($certificate);
        if ($given === []) {
            return null;
        }

        $leaf = array_shift($given);
        $pool = array_merge($given, $this->readCertificates((string) $chain));

        $intermediates = [];
        $current = $leaf;

        for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
            if ($this->isSelfSigned($current)) {
                return $this->result($leaf, $intermediates);
            }

            $issuer = $this->findIssuer($current, $pool) ?? $this->findIssuer($current, $this->downloadIssuers($current));
            if ($issuer === null) {
                return null;
            }

            if ($this->isSelfSigned($issuer)) {
                return $this->result($leaf, $intermediates);
            }

            $intermediates[] = $issuer;
            $current = $issuer;
        }

        return null;
    }

    /**
     * @param  list<OpenSSLCertificate>  $intermediates
     * @return array{cert: string, ca_cert: string|null, fullchain: string}
     */
    private function result(OpenSSLCertificate $leaf, array $intermediates): array
    {
        $cert = $this->toPem($leaf);
        $caCert = $intermediates === [] ? null : implode('', array_map($this->toPem(...), $intermediates));

        return [
            'cert' => $cert,
            'ca_cert' => $caCert,
            'fullchain' => $cert.$caCert,
        ];
    }

    /**
     * @param  list<OpenSSLCertificate>  $candidates
     */
    private function findIssuer(OpenSSLCertificate $certificate, array $candidates): ?OpenSSLCertificate
    {
        $issuerName = openssl_x509_parse($certificate)['issuer'] ?? null;

        foreach ($candidates as $candidate) {
            $subject = openssl_x509_parse($candidate)['subject'] ?? null;
            if ($subject == $issuerName && openssl_x509_verify($certificate, $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    private function isSelfSigned(OpenSSLCertificate $certificate): bool
    {
        $parsed = openssl_x509_parse($certificate);

        return ($parsed['subject'] ?? null) == ($parsed['issuer'] ?? null)
            && openssl_x509_verify($certificate, $certificate) === 1;
    }

    /**
     * @return list<OpenSSLCertificate>
     */
    private function downloadIssuers(OpenSSLCertificate $certificate): array
    {
        $access = (string) (openssl_x509_parse($certificate)['extensions']['authorityInfoAccess'] ?? '');
        preg_match_all('/CA Issuers - URI:(https?:\/\/\S+)/i', $access, $matches);

        $found = [];
        foreach ($matches[1] as $url) {
            $found = array_merge($found, $this->readCertificates($this->download($url)));
        }

        return $found;
    }

    /**
     * Issuer certificates rarely change, so a successful download is reused
     * for a week rather than fetched again for every order and every download.
     */
    private function download(string $url): string
    {
        $key = 'ssl-chain:'.sha1($url);
        $cached = Cache::get($key);
        if (is_string($cached)) {
            return base64_decode($cached);
        }

        try {
            $response = Http::timeout(10)->get($url);
        } catch (Throwable) {
            return '';
        }

        $body = $response->successful() ? $response->body() : '';
        if ($body === '' || strlen($body) > self::MAX_DOWNLOAD_BYTES || $this->readCertificates($body) === []) {
            return '';
        }

        Cache::put($key, base64_encode($body), now()->addWeek());

        return $body;
    }

    /**
     * Reads every certificate in a PEM bundle, a DER certificate or a PKCS#7
     * bundle (PEM or DER), the forms providers and CA Issuers addresses use.
     *
     * @return list<OpenSSLCertificate>
     */
    private function readCertificates(string $data): array
    {
        if (trim($data) === '') {
            return [];
        }

        if (str_contains($data, '-----BEGIN')) {
            preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $data, $blocks);
            $certificates = array_values(array_filter(array_map($this->readOne(...), $blocks[0])));

            if (preg_match('/-----BEGIN PKCS7-----.+?-----END PKCS7-----/s', $data, $pkcs7)) {
                $certificates = array_merge($certificates, $this->readPkcs7($pkcs7[0]));
            }

            return $certificates;
        }

        $encoded = chunk_split(base64_encode($data), 64, "\n");
        $single = $this->readOne("-----BEGIN CERTIFICATE-----\n{$encoded}-----END CERTIFICATE-----\n");

        return $single !== null
            ? [$single]
            : $this->readPkcs7("-----BEGIN PKCS7-----\n{$encoded}-----END PKCS7-----\n");
    }

    private function readOne(string $pem): ?OpenSSLCertificate
    {
        $certificate = @openssl_x509_read($pem);

        return $certificate instanceof OpenSSLCertificate ? $certificate : null;
    }

    /**
     * @return list<OpenSSLCertificate>
     */
    private function readPkcs7(string $pem): array
    {
        $pems = [];
        if (! @openssl_pkcs7_read($pem, $pems)) {
            return [];
        }

        return array_values(array_filter(array_map($this->readOne(...), $pems)));
    }

    private function toPem(OpenSSLCertificate $certificate): string
    {
        openssl_x509_export($certificate, $pem);

        return $pem;
    }
}
