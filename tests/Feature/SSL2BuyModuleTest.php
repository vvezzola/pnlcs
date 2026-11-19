<?php

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Models\SslModuleSettings;
use App\Models\SslOrder;
use App\Services\SslProvisioningService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Ssl\SSL2Buy\SSL2BuyModule;

/**
 * SSL2BUY full orders: the panel collects CSR, contact and validation method
 * and buys the certificate with them, then polls until it is issued.
 */
function ssl2buyCsr(string $domain = 'shop.example.com'): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_csr_export(openssl_csr_new(['commonName' => $domain], $key, ['digest_alg' => 'sha256']), $csr);

    return $csr;
}

function ssl2buyOrder(array $attributes = []): SslOrder
{
    $product = Product::factory()->create(['group_id' => ProductGroup::factory()->create()->id, 'type' => 'ssl', 'ssl_module' => 'ssl2buy']);
    $service = Service::factory()->create([
        'client_id' => Client::factory()->create()->id,
        'product_id' => $product->id,
        'domain' => 'shop.example.com',
    ]);

    return SslOrder::create(array_merge([
        'client_id' => $service->client_id,
        'service_id' => $service->id,
        'module' => 'ssl2buy',
        'domain' => 'shop.example.com',
        'status' => 'Awaiting Configuration',
        'csr' => ssl2buyCsr(),
        'webserver_type' => '2',
        'validation_method' => 'DNS',
        'admin_first_name' => 'Mario',
        'admin_last_name' => 'Rossi',
        'admin_email' => 'mario@example.com',
        'admin_phone' => '+39.0301234567',
        'admin_address' => 'Via Roma 1',
        'admin_city' => 'Brescia',
        'admin_state' => 'BS',
        'admin_zip' => '25100',
        'admin_country' => 'it',
    ], $attributes));
}

beforeEach(function () {
    SslModuleSettings::setSetting('ssl2buy', 'partner_email', 'reseller@example.com');
    SslModuleSettings::setSetting('ssl2buy', 'api_key', 'secret-key');
    SslModuleSettings::setSetting('ssl2buy', 'default_product', '351');
});

test('a certificate is bought with the configuration collected in the panel', function () {
    Http::fake(['api.ssl2buy.com/*' => Http::response([
        'SSL2BuyOrderNumber' => 987654, 'ComodoOrderNumber' => '55501', 'DomainName' => 'shop.example.com',
        'ApprovalMethod' => 'CNAMECSRHASH', 'UniqueValue' => 'abc123', 'StatusCode' => 0,
    ])]);
    $order = ssl2buyOrder();

    $result = (new SSL2BuyModule)->purchaseCertificate($order, []);

    expect($result['success'])->toBeTrue();
    Http::assertSent(function (Request $request) use ($order) {
        return $request->url() === 'https://api.ssl2buy.com/queryservice/ComodoFullOrder/SectigoPositiveSSL'
            && $request['PartnerEmail'] === 'reseller@example.com'
            && $request['ApiKey'] === 'secret-key'
            && $request['ApprovalMethod'] === 'CNAMECSRHASH'
            && $request['CSR'] === $order->csr
            && $request['WebServer'] === '2'
            && $request['ContactDetails']['CountryName'] === 'IT'
            && $request['ContactDetails']['City'] === 'Brescia';
    });

    $order->refresh();
    $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $order->csr));
    $sha256 = strtoupper(hash('sha256', $der));

    expect($order->remote_id)->toBe('987654')
        ->and($order->status)->toBe('Awaiting Issuance')
        ->and($order->cert_type)->toBe('Sectigo PositiveSSL')
        ->and($order->config_data['ssl2buy_product'])->toBe(351)
        ->and($order->config_data['dcv'])->toBe([
            'method' => 'DNS',
            'name' => '_'.strtoupper(md5($der)).'.shop.example.com',
            'type' => 'CNAME',
            'value' => substr($sha256, 0, 32).'.'.substr($sha256, 32).'.abc123.sectigo.com',
        ]);
});

