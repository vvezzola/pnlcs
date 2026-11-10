<?php

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Models\SslModuleSettings;
use App\Models\SslOrder;
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
