<?php

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Server;
use App\Models\Service;
use App\Models\SslModuleSettings;
use App\Models\SslOrder;
use App\Services\SslProvisioningService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Servers\Plesk\PleskModule;

/*
 * A certificate the panel renews is validated and installed on the Plesk
 * server that hosts its domain, with no one copying records or files by hand:
 * the TXT record goes into the zone, GlobalSign is asked to look for it, and
 * the issued certificate replaces the old one for the website, mail and
 * webmail.
 */

/**
 * A private key and a certificate made from it, as an issued order holds.
 *
 * @return array{0: string, 1: string}
 */
function deployKeyPair(): array
{
    static $pair = null;
    if ($pair === null) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => '*.example.com'], $key, ['digest_alg' => 'sha256']);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 200, ['digest_alg' => 'sha256']), $cert);
        openssl_pkey_export($key, $pem);
        $pair = [$pem, $cert];
    }

    return $pair;
}

function deployServer(): Server
{
    return Server::factory()->create([
        'type' => 'plesk', 'hostname' => 'plesk.test', 'ip_address' => '', 'port' => 8443,
        'username' => 'admin', 'password' => 'admin-secret', 'access_hash' => 'sk-123',
    ]);
}

function deployOrder(Server $server, array $attributes = []): SslOrder
{
    $client = Client::factory()->create();
    Service::factory()->create([
        'client_id' => $client->id, 'server_id' => $server->id, 'domain' => 'example.com', 'status' => 'active',
        'product_id' => Product::factory()->create(['group_id' => ProductGroup::factory()->create()->id, 'server_type' => 'plesk'])->id,
    ]);
    $ssl = Service::factory()->create([
        'client_id' => $client->id, 'domain' => 'example.com', 'status' => 'active',
        'product_id' => Product::factory()->create(['group_id' => ProductGroup::factory()->create()->id, 'type' => 'ssl', 'ssl_module' => 'ssl2buy'])->id,
    ]);

    return SslOrder::create(array_merge([
        'client_id' => $client->id, 'service_id' => $ssl->id, 'module' => 'ssl2buy', 'domain' => '*.example.com',
        'remote_id' => '3268596', 'status' => 'Awaiting Issuance', 'private_key' => deployKeyPair()[0],
        'config_data' => ['ssl2buy_family' => 'globalsign', 'ssl2buy_product' => 102,
            'dcv' => ['method' => 'DNS', 'name' => 'example.com', 'type' => 'TXT', 'value' => '_globalsign-domain-verification=abc']],
    ], $attributes));
}

/**
 * Plesk's XML API and command line, and SSL2BUY, as this flow meets them.
 * The certificate is issued once DNS verification has been asked for, or
 * from the start when $issued.
 *
 * @return object{xml: array<int, string>, cli: array<int, array<int, string>>}
 */
function fakeDeployment(string $siteCertificate = 'examplewild-2025-26', string $mailCertificate = 'examplewild-2025-26', bool $issued = false): object
{
    $seen = (object) ['xml' => [], 'cli' => [], 'verified' => $issued];
    Http::preventStrayRequests();
    Http::fake([
        'https://plesk.test:8443/enterprise/control/agent.php' => function (Request $request) use ($seen) {
            $body = $request->body();
            $seen->xml[] = $body;
            $reply = match (true) {
                str_contains($body, '<parent-name>') => '<site><get>'
                    .'<result><status>ok</status><id>92</id><data><gen_info><name>shop.example.com</name></gen_info></data></result>'
                    .'<result><status>ok</status><id>93</id><data><gen_info><name>example.org</name></gen_info></data></result></get></site>',
                str_contains($body, '<site><get>') => '<site><get><result><status>ok</status><id>91</id><data><gen_info><name>example.com</name></gen_info></data></result></get></site>',
                str_contains($body, '<get_rec>') => '<dns><get_rec><result><status>ok</status><id>7</id><data><site-id>91</site-id><type>TXT</type><host>example.com.</host><value>v=spf1 +a ~all</value></data></result></get_rec></dns>',
                str_contains($body, '<add_rec>') => '<dns><add_rec><result><status>ok</status><id>14289</id></result></add_rec></dns>',
                str_contains($body, '<del_rec>') => '<dns><del_rec><result><status>ok</status><id>14289</id></result></del_rec></dns>',
                str_contains($body, '<get-pool>') => '<certificate><get-pool><result><status>ok</status><certificates><certificate><name>examplewild-2025-26</name></certificate></certificates></result></get-pool></certificate>',
                str_contains($body, '<install>') => '<certificate><install><result><status>ok</status></result></install></certificate>',
            };

            return Http::response('<?xml version="1.0"?><packet>'.$reply.'</packet>');
        },
        'https://plesk.test:8443/api/v2/cli/*' => function (Request $request) use ($seen, $siteCertificate, $mailCertificate) {
            $seen->cli[] = [basename(dirname($request->url())), ...$request['params']];
            $stdout = match ($request['params'][0]) {
                '--info' => str_contains($request->url(), '/site/')
                    ? "Certificate:                            {$siteCertificate}\nSSL/TLS support:                        On\n"
                    : "SSL/TLS certificate for mail:                     {$mailCertificate}\nSSL/TLS certificate for webmail:                  {$mailCertificate}\n",
                default => 'SUCCESS',
            };

            return Http::response(['code' => 0, 'stdout' => $stdout, 'stderr' => '']);
        },
        'api.ssl2buy.com/orderservice/GlobalSignFullOrder/GSDNSVerification' => function () use ($seen) {
            $seen->verified = true;

            return Http::response(['CertificateStatus' => 'ISSUED', 'StatusCode' => 0]);
        },
        'api.ssl2buy.com/queryservice/globalsign/getorderdetails' => function () use ($seen) {
            return Http::response($seen->verified
                ? ['OrderStatus' => 'COMPLETED', 'StartDate' => '2026-11-19T06:57:56', 'EndDate' => '2027-06-06T06:57:56', 'StatusCode' => 0]
                : ['OrderStatus' => 'WAIT_FOR_APPROVAL', 'DNSText' => '_globalsign-domain-verification=abc', 'StatusCode' => 0]);
        },
        'api.ssl2buy.com/orderservice/GlobalSignFullOrder/GetCertificate' => Http::response(['X509Cert' => deployKeyPair()[1], 'InterCert' => 'INTER', 'StatusCode' => 0]),
    ]);

    return $seen;
}