test('the error SSL2BUY gives is the error the panel shows', function () {
    Http::fake(['api.ssl2buy.com/*' => Http::response([
        'Errors' => ['ErrorNumber' => -104, 'ErrorField' => 'Balance', 'ErrorMessage' => 'Insufficient balance.'],
        'StatusCode' => -1,
    ])]);
    $order = ssl2buyOrder();

    $result = (new SSL2BuyModule)->purchaseCertificate($order, []);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('Insufficient balance. (-104)')
        ->and($order->fresh()->remote_id)->toBeNull();
});

test('a wildcard is not sent with HTTP validation, which the authority refuses for it', function () {
    Http::fake();
    SslModuleSettings::setSetting('ssl2buy', 'default_product', '352');
    $order = ssl2buyOrder(['validation_method' => 'HTTP', 'domain' => '*.example.com']);

    $result = (new SSL2BuyModule)->purchaseCertificate($order, []);

    expect($result['success'])->toBeFalse()->and($result['message'])->toContain('wildcard');
    Http::assertNothingSent();
});

test('each panel product can buy its own certificate', function () {
    Http::fake(['api.ssl2buy.com/*' => Http::response(['SSL2BuyOrderNumber' => 1, 'StatusCode' => 0])]);
    $order = ssl2buyOrder(['validation_method' => 'EMAIL', 'approver_email' => 'admin@example.com']);
    SslModuleSettings::setSetting('ssl2buy', 'product_map', "9999=301, {$order->service->product_id}=362");

    (new SSL2BuyModule)->purchaseCertificate($order, []);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/ComodoFullOrder/SectigoEssentialSSL')
        && $request['ApprovalEmail'] === 'admin@example.com'
        && $request['OrganisationInfo']['FirstName'] === 'Mario');
});

test('an issued certificate is fetched with its chain and expiry', function () {
    Http::fake([
        'api.ssl2buy.com/queryservice/comodo/getorderdetails' => Http::response([
            'OrderStatus' => 'COMPLETED', 'StartDate' => '2026-11-10T00:00:00', 'EndDate' => '2027-11-10T23:59:59', 'StatusCode' => 0,
        ]),
        'api.ssl2buy.com/queryservice/ComodoFullOrder/GetCertificate' => Http::response([
            'Certificate' => 'CERT', 'IntermediateCertificate1' => 'INT1', 'IntermediateCertificate2' => 'INT2', 'RootCertificate' => 'ROOT', 'StatusCode' => 0,
        ]),
    ]);
    $order = ssl2buyOrder(['remote_id' => '987654', 'status' => 'Awaiting Issuance']);

    (new SSL2BuyModule)->getCertificateStatus($order);

    $order->refresh();
    expect($order->status)->toBe('Completed')
        ->and($order->cert)->toBe('CERT')
        ->and($order->ca_cert)->toBe("INT1\nINT2")
        ->and($order->fullchain)->toBe("CERT\nINT1\nINT2")
        ->and($order->crt_expires->toDateString())->toBe('2027-11-10');
});

test('the orders already on the account are listed across pages', function () {
    Http::fakeSequence('api.ssl2buy.com/*')
        ->push(['OrderList' => [['OrderNumber' => 11, 'DomainName' => 'a.example.com', 'OrderStatus' => 'COMPLETED', 'ExpireOn' => '2027-01-31T00:00:00']], 'TotalPages' => 2, 'StatusCode' => 0])
        ->push(['OrderList' => [['OrderNumber' => 12, 'DomainName' => 'b.example.com', 'OrderStatus' => 'WAIT_FOR_APPROVAL', 'ExpireOn' => null]], 'TotalPages' => 2, 'StatusCode' => 0]);

    $module = new SSL2BuyModule;
    $orders = $module->listRemoteOrders();

    expect($orders)->toHaveCount(2)
        ->and($orders[0])->toMatchArray(['remote_id' => '11', 'domain' => 'a.example.com', 'valid_till' => '2027-01-31'])
        ->and($module->mapRemoteStatus($orders[1]['status']))->toBe('Awaiting Issuance');
});

