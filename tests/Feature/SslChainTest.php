<?php

use App\Models\Client;
use App\Models\SslOrder;
use App\Services\SslProvisioningService;
use Illuminate\Support\Facades\Http;

/**
 * Providers hand back certificate chains missing an intermediate, in the wrong
 * order or with the root included. The panel rebuilds the chain from the leaf,
 * fetching a missing issuer from the address the certificate names, so the
 * downloaded bundle installs in Plesk and other panels as it is.
 */

/**
 * A three-level hierarchy: a self-signed root, an intermediate it signs and a
 * leaf the intermediate signs. Each child names its issuer's download address.
 *
 * @return array{root: string, intermediate: string, leaf: string, intermediateDer: string}
 */
function sslChainHierarchy(): array
{
    $config = tempnam(sys_get_temp_dir(), 'sslchain');
    file_put_contents($config, <<<'CNF'
        [req]
        distinguished_name = dn
        [dn]
        [ca]
        basicConstraints = critical,CA:true
        keyUsage = critical,keyCertSign,cRLSign
        [intermediate]
        basicConstraints = critical,CA:true,pathlen:0
        keyUsage = critical,keyCertSign,cRLSign
        authorityInfoAccess = caIssuers;URI:http://ca.test/root.crt
        [leaf]
        basicConstraints = CA:false
        authorityInfoAccess = caIssuers;URI:http://ca.test/intermediate.crt
        CNF);

    $keyOptions = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
    $issue = function (string $name, string $extensions, $issuerCert, $issuerKey) use ($keyOptions, $config): array {
        $key = openssl_pkey_new($keyOptions);
        $csr = openssl_csr_new(['commonName' => $name], $key, ['config' => $config, 'digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, $issuerCert, $issuerKey ?? $key, 365, ['config' => $config, 'x509_extensions' => $extensions, 'digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        openssl_x509_export($cert, $pem);

        return [$pem, $key];
    };

    [$root, $rootKey] = $issue('Test Root CA', 'ca', null, null);
    [$intermediate, $intermediateKey] = $issue('Test Intermediate CA', 'intermediate', $root, $rootKey);
    [$leaf] = $issue('shop.example.com', 'leaf', $intermediate, $intermediateKey);
    unlink($config);

    $intermediateDer = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $intermediate));

    return compact('root', 'intermediate', 'leaf', 'intermediateDer');
}

function sslChainOrder(array $attributes): SslOrder
{
    return SslOrder::create(array_merge([
        'client_id' => Client::factory()->create()->id,
        'domain' => 'shop.example.com',
        'module' => 'test',
        'status' => 'Completed',
    ], $attributes));
}

test('a missing intermediate is fetched from the certificate and the root left out', function () {
    $pki = sslChainHierarchy();
    Http::preventStrayRequests();
    Http::fake([
        'http://ca.test/intermediate.crt' => Http::response($pki['intermediateDer']),
        'http://ca.test/root.crt' => Http::response($pki['root']),
    ]);
    $order = sslChainOrder(['cert' => $pki['leaf'], 'fullchain' => $pki['leaf']]);

    $result = app(SslProvisioningService::class)->downloadCertificate($order);

    expect($result['data']['ca_cert'])->toBe($pki['intermediate'])
        ->and($result['data']['fullchain'])->toBe($pki['leaf'].$pki['intermediate'])
        ->and($order->fresh()->ca_cert)->toBe($pki['intermediate']);
});

test('a chain sent in the wrong order with the root is put in order without the root', function () {
    $pki = sslChainHierarchy();
    Http::preventStrayRequests();
    $order = sslChainOrder([
        'cert' => $pki['leaf'],
        'ca_cert' => $pki['root']."\n".$pki['intermediate'],
        'fullchain' => $pki['leaf'].$pki['root'].$pki['intermediate'],
    ]);

    app(SslProvisioningService::class)->completeChain($order);

    expect($order->fresh())
        ->ca_cert->toBe($pki['intermediate'])
        ->fullchain->toBe($pki['leaf'].$pki['intermediate']);
});

test('a chain that cannot be completed is left as the provider sent it', function () {
    $pki = sslChainHierarchy();
    Http::preventStrayRequests();
    Http::fake(['http://ca.test/intermediate.crt' => Http::response('', 404)]);
    $order = sslChainOrder(['cert' => $pki['leaf'], 'ca_cert' => null, 'fullchain' => $pki['leaf']]);

    app(SslProvisioningService::class)->completeChain($order);

    expect($order->fresh())
        ->ca_cert->toBeNull()
        ->fullchain->toBe($pki['leaf']);
});
