<?php

use App\Models\Client;
use App\Models\Domain;
use App\Models\RegistrarSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Registrars\InternetBS\InternetBSRegistrar;

/*
 * The Internet.bs module, against the API as Internet.bs's own WHMCS module
 * and the Blesta module speak it: form POSTs to /<Command> with apikey and
 * password, plain "name=value" answers whose status is SUCCESS, PENDING or
 * FAILURE, lists numbered name_0, name_1...
 */

function ibsSettings(array $overrides = []): void
{
    foreach (array_merge(['api_key' => 'key-1', 'password' => 'pass-1', 'test_mode' => '1'], $overrides) as $setting => $value) {
        RegistrarSettings::updateOrCreate(['registrar' => 'internetbs', 'setting' => $setting], ['value' => $value]);
    }
}

function ibsDomain(string $name = 'example.com', array $client = []): Domain
{
    return Domain::factory()->create([
        'client_id' => Client::factory()->create(array_merge([
            'first_name' => 'Mario', 'last_name' => 'Rossi', 'company_name' => null,
            'email' => 'mario@example.test', 'country' => 'IT', 'state' => 'BG', 'city' => 'Bergamo',
            'phone_prefix' => '39', 'phone_number' => '035123456',
            'tax_id' => '01234567890', 'national_id' => 'RSSMRA80A01A794X',
        ], $client))->id,
        'domain' => $name,
        'registrar' => 'internetbs',
        'expiry_date' => '2027-03-05',
    ]);
}

/** @param  array<string, string>  $answers  command => plain-text body */
function fakeIbs(array $answers): void
{
    Http::preventStrayRequests();
    $fakes = [];
    foreach ($answers as $command => $body) {
        $fakes["https://testapi.internet.bs/{$command}"] = Http::response($body);
    }
    Http::fake($fakes);
}

/** The one request sent to a command, decoded. */
function ibsSent(string $command): array
{
    $sent = collect(Http::recorded())->first(fn ($pair) => str_ends_with($pair[0]->url(), '/'.$command));
    expect($sent)->not->toBeNull();

    return $sent[0]->data();
}

test('it signs every command with the api key and password, on the test host while test mode is on', function () {
    ibsSettings();
    fakeIbs(['Domain/RegistrarLock/Status' => "status=SUCCESS\ndomain=example.com\nregistrar_lock_status=LOCKED"]);

    expect((new InternetBSRegistrar)->getLockStatus(ibsDomain()))->toBeTrue();

    expect(ibsSent('Domain/RegistrarLock/Status'))->toMatchArray([
        'apikey' => 'key-1', 'password' => 'pass-1', 'domain' => 'example.com',
    ]);
});

test('it talks to the live host once test mode is off', function () {
    ibsSettings(['test_mode' => '0']);
    Http::preventStrayRequests();
    Http::fake(['https://api.internet.bs/Domain/Check' => Http::response("status=AVAILABLE\ndomain=free.com")]);

    expect((new InternetBSRegistrar)->checkAvailability('free.com')['available'])->toBeTrue();
});

test('it calls nothing until the api key and password are set', function () {
    Http::preventStrayRequests();

    expect((new InternetBSRegistrar)->testConnection())
        ->toMatchArray(['success' => false]);
});

test('a taken domain is not offered', function () {
    ibsSettings();
    fakeIbs(['Domain/Check' => "status=UNAVAILABLE\ndomain=example.com"]);

    expect((new InternetBSRegistrar)->checkAvailability('example.com')['available'])->toBeFalse();
});

test('a refused command reports the registrar reason', function () {
    ibsSettings();
    fakeIbs(['Domain/Create' => "status=FAILURE\ncode=100005\nmessage=Insufficient funds"]);

    expect((new InternetBSRegistrar)->register(ibsDomain(), 1))
        ->toMatchArray(['success' => false, 'message' => 'Internet.bs: Insufficient funds']);
});

test('sync reads the paid-until date, status, lock, privacy and nameservers', function () {
    ibsSettings();
    fakeIbs(['Domain/Info' => implode("\n", [
        'status=SUCCESS', 'domain=example.it', 'expirationdate=2026/12/01', 'paiduntil=2027/12/01',
        'registrationdate=2015/12/01', 'registrarlock=ENABLED', 'privatewhois=DISABLED',
        'domainstatus=REGISTERED', 'nameserver_0=NS1.Esalogica.com', 'nameserver_1=ns2.esalogica.com',
        'transferauthinfo=epp-123',
    ])]);

    expect((new InternetBSRegistrar)->syncDomain(ibsDomain('example.it')))->toBe([
        'success' => true,
        'registration_date' => '2015-12-01',
        'expiry_date' => '2027-12-01',
        'status' => 'active',
        'locked' => true,
        'nameservers' => ['ns1.esalogica.com', 'ns2.esalogica.com'],
        'id_protection' => false,
    ]);
});