test('a running order listed without a domain takes it from its details, an ended one is not asked for', function () {
    $this->travelTo('2026-11-18');
    Http::fake([
        'api.ssl2buy.com/orderservice/order/getorderlist' => Http::response(['OrderList' => [
            ['OrderNumber' => 21, 'ProductName' => 'AlphaSSL Wildcard Certificate', 'DomainName' => '', 'OrderStatus' => 'Active', 'ExpireOn' => '2026-12-02T00:00:00'],
            ['OrderNumber' => 22, 'ProductName' => 'AlphaSSL Wildcard Certificate', 'DomainName' => '', 'OrderStatus' => 'Expired', 'ExpireOn' => '2023-01-03T00:00:00'],
        ], 'TotalPages' => 1, 'StatusCode' => 0]),
        'api.ssl2buy.com/queryservice/globalsign/getorderdetails' => Http::response([
            'DomainName' => '*.example.com', 'OrderStatus' => 'COMPLETED', 'StatusCode' => 0,
        ]),
    ]);

    $orders = (new SSL2BuyModule)->listRemoteOrders();

    expect($orders[0]['domain'])->toBe('*.example.com')
        ->and($orders[1]['domain'])->toBe('');
    Http::assertSentCount(2);
});

test('the administrator sees the DNS record the validation waits for', function () {
    $order = ssl2buyOrder(['remote_id' => '987654', 'status' => 'Awaiting Issuance', 'config_data' => ['dcv' => [
        'method' => 'DNS', 'name' => '_ABC.shop.example.com', 'type' => 'CNAME', 'value' => 'AAA.BBB.sectigo.com',
    ]]]);
    $admin = Admin::factory()->create(['role_id' => AdminRole::factory()->fullAdmin()->create()->id]);

    $this->actingAs($admin, 'admin')
        ->get(route('admin.ssl.show', $order))
        ->assertOk()
        ->assertSee('_ABC.shop.example.com')
        ->assertSee('AAA.BBB.sectigo.com');
});

test('an AlphaSSL wildcard is bought through the GlobalSign call with its own contact block', function () {
    Http::fake(['api.ssl2buy.com/*' => Http::response([
        'SSL2BuyOrderNumber' => 3300001, 'GSOrderNumber' => 'GSXX1', 'DNSText' => '_globalsign-domain-verification=abc', 'DNSVerificationDomains' => ['example.com'], 'StatusCode' => 0,
    ])]);
    SslModuleSettings::setSetting('ssl2buy', 'default_product', '102');
    $order = ssl2buyOrder(['domain' => '*.example.com', 'csr' => ssl2buyCsr('*.example.com')]);

    $result = (new SSL2BuyModule)->purchaseCertificate($order, []);

    expect($result['success'])->toBeTrue();
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.ssl2buy.com/orderservice/GlobalSignFullOrder/AlphaSSLWildCardFullOrder'
        && $request['ApprovalMethod'] === 'DNS'
        && $request['ApprovalEmail'] === 'admin@example.com'
        && $request['ContactInfo']['Phone'] === '+39.0301234567'
        && ! isset($request['WebServer']));

    $order->refresh();
    expect($order->remote_id)->toBe('3300001')
        ->and($order->config_data['ssl2buy_family'])->toBe('globalsign')
        ->and($order->config_data['dcv'])->toBe(['method' => 'DNS', 'name' => 'example.com', 'type' => 'TXT', 'value' => '_globalsign-domain-verification=abc']);
});