beforeEach(function () {
    SslModuleSettings::setSetting('ssl2buy', 'partner_email', 'reseller@example.com');
    SslModuleSettings::setSetting('ssl2buy', 'api_key', 'secret-key');
});

test('a renewal waiting for DNS validation is validated and installed on the hosting with one status check', function () {
    $seen = fakeDeployment();
    $order = deployOrder(deployServer());

    app(SslProvisioningService::class)->pollCertificateStatus($order);

    $added = collect($seen->xml)->first(fn ($xml) => str_contains($xml, '<add_rec>'));
    expect($added)->toContain('<site-id>91</site-id><type>TXT</type><host></host><value>_globalsign-domain-verification=abc</value>');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/GSDNSVerification')
        && $request['OrderNumber'] === 3268596 && $request['DomainToVerify'] === 'example.com');

    $install = collect($seen->xml)->first(fn ($xml) => str_contains($xml, '<install>'));
    expect($install)->toContain('<name>example.com-wildcard-2027-06-06</name><site>example.com</site>')
        ->toContain('<pvt>'.deployKeyPair()[0].'</pvt><cert>'.deployKeyPair()[1])
        ->and($seen->cli)->toContain(['site', '--update', 'example.com', '-certificate-name', 'example.com-wildcard-2027-06-06'])
        ->and($seen->cli)->toContain(['site', '--update', 'shop.example.com', '-certificate-name', 'example.com-wildcard-2027-06-06'])
        ->and(collect($seen->cli)->contains(fn ($call) => ($call[2] ?? null) === 'example.org'))->toBeFalse()
        ->and($seen->cli)->toContain(['domain_pref', '--update', 'example.com', '-mail_certificate', 'example.com-wildcard-2027-06-06', '-webmail_certificate', 'example.com-wildcard-2027-06-06'])
        ->and(collect($seen->xml)->contains(fn ($xml) => str_contains($xml, '<del_rec><filter><id>14289</id>')))->toBeTrue();

    $order->refresh();
    expect($order->config_data['installed_certificate'])->toBe('example.com-wildcard-2027-06-06')
        ->and($order->config_data['installed_certificate_hash'])->toBe(sha1($order->cert))
        ->and($order->config_data)->not->toHaveKey('dns_record');
});

test('a certificate already installed is not installed again on the next check', function () {
    $seen = fakeDeployment(issued: true);
    $order = deployOrder(deployServer(), ['status' => 'Completed', 'cert' => deployKeyPair()[1], 'crt_expires' => '2027-06-06']);
    $order->update(['config_data' => $order->config_data + ['installed_certificate_hash' => sha1(deployKeyPair()[1])]]);

    app(SslProvisioningService::class)->pollCertificateStatus($order);

    expect($order->refresh()->status)->toBe('Completed')
        ->and(collect($seen->xml)->contains(fn ($xml) => str_contains($xml, '<install>')))->toBeFalse();
});

