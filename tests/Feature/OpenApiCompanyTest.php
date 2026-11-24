<?php

use App\Models\AddonSetting;
use App\Models\Admin;
use App\Models\AdminRole;
use Illuminate\Support\Facades\Http;
use Modules\Addons\OpenApiCompany\CompanyRegister;
use Modules\Addons\OpenApiCompany\ItalianCompany;
use Modules\Addons\OpenApiCompany\OpenApiCompanyCall;
use Modules\Addons\OpenApiCompany\OpenApiCompanyServiceProvider;
use Modules\Addons\OpenApiCompany\OpenApiCompanyUnavailable;

/*
 * Italian companies from the business register through OpenAPI's Company
 * service. Every call is paid for: an invalid VAT number is never sent, an
 * answer is reused, and each call is recorded.
 */

const OAC_VAT = '12485671007';

beforeEach(function () {
    AddonSetting::setSetting('openapi_company', 'token', 'live-token');
});

function oacStart(array $overrides = []): array
{
    return ['data' => [array_merge([
        'taxCode' => OAC_VAT, 'companyName' => 'OPENAPI S.P.A.', 'vatCode' => OAC_VAT,
        'address' => ['registeredOffice' => ['streetName' => 'VIALE F TOMMASO MARINETTI 221', 'town' => 'ROMA', 'province' => 'RM', 'zipCode' => '00143']],
        'activityStatus' => 'ATTIVA', 'sdiCode' => 'USAL8PV',
    ], $overrides)], 'success' => true, 'message' => '', 'error' => null];
}

function oacFake(array $start, int $status = 200): void
{
    Http::fake([
        'https://company.openapi.com/IT-start/*' => Http::response($start, $status),
        'https://company.openapi.com/IT-pec/*' => Http::response(['data' => [['pec' => 'OpenAPI@LegalMail.it', 'history' => []]], 'success' => true]),
    ]);
}

test('a company comes back with its address, SDI code and PEC, and is asked only once', function () {
    oacFake(oacStart());

    $company = app(CompanyRegister::class)->find('IT '.OAC_VAT, 'check');
    $again = app(CompanyRegister::class)->find(OAC_VAT, 'check');

    expect($company->name)->toBe('OPENAPI S.P.A.')
        ->and($company->name)->toBe('OPENAPI S.P.A.')
        ->and($company->address)->toBe('Viale F Tommaso Marinetti 221')
        ->and($company->city)->toBe('Roma')
        ->and($company->postcode)->toBe('00143')
        ->and($company->province)->toBe('RM')
        ->and($company->sdiCode)->toBe('USAL8PV')
        ->and($company->pec)->toBe('openapi@legalmail.it')
        ->and($company->isCeased())->toBeFalse()
        ->and($again->pec)->toBe('openapi@legalmail.it');
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer live-token'));
    expect(OpenApiCompanyCall::pluck('endpoint')->all())->toBe(['IT-start', 'IT-pec'])
        ->and(OpenApiCompanyCall::first()->purpose)->toBe('check');
});

test('a closed company is reported as ceased and its PEC is not paid for', function () {
    oacFake(oacStart(['activityStatus' => 'CESSATA', 'sdiCode' => '0000000']));

    $company = app(CompanyRegister::class)->find(OAC_VAT, 'registration');

    expect($company->isCeased())->toBeTrue()
        ->and($company->sdiCode)->toBeNull()
        ->and($company->pec)->toBeNull();
    Http::assertSentCount(1);
});

test('a VAT number failing its check digit is never sent', function () {
    Http::fake();

    expect(app(CompanyRegister::class)->find('12485671008', 'registration'))->toBeNull()
        ->and(CompanyRegister::isValidVat('03268930983'))->toBeTrue()
        ->and(CompanyRegister::isValidVat('00000000000'))->toBeFalse();
    Http::assertNothingSent();
});

test('an unknown VAT number is remembered as unknown', function () {
    oacFake(['data' => null, 'success' => false, 'message' => 'not found'], 404);

    expect(app(CompanyRegister::class)->find(OAC_VAT, 'check'))->toBeNull()
        ->and(app(CompanyRegister::class)->find(OAC_VAT, 'check'))->toBeNull();
    Http::assertSentCount(1);
});

test('a refused token is said plainly', function () {
    Http::fake(['https://company.openapi.com/*' => Http::response(['success' => false, 'message' => 'Wrong Token'], 401)]);

    expect(fn () => app(CompanyRegister::class)->find(OAC_VAT, 'check'))->toThrow(OpenApiCompanyUnavailable::class);
});

test('staff look a VAT number up from the addon page', function () {
    oacFake(oacStart());
    (new OpenApiCompanyServiceProvider(app()))->boot();
    app('router')->getRoutes()->refreshNameLookups();
    $admin = Admin::factory()->create(['role_id' => AdminRole::create(['name' => 'Desk', 'permissions' => ['view_clients']])->id]);

    $this->actingAs($admin, 'admin')->post(route('admin.openapi-company.lookup'), ['vat_code' => OAC_VAT])
        ->assertSessionHas('openapi_company_result', fn (?array $result) => ($result['sdiCode'] ?? null) === 'USAL8PV');
    $this->actingAs($admin, 'admin')->post(route('admin.openapi-company.lookup'), ['vat_code' => '123'])
        ->assertSessionHas('error');
});

test('the register\'s capitals become an address as people write it', function (string $register, string $written) {
    expect(ItalianCompany::ordinaryCase($register))->toBe($written);
})->with([
    ['VIA DELL\'ARTIGIANATO 4', 'Via dell\'Artigianato 4'],
    ['PIAZZA DELLE DONNE LAVORATRICI 2', 'Piazza delle Donne Lavoratrici 2'],
    ['CORSO VITTORIO EMANUELE II 12', 'Corso Vittorio Emanuele II 12'],
    ['VIA XX SETTEMBRE 10', 'Via XX Settembre 10'],
    ['PRATO ALLO STELVIO', 'Prato allo Stelvio'],
    ['SANT\'ANGELO LODIGIANO', 'Sant\'Angelo Lodigiano'],
    ['Via Roma 1', 'Via Roma 1'],
]);