test('an imported order is found under its certificate authority and its certificate downloaded', function () {
    Http::fake([
        'api.ssl2buy.com/queryservice/globalsign/getorderdetails' => Http::response([
            'OrderStatus' => 'COMPLETED', 'StartDate' => '2026-07-29T12:35:43+00:00', 'EndDate' => '2027-02-13T12:35:43+00:00',
            'BaseOrderDetails' => ['ProductId' => 102, 'ProductName' => 'AlphaSSL Wildcard Certificate'], 'StatusCode' => 0,
        ]),
        'api.ssl2buy.com/orderservice/GlobalSignFullOrder/GetCertificate' => Http::response([
            'X509Cert' => 'CERT', 'InterCert' => 'INTER', 'RootCert' => 'ROOT', 'PKCS7Cert' => '', 'StatusCode' => 0,
        ]),
        'api.ssl2buy.com/*' => Http::response(['Errors' => ['ErrorNumber' => -102, 'ErrorMessage' => 'Invalid or missing Order Number.'], 'StatusCode' => -1]),
    ]);
    $order = ssl2buyOrder(['remote_id' => '3244452', 'status' => 'Completed', 'csr' => null]);

    (new SSL2BuyModule)->getCertificateStatus($order);

    $order->refresh();
    expect($order->cert)->toBe('CERT')
        ->and($order->fullchain)->toBe("CERT\nINTER")
        ->and($order->cert_type)->toBe('AlphaSSL Wildcard Certificate')
        ->and($order->crt_expires->toDateString())->toBe('2027-02-13')
        ->and($order->config_data['ssl2buy_family'])->toBe('globalsign');
});

test('the server software list is offered as code => name', function () {
    Http::fake(['api.ssl2buy.com/*' => Http::response([
        'WebServerTypeList' => ['$id' => '3', 'Apache/ModSSL' => '2', 'Plesk' => '30', 'OTHER' => '-1'], 'StatusCode' => 0,
    ])]);

    expect((new SSL2BuyModule)->getWebServerTypes())->toBe(['2' => 'Apache/ModSSL', '30' => 'Plesk', '-1' => 'OTHER']);
});

test('an order asked under the wrong authority is read again under the one its product names', function () {
    Http::fake([
        // SSL2BUY answers "success" with an empty status for another authority's order.
        'api.ssl2buy.com/queryservice/globalsign/getorderdetails' => Http::response([
            'OrderStatus' => null, 'BaseOrderDetails' => ['ProductName' => 'PrimeSSL DV Wildcard Certificate'], 'StatusCode' => 0,
        ]),
        'api.ssl2buy.com/queryservice/prime/getorderdetails' => Http::response([
            'OrderStatus' => 'Valid', 'EndDate' => '2027-03-19T00:00:00', 'BaseOrderDetails' => ['ProductName' => 'PrimeSSL DV Wildcard Certificate'], 'StatusCode' => 0,
        ]),
        'api.ssl2buy.com/queryservice/prime/getcertificate' => Http::response([
            'Certificate' => 'CERT', 'IntermediateCertificate1' => 'INT1', 'IntermediateCertificate2' => 'INT2', 'StatusCode' => 0,
        ]),
    ]);
    $order = ssl2buyOrder(['remote_id' => '3128536', 'status' => 'Completed', 'csr' => null]);

    (new SSL2BuyModule)->getCertificateStatus($order);

    $order->refresh();
    expect($order->status)->toBe('Completed')
        ->and($order->config_data['ssl2buy_family'])->toBe('prime')
        ->and($order->fullchain)->toBe("CERT\nINT1\nINT2");
});

test('the settings page lists what SSL2BUY sells and offers the certificates the panel can buy', function () {
    SslModuleSettings::setSetting('ssl2buy', 'partner_email', 'partner@example.com');
    SslModuleSettings::setSetting('ssl2buy', 'api_key', 'key');
    SslModuleSettings::setSetting('ssl2buy', 'default_product', '102');
    Http::preventStrayRequests();
    Http::fake(['api.ssl2buy.com/orderservice/order/getproductprice' => function (Request $request) {
        return match ($request['ProductId']) {
            102 => Http::response(['ProductName' => 'AlphaSSL Wildcard Certificate', 'Price' => 57.0, 'AddDomainPrice' => 0.0, 'StatusCode' => 0]),
            202 => Http::response(['ProductName' => 'PrimeSSL DV Wildcard Certificate', 'Price' => 45.0, 'AddDomainPrice' => 0.0, 'StatusCode' => 0]),
            default => Http::response(['Errors' => ['ErrorNumber' => -103, 'ErrorMessage' => 'Invalid ProductId or Validity Year.'], 'StatusCode' => -1]),
        };
    }]);
    $admin = Admin::factory()->create(['role_id' => AdminRole::factory()->fullAdmin()->create()->id]);

    $this->actingAs($admin, 'admin')
        ->get(route('admin.config.sslModules'))
        ->assertOk()
        ->assertSee('PrimeSSL DV Wildcard Certificate')
        ->assertSee('<option value="102" selected>AlphaSSL Wildcard (102) - 57.00</option>', false)
        ->assertDontSee('<option value="202"', false);
});