test('sync falls back to the expiration date when nothing is paid ahead', function () {
    ibsSettings();
    fakeIbs(['Domain/Info' => "status=SUCCESS\nexpirationdate=2026/12/01\npaiduntil=n/a\ndomainstatus=EXPIRED"]);

    expect((new InternetBSRegistrar)->syncDomain(ibsDomain()))
        ->toMatchArray(['expiry_date' => '2026-12-01', 'status' => 'expired']);
});

test('the EPP code is the transfer auth info', function () {
    ibsSettings();
    fakeIbs(['Domain/Info' => "status=SUCCESS\ntransferauthinfo=epp-123"]);

    expect((new InternetBSRegistrar)->getEPPCode(ibsDomain()))->toBe('epp-123');
});

test('a renewal names the expiry it renews and keeps the registry date', function () {
    ibsSettings();
    fakeIbs(['Domain/Renew' => "status=SUCCESS\nproduct_0_status=SUCCESS\nproduct_0_newExpiration=2028/03/05"]);
    $domain = ibsDomain();

    $result = (new InternetBSRegistrar)->renew($domain, 1);

    expect($result['success'])->toBeTrue()
        ->and(ibsSent('Domain/Renew'))->toMatchArray(['period' => '1Y', 'currentexpiration' => '2027-03-05'])
        ->and($domain->fresh()->expiry_date->toDateString())->toBe('2028-03-05')
        ->and($domain->fresh()->next_due_date->toDateString())->toBe('2028-03-05');
});

test('a failed renewal leaves the dates alone', function () {
    ibsSettings();
    fakeIbs(['Domain/Renew' => "status=FAILURE\nmessage=Current expiration date does not match"]);
    $domain = ibsDomain();

    expect((new InternetBSRegistrar)->renew($domain, 1)['success'])->toBeFalse()
        ->and($domain->fresh()->expiry_date->toDateString())->toBe('2027-03-05');
});

test('nameservers are saved as one comma separated list', function () {
    ibsSettings();
    fakeIbs(['Domain/Update' => 'status=SUCCESS']);

    expect((new InternetBSRegistrar)->saveNameservers(ibsDomain(), ['NS1.esalogica.com', 'ns2.esalogica.com', '']))->toBeTrue()
        ->and(ibsSent('Domain/Update')['ns_list'])->toBe('ns1.esalogica.com,ns2.esalogica.com');
});

test('a .it registration for a person carries the registry fields of a natural person', function () {
    ibsSettings();
    fakeIbs(['Domain/Create' => 'status=SUCCESS']);

    expect((new InternetBSRegistrar)->register(ibsDomain('rossi.it'), 1)['success'])->toBeTrue();

    expect(ibsSent('Domain/Create'))->toMatchArray([
        'domain' => 'rossi.it',
        'period' => '1Y',
        'registrant_firstname' => 'Mario',
        'registrant_phonenumber' => '+39.035123456',
        'registrant_dotitentitytype' => '1',
        'registrant_dotitnationality' => 'IT',
        'registrant_dotitprovince' => 'BG',
        'registrant_dotitregcode' => 'RSSMRA80A01A794X',
        'registrant_dotithidewhois' => 'YES',
        'registrant_dotitterm3' => 'no',
    ]);
});

test('a .it registration for a company uses its VAT number and shows it in whois', function () {
    ibsSettings();
    fakeIbs(['Domain/Create' => 'status=SUCCESS']);

    (new InternetBSRegistrar)->register(ibsDomain('rossi.it', ['company_name' => 'Rossi Srl']), 1);

    expect(ibsSent('Domain/Create'))->toMatchArray([
        'Registrant_Organization' => 'Rossi Srl',
        'registrant_dotitentitytype' => '2',
        'registrant_dotitregcode' => '01234567890',
        'registrant_dotithidewhois' => 'NO',
        'registrant_dotitterm3' => 'yes',
    ]);
});

test('other extensions carry no .it fields', function () {
    ibsSettings();
    fakeIbs(['Domain/Create' => 'status=SUCCESS']);

    (new InternetBSRegistrar)->register(ibsDomain('rossi.com'), 1);

    expect(array_filter(array_keys(ibsSent('Domain/Create')), fn ($key) => str_contains($key, 'dotit')))->toBe([]);
});

test('it lists the domains in the account', function () {
    ibsSettings();
    Http::preventStrayRequests();
    Http::fake(['https://testapi.internet.bs/Domain/List' => Http::response(
        ['transactid' => 't', 'status' => 'SUCCESS', 'domaincount' => 2, 'domain' => ['Rossi.it', 'bianchi.com']]
    )]);

    expect((new InternetBSRegistrar)->listDomains())->toBe(['rossi.it', 'bianchi.com']);
    Http::assertSent(fn (Request $request) => $request['ResponseFormat'] === 'JSON');
});