test('the places on Let\'s Encrypt are left to renew themselves', function () {
    $seen = fakeDeployment(siteCertificate: 'Lets Encrypt example.com', issued: true);
    $order = deployOrder(deployServer(), ['status' => 'Completed', 'cert' => deployKeyPair()[1], 'crt_expires' => '2027-06-06']);

    app(SslProvisioningService::class)->pollCertificateStatus($order);

    expect(collect($seen->cli)->contains(fn ($call) => $call[0] === 'site' && $call[1] === '--update'))->toBeFalse()
        ->and(collect($seen->cli)->contains(fn ($call) => $call[0] === 'domain_pref' && $call[1] === '--update'))->toBeTrue();
});

test('a certificate whose key the customer holds is left for them to install', function () {
    $seen = fakeDeployment(issued: true);
    $order = deployOrder(deployServer(), ['status' => 'Completed', 'cert' => deployKeyPair()[1], 'private_key' => null]);

    app(SslProvisioningService::class)->pollCertificateStatus($order);

    expect(collect($seen->xml)->contains(fn ($xml) => str_contains($xml, '<install>')))->toBeFalse();
});

test('a domain not hosted here gets nothing published', function () {
    $seen = fakeDeployment();
    $order = deployOrder(deployServer());
    Service::where('domain', 'example.com')->whereNotNull('server_id')->update(['domain' => 'elsewhere.test']);

    app(SslProvisioningService::class)->pollCertificateStatus($order);

    expect($seen->xml)->toBe([])
        ->and($seen->verified)->toBeFalse();
});

test('after a reissue, the certificate issued before is not taken for the new one', function () {
    fakeDeployment(issued: true);
    $order = deployOrder(deployServer(), ['status' => 'Awaiting Issuance']);
    $order->update(['private_key' => openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048]), $other) ? $other : null]);

    app(SslProvisioningService::class)->pollCertificateStatus($order);

    expect($order->refresh()->status)->toBe('Awaiting Issuance')
        ->and($order->cert)->toBeNull();
});

test('the administrator installs an order that was already complete, and sees under what name', function () {
    $seen = fakeDeployment(issued: true);
    $order = deployOrder(deployServer(), ['status' => 'Completed', 'cert' => deployKeyPair()[1], 'crt_expires' => '2027-06-06']);
    $admin = Admin::factory()->create(['role_id' => AdminRole::factory()->fullAdmin()->create()->id]);

    $this->actingAs($admin, 'admin')
        ->post(route('admin.ssl.action', $order), ['action' => 'install'])
        ->assertSessionHas('success');

    expect(collect($seen->xml)->contains(fn ($xml) => str_contains($xml, '<install>')))->toBeTrue();
    $this->actingAs($admin, 'admin')->get(route('admin.ssl.show', $order))
        ->assertSee('example.com-wildcard-2027-06-06');
});

test('an imported certificate attached to the hosting moves onto the SSL service created to bill it', function () {
    $order = deployOrder(deployServer());
    $admin = Admin::factory()->create(['role_id' => AdminRole::factory()->fullAdmin()->create()->id]);
    $hosting = Service::where('client_id', $order->client_id)->whereNotNull('server_id')->first();
    $order->update(['service_id' => $hosting->id]);
    $billing = Service::factory()->create(['client_id' => $order->client_id, 'domain' => 'example.com', 'product_id' => Product::factory()->create(['group_id' => ProductGroup::factory()->create()->id, 'type' => 'ssl'])->id]);
    $stranger = Service::factory()->create(['client_id' => Client::factory()->create()->id]);
    $taken = Service::where('client_id', $order->client_id)->whereNull('server_id')->whereKeyNot($billing->id)->first();
    SslOrder::create(['client_id' => $order->client_id, 'service_id' => $taken->id, 'module' => 'ssl2buy', 'domain' => 'other.example.com', 'status' => 'Completed']);

    $this->actingAs($admin, 'admin')->put(route('admin.ssl.service', $order), ['service_id' => $stranger->id])->assertSessionHasErrors('service_id');
    $this->actingAs($admin, 'admin')->put(route('admin.ssl.service', $order), ['service_id' => $taken->id])->assertSessionHas('error');
    expect($order->refresh()->service_id)->toBe($hosting->id);

    $this->actingAs($admin, 'admin')->put(route('admin.ssl.service', $order), ['service_id' => $billing->id])->assertSessionHas('success');
    expect($order->refresh()->service_id)->toBe($billing->id);
});

test('a validation record already gone from the zone counts as removed', function () {
    Http::fake(['https://plesk.test:8443/enterprise/control/agent.php' => Http::response(
        '<?xml version="1.0"?><packet><dns><del_rec><result><status>error</status><errcode>1013</errcode><errtext>Record does not exist</errtext></result></del_rec></dns></packet>'
    )]);

    expect((new PleskModule)->removeDnsRecord(deployServer(), 5480))->toBeTrue();
});