test('the settings still open, without prices, when SSL2BUY cannot be read', function () {
    SslModuleSettings::setSetting('ssl2buy', 'partner_email', 'partner@example.com');
    SslModuleSettings::setSetting('ssl2buy', 'api_key', 'key');
    Http::preventStrayRequests();
    Http::fake(['api.ssl2buy.com/orderservice/order/getproductprice' => Http::response('', 500)]);

    $fields = (new SSL2BuyModule)->getConfigFields();

    expect($fields['default_product']['options'][102])->toBe('AlphaSSL Wildcard (102)')
        ->and($fields)->not->toHaveKey('catalogue');
});

test('the settings do not call SSL2BUY before the credentials are entered', function () {
    Http::preventStrayRequests();

    expect((new SSL2BuyModule)->catalogue())->toBe([]);
});

test('an imported wildcard renews with a key and CSR made here, the customer as contact and DNS validation', function () {
    Http::fake(['api.ssl2buy.com/*' => Http::response([
        'SSL2BuyOrderNumber' => 3400001, 'GSOrderNumber' => 'GSXX2', 'DNSText' => '_globalsign-domain-verification=xyz', 'DNSVerificationDomains' => ['example.com'], 'StatusCode' => 0,
    ])]);
    $order = ssl2buyOrder([
        'domain' => '*.example.com', 'status' => 'Completed', 'remote_id' => '3103927', 'cert_type' => 'AlphaSSL Wildcard Certificate',
        'csr' => null, 'validation_method' => null, 'webserver_type' => null,
        'admin_first_name' => null, 'admin_last_name' => null, 'admin_email' => null, 'admin_phone' => null,
        'admin_address' => null, 'admin_city' => null, 'admin_state' => null, 'admin_zip' => null, 'admin_country' => null,
    ]);
    $order->client->update(['first_name' => 'Anna', 'last_name' => 'Bianchi', 'email' => 'anna@example.com', 'phone_prefix' => '+39', 'phone_number' => '030 1234567', 'country' => 'IT']);

    $result = (new SSL2BuyModule)->renewCertificate($order);

    expect($result['success'])->toBeTrue();
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.ssl2buy.com/orderservice/GlobalSignFullOrder/AlphaSSLWildCardFullOrder'
        && $request['ApprovalMethod'] === 'DNS'
        && $request['ContactInfo']['Email'] === 'anna@example.com'
        && $request['ContactInfo']['Phone'] === '+39.0301234567'
        && openssl_csr_get_subject($request['CSR'])['CN'] === '*.example.com');

    $order->refresh();
    $csrKey = openssl_pkey_get_details(openssl_csr_get_public_key($order->csr))['key'];
    $savedKey = openssl_pkey_get_details(openssl_pkey_get_private($order->private_key))['key'];
    expect($order->remote_id)->toBe('3400001')
        ->and($order->status)->toBe('Awaiting Issuance')
        ->and($savedKey)->toBe($csrKey)
        ->and($order->config_data['replaced_remote_ids'])->toBe(['3103927'])
        ->and($order->config_data['dcv']['value'])->toBe('_globalsign-domain-verification=xyz');
});

