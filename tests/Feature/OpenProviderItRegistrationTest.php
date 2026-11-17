<?php

use App\Models\Client;
use App\Models\Domain;
use App\Models\RegistrarSettings;
use Illuminate\Support\Facades\Http;
use Modules\Registrars\OpenProvider\OpenProviderRegistrar;

/*
 * Registro .it wants the owner's tax identity: a company's VAT number, a
 * person's Codice Fiscale. Openprovider files them on the handle ("vat" and
 * additional_data.social_security_number, as its own WHMCS module sends
 * them), and the Registro .it declarations go with the domain when the
 * operator has switched them on.
 */

function itSettings(array $extra = []): void
{
    foreach (['username' => 'reseller', 'password' => 'secret', 'test_mode' => '1'] + $extra as $k => $v) {
        RegistrarSettings::updateOrCreate(['registrar' => 'openprovider', 'setting' => $k], ['value' => $v]);
    }
}

function itDomain(array $client, string $name = 'rossi.it'): Domain
{
    $owner = Client::factory()->create($client + ['country' => 'IT', 'city' => 'Brescia']);

    return Domain::factory()->create(['client_id' => $owner->id, 'domain' => $name, 'registrar' => 'OpenProvider']);
}

function itFake(): void
{
    Http::fake([
        '*/auth/login' => Http::response(['code' => 0, 'desc' => '', 'data' => ['token' => 't']]),
        '*/customers/*' => Http::response(['code' => 0, 'desc' => '', 'data' => ['success' => true]]),
        '*/customers' => Http::response(['code' => 0, 'desc' => '', 'data' => ['handle' => 'RS000001-IT']]),
        '*/domains/transfer' => Http::response(['code' => 0, 'desc' => '', 'data' => ['id' => 9]]),
        '*/domains' => Http::response(['code' => 0, 'desc' => '', 'data' => ['id' => 8]]),
    ]);
}

test('a company registers .it with its VAT number on the handle', function () {
    itSettings();
    itFake();
    $domain = itDomain(['client_type' => 'company', 'company_name' => 'Rossi Srl', 'tax_id' => '0123 4567 890', 'national_id' => null]);

    expect((new OpenProviderRegistrar)->register($domain, 1)['success'])->toBeTrue();

    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/customers')
        && $r['vat'] === '01234567890');
    Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/customers/RS000001-IT')
        && $r['vat'] === '01234567890');
    // Declarations are off until the operator switches them on.
    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/domains')
        && ! isset($r['additional_data']));
});

test('a person registers .it with the Codice Fiscale, and the declarations when switched on', function () {
    itSettings(['it_declarations' => '1']);
    itFake();
    $domain = itDomain(['client_type' => 'individual', 'company_name' => null, 'tax_id' => null, 'national_id' => 'rssmra80a01b157x']);

    expect((new OpenProviderRegistrar)->register($domain, 1)['success'])->toBeTrue();

    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/customers')
        && ($r['additional_data']['social_security_number'] ?? null) === 'RSSMRA80A01B157X'
        && ! isset($r['vat']));
    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/domains')
        && $r['additional_data'] === ['it_nexus_declaration' => 'YES', 'it_reseller_declaration' => 'YES']);
});

test('a handle created earlier gets the identity before a .it transfer', function () {
    itSettings(['it_declarations' => '1']);
    itFake();
    $domain = itDomain(['client_type' => 'company', 'company_name' => 'Rossi Srl', 'tax_id' => '01234567890']);
    RegistrarSettings::updateOrCreate(['registrar' => 'openprovider', 'setting' => 'handle_client_'.$domain->client_id], ['value' => 'OLD000001-IT']);

    expect((new OpenProviderRegistrar)->transfer($domain, 'code')['success'])->toBeTrue();

    Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/customers/OLD000001-IT') && $r['vat'] === '01234567890');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/domains/transfer') && $r['additional_data']['it_reseller_declaration'] === 'YES');
});

test('.it without a VAT number or tax code is refused before reaching the registry', function () {
    itSettings();
    itFake();
    $domain = itDomain(['client_type' => 'company', 'company_name' => 'Rossi Srl', 'tax_id' => null, 'national_id' => null]);

    $result = (new OpenProviderRegistrar)->register($domain, 1);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('VAT number');
    Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/domains'));
});

test('other extensions are registered as before', function () {
    itSettings(['it_declarations' => '1']);
    itFake();
    $domain = itDomain(['client_type' => 'company', 'company_name' => 'Rossi Srl', 'tax_id' => null], 'rossi.com');

    expect((new OpenProviderRegistrar)->register($domain, 1)['success'])->toBeTrue();

    Http::assertNotSent(fn ($r) => $r->method() === 'PUT');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/domains') && ! isset($r['additional_data']));
});
