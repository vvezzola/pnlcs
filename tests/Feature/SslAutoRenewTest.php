<?php

use App\Models\Client;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Models\SslModuleSettings;
use App\Models\SslOrder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Certificates the panel manages renew by themselves before they end: a free
 * reissue while the subscription runs on, a new order, paid for, once it does
 * not, and only for a customer whose SSL service is active and paid.
 */

function autoRenewOrder(array $attributes = [], array $service = []): SslOrder
{
    $client = Client::factory()->create();
    $ssl = Service::factory()->create(array_merge([
        'client_id' => $client->id, 'domain' => 'example.com', 'status' => 'active', 'auto_renew' => true, 'amount' => 90,
        'next_due_date' => '2028-10-16',
        'product_id' => Product::factory()->create(['group_id' => ProductGroup::factory()->create()->id, 'type' => 'ssl', 'ssl_module' => 'ssl2buy'])->id,
    ], $service));
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $pem);

    return SslOrder::create(array_merge([
        'client_id' => $client->id, 'service_id' => $ssl->id, 'module' => 'ssl2buy', 'domain' => '*.example.com',
        'remote_id' => '3268596', 'status' => 'Completed', 'cert' => 'CERT', 'private_key' => $pem,
        'csr' => 'OLD-CSR', 'validation_method' => 'DNS', 'cert_type' => 'AlphaSSL Wildcard',
        'admin_first_name' => 'Laura', 'admin_last_name' => 'Verdi', 'admin_email' => 'ssl@reseller.example', 'admin_phone' => '+39.0307654321',
        'order_date' => '2026-11-19 06:25:22', 'crt_expires' => '2027-06-06',
        'config_data' => ['ssl2buy_family' => 'globalsign', 'ssl2buy_product' => 102, 'ssl2buy_years' => 1, 'ssl2buy_amount' => 57, 'ssl2buy_paid_on' => '2026-11-19'],
    ], $attributes));
}

beforeEach(function () {
    SslModuleSettings::setSetting('ssl2buy', 'partner_email', 'reseller@example.com');
    SslModuleSettings::setSetting('ssl2buy', 'api_key', 'secret-key');
    SslModuleSettings::setSetting('ssl2buy', 'auto_renew_days', '20');
    Http::preventStrayRequests();
});

/**
 * SSL2BUY, answering the calls a test names and taking every other order.
 *
 * @param  array<string, mixed>  $answers
 */
function autoRenewFake(array $answers = []): void
{
    Http::fake($answers + ['api.ssl2buy.com/*' => Http::response(['SSL2BuyOrderNumber' => 3500001, 'GSOrderNumber' => 'GSNEW', 'StatusCode' => 0])]);
}

test('a certificate ending within its subscription is reissued with a new key, at no cost', function () {
    autoRenewFake();
    $this->travelTo('2027-05-20 07:30:00');
    $order = autoRenewOrder();
    $oldKey = $order->private_key;

    $this->artisan('pnlcs:ssl-auto-renew')->assertSuccessful();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/GSReissueCertificate')
        && $request['OrderNumber'] === 3268596
        && openssl_csr_get_subject($request['CSR'])['CN'] === '*.example.com');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'FullOrder/AlphaSSL'));

    $order->refresh();
    expect($order->status)->toBe('Awaiting Issuance')
        ->and($order->private_key)->not->toBe($oldKey)
        ->and($order->remote_id)->toBe('3268596');
});

test('a certificate ending with its subscription is bought again', function () {
    autoRenewFake();
    $this->travelTo('2027-11-05 07:30:00');
    $order = autoRenewOrder(['crt_expires' => '2027-11-19']);

    $this->artisan('pnlcs:ssl-auto-renew')->assertSuccessful();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/AlphaSSLWildCardFullOrder') && $request['Year'] === 1);
    expect($order->refresh()->remote_id)->toBe('3500001');
});