test('a renewal sends again the CSR the customer brought, whose key only they hold', function () {
    Http::fake(['api.ssl2buy.com/*' => Http::response(['SSL2BuyOrderNumber' => 3400002, 'StatusCode' => 0])]);
    $order = ssl2buyOrder(['status' => 'Completed', 'remote_id' => '987654']);
    $csr = $order->csr;

    (new SSL2BuyModule)->renewCertificate($order);

    Http::assertSent(fn (Request $request) => $request['CSR'] === $csr);
    expect($order->refresh()->private_key)->toBeNull();
});

test('a renewal SSL2BUY refuses leaves the order as it was', function () {
    Http::fake(['api.ssl2buy.com/*' => Http::response(['StatusCode' => -1, 'Errors' => ['ErrorMessage' => 'Insufficient balance']])]);
    $order = ssl2buyOrder(['domain' => '*.example.com', 'status' => 'Completed', 'remote_id' => '3103927', 'cert_type' => 'AlphaSSL Wildcard Certificate', 'csr' => null]);

    $result = (new SSL2BuyModule)->renewCertificate($order);

    $order->refresh();
    expect($result['success'])->toBeFalse()
        ->and($order->csr)->toBeNull()
        ->and($order->private_key)->toBeNull()
        ->and($order->remote_id)->toBe('3103927');
});

test('the import does not bring back as an order of its own a certificate already renewed', function () {
    $this->travelTo('2026-11-19');
    Http::fake(['api.ssl2buy.com/orderservice/order/getorderlist' => Http::response(['OrderList' => [
        ['OrderNumber' => 3103927, 'ProductName' => 'AlphaSSL Wildcard Certificate', 'DomainName' => '*.example.com', 'OrderStatus' => 'Active', 'ExpireOn' => '2026-11-28T00:00:00'],
    ], 'TotalPages' => 1, 'StatusCode' => 0])]);
    $renewed = ssl2buyOrder(['domain' => '*.example.com', 'status' => 'Awaiting Issuance', 'remote_id' => '3400001', 'config_data' => ['replaced_remote_ids' => ['3103927']]]);
    Service::factory()->create(['client_id' => $renewed->client_id, 'domain' => 'example.com', 'status' => 'active']);

    $result = app(SslProvisioningService::class)->importRemoteOrders('ssl2buy');

    expect($result['imported'])->toBe(0)
        ->and(SslOrder::count())->toBe(1);
});

test('a customer recorded as a company alone renews with the contact set in the settings', function () {
    Http::fake(['api.ssl2buy.com/*' => Http::response(['SSL2BuyOrderNumber' => 3400003, 'StatusCode' => 0])]);
    $order = ssl2buyOrder(['domain' => '*.example.com', 'status' => 'Completed', 'remote_id' => '3103927', 'cert_type' => 'AlphaSSL Wildcard Certificate',
        'csr' => null, 'admin_first_name' => null, 'admin_last_name' => null, 'admin_email' => null, 'admin_phone' => null]);
    $order->client->update(['first_name' => 'Altrad Italia Srl', 'last_name' => '', 'phone_number' => '']);
    $module = new SSL2BuyModule;

    $refused = $module->renewCertificate($order);

    expect($refused['success'])->toBeFalse()
        ->and($refused['message'])->toContain('last name, phone');
    Http::assertNothingSent();

    foreach (['contact_first_name' => 'Laura', 'contact_last_name' => 'Verdi', 'contact_email' => 'ssl@reseller.example', 'contact_phone' => '+39.0307654321'] as $key => $value) {
        SslModuleSettings::setSetting('ssl2buy', $key, $value);
    }

    expect((new SSL2BuyModule)->renewCertificate($order->refresh())['success'])->toBeTrue();
    Http::assertSent(fn (Request $request) => $request['ContactInfo']['FirstName'] === 'Laura'
        && $request['ContactInfo']['LastName'] === 'Verdi'
        && $request['ContactInfo']['Email'] === 'ssl@reseller.example'
        && $request['ContactInfo']['Phone'] === '+39.0307654321');
});
