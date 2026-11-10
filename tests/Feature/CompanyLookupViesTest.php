<?php

use App\Models\Setting;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\CompanyLookup\CompanyLookupService;

/*
 * VAT numbers of other EU countries are looked up in VIES, the European
 * Commission's free register: valid or not, and the registered name and
 * address where the member state shares them. Polish NIPs keep going to the
 * Polish registries.
 */

const VIES_IT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/IT/vat/03268930983';

function viesAnswer(array $overrides = []): array
{
    return array_merge([
        'isValid' => true, 'userError' => 'VALID', 'name' => 'VEZZOLA VITTORIO',
        'address' => "VIA VERDI N 12 INT 3 \n25080 NUVOLENTO BS\n", 'vatNumber' => '03268930983',
    ], $overrides);
}

beforeEach(fn () => Cache::flush());

it('reads an Italian VAT number from VIES into street, postcode, city and province', function () {
    Http::preventStrayRequests();
    Http::fake([VIES_IT => Http::response(viesAnswer())]);

    $result = app(CompanyLookupService::class)->lookup('IT 03268930983');

    expect($result['success'])->toBeTrue()
        ->and($result['sources'])->toBe(['vies' => true])
        ->and($result['company']['name'])->toBe('VEZZOLA VITTORIO')
        ->and($result['company']['nip'])->toBe('IT03268930983')
        ->and($result['company']['address'])->toMatchArray([
            'street' => 'VIA VERDI N 12 INT 3', 'postal_code' => '25080', 'city' => 'NUVOLENTO', 'voivodeship' => 'BS',
        ]);
});

it('takes a bare number to VIES when the client country is not Poland', function () {
    Http::preventStrayRequests();
    Http::fake([VIES_IT => Http::response(viesAnswer())]);

    $result = app(CompanyLookupService::class)->lookup('03268930983', 'IT');

    expect($result['success'])->toBeTrue()
        ->and($result['company']['nip'])->toBe('03268930983');
});

it('reports a number VIES does not know as not found', function () {
    Http::preventStrayRequests();
    Http::fake([VIES_IT => Http::response(viesAnswer(['isValid' => false, 'name' => '---', 'address' => '---']))]);

    expect(app(CompanyLookupService::class)->lookup('IT03268930983'))
        ->toMatchArray(['success' => false, 'error' => 'COMPANY_NOT_FOUND']);
});

it('does not call a register that is down "not found"', function () {
    Http::preventStrayRequests();
    Http::fake([VIES_IT => Http::response(viesAnswer(['isValid' => false, 'userError' => 'MS_UNAVAILABLE']))]);

    $result = app(CompanyLookupService::class)->lookup('IT03268930983');

    expect($result)->toMatchArray(['success' => false, 'error' => 'UNKNOWN_ERROR'])
        ->and($result['warnings'])->toHaveCount(1);
});

it('keeps a Polish NIP away from VIES', function () {
    Http::preventStrayRequests();
    Http::fake(['wl-api.mf.gov.pl/*' => Http::response(['result' => ['subject' => null]])]);

    app(CompanyLookupService::class)->lookup('PL5261040828');

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'ec.europa.eu'));
});

it('passes the client country from the form to the lookup', function () {
    Setting::set('addon_company_lookup_active', '1');
    Http::preventStrayRequests();
    Http::fake([VIES_IT => Http::response(viesAnswer())]);

    $this->postJson('/api/company/lookup', ['nip' => '03268930983', 'country' => 'IT'])
        ->assertOk()
        ->assertJsonPath('company.address.city', 'NUVOLENTO');
});