test('nothing is bought twice for the same certificate, even after a failure', function () {
    $this->travelTo('2027-11-05 07:30:00');
    Http::fake(['api.ssl2buy.com/*' => Http::response(['StatusCode' => -1, 'Errors' => ['ErrorMessage' => 'Insufficient balance']])]);
    autoRenewOrder(['crt_expires' => '2027-11-19']);

    $this->artisan('pnlcs:ssl-auto-renew')->assertSuccessful();
    $this->artisan('pnlcs:ssl-auto-renew')->assertSuccessful();

    Http::assertSentCount(1);
});

test('a customer who has not paid, a certificate not yet due, or no setting renews nothing', function (array $order, array $service, ?string $days) {
    autoRenewFake();
    $this->travelTo('2027-11-05 07:30:00');
    SslModuleSettings::setSetting('ssl2buy', 'auto_renew_days', (string) $days);
    autoRenewOrder(array_merge(['crt_expires' => '2027-11-19'], $order), $service);

    $this->artisan('pnlcs:ssl-auto-renew')->assertSuccessful();

    Http::assertNothingSent();
})->with([
    'service unpaid' => [[], ['next_due_date' => '2027-11-05'], '20'],
    'service set not to renew' => [[], ['auto_renew' => false], '20'],
    'not yet within the days' => [['crt_expires' => '2027-12-20'], [], '20'],
    'customer holds the key' => [['private_key' => null], [], '20'],
    'auto renew off' => [[], [], ''],
]);

test('an imported order is read first, and the first year of a subscription bought for two is reissued, not bought', function () {
    $this->travelTo('2027-01-25 07:30:00');
    autoRenewFake([
        'api.ssl2buy.com/queryservice/globalsign/getorderdetails' => Http::response([
            'DomainName' => '*.esalogica.com', 'OrderStatus' => 'COMPLETED', 'StartDate' => '2026-07-29T12:35:43+00:00', 'EndDate' => '2027-02-13T12:35:43+00:00',
            'ValidityPeriod' => 12, 'BaseOrderDetails' => ['ProductName' => 'AlphaSSL Wildcard Certificate', 'Year' => 2, 'TotalAmount' => 104.5, 'PaidDate' => '2026-07-29T12:24:31.853'],
            'StatusCode' => 0,
        ]),
        'api.ssl2buy.com/orderservice/GlobalSignFullOrder/GetCertificate' => Http::response(['X509Cert' => 'CERT', 'StatusCode' => 0]),
        'api.ssl2buy.com/orderservice/GlobalSignFullOrder/GSReissueCertificate' => Http::response(['GSOrderNumber' => 'GSNEW', 'StatusCode' => 0]),
    ]);
    $order = autoRenewOrder(['remote_id' => '3244452', 'crt_expires' => '2027-02-13', 'private_key' => null, 'csr' => null, 'order_date' => null,
        'config_data' => ['ssl2buy_family' => 'globalsign']]);

    $this->artisan('pnlcs:ssl-auto-renew')->assertSuccessful();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/GSReissueCertificate'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'FullOrder/AlphaSSL'));
    expect($order->refresh()->config_data)->toMatchArray(['ssl2buy_years' => 2, 'ssl2buy_paid_on' => '2026-07-29', 'ssl2buy_validity_months' => 12]);
});

test('a year of a subscription that has further years to come is not bought again', function () {
    autoRenewFake();
    $this->travelTo('2027-07-15 07:30:00');
    autoRenewOrder(['crt_expires' => '2027-07-29', 'config_data' => [
        'ssl2buy_family' => 'globalsign', 'ssl2buy_product' => 102, 'ssl2buy_years' => 2, 'ssl2buy_amount' => 104.5, 'ssl2buy_paid_on' => '2026-07-29',
    ]]);

    $this->artisan('pnlcs:ssl-auto-renew')
        ->expectsOutputToContain('waits at the provider')
        ->assertSuccessful();

    Http::assertNothingSent();
});

test('a service given at no charge, never invoiced, still has its certificate reissued', function () {
    autoRenewFake();
    $this->travelTo('2027-05-20 07:30:00');
    autoRenewOrder([], ['amount' => 0, 'next_due_date' => '2027-01-01']);

    $this->artisan('pnlcs:ssl-auto-renew')->assertSuccessful();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/GSReissueCertificate'));
});
