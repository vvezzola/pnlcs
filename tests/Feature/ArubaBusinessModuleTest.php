<?php

use App\Models\Client;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Servers\ArubaBusiness\ArubaBusinessModule;

/*
 * PEC mailboxes held in an Aruba Business account
 * (modules/Servers/ArubaBusiness, Esalogica only). Aruba is faked at the HTTP
 * level with the shapes its API answers with. The module reads and changes
 * nothing there: the only request that is not a GET is the sign-in.
 */

const AB_API = 'https://api.arubabusiness.it/';

/** @return array<string, mixed> a service as Aruba lists it */
function abRow(array $over = []): array
{
    return array_merge([
        'Id' => 569906, 'Domain' => 'rossi.it', 'Article' => 'PEC Standard', 'State' => 'Active',
        'ActivationDate' => '2017-11-15T18:01:44.183', 'ExpirationDate' => '2027-11-15T18:01:44.183',
        'UserNameAccount' => 'Info@pec.rossi.it', 'AutomaticRenewal' => true, 'RenewalPrice' => '10,00 €',
    ], $over);
}

/**
 * A fake Aruba Business account that lists $rows.
 *
 * @param  list<array<string, mixed>>  $rows
 * @param  list<int>  $statuses  the status of each call to the list in turn; 200 once they run out
 */
function abAccount(array $rows, array $statuses = []): void
{
    Cache::flush();
    Http::preventStrayRequests();
    Http::fake([
        AB_API.'auth/token' => Http::response(['access_token' => 'token-of-the-day', 'expires_in' => 86400], 200),
        AB_API.'api/services' => function () use ($rows, &$statuses) {
            $status = array_shift($statuses) ?? 200;

            return Http::response($status === 200 ? $rows : ['Message' => 'no'], $status);
        },
    ]);
}

function abServer(array $over = []): Server
{
    return Server::factory()->create(array_merge([
        'type' => ArubaBusinessModule::NAME, 'hostname' => 'api.arubabusiness.it', 'ip_address' => '', 'port' => null,
        'username' => 'delegato', 'password' => 'la-password', 'access_hash' => 'la-chiave', 'active' => true, 'disabled' => false,
    ], $over));
}

function abService(Server $server, array $moduleData = [], array $over = []): Service
{
    return Service::factory()->create(array_merge([
        'client_id' => Client::factory()->create()->id,
        'product_id' => Product::factory()->create(['server_type' => ArubaBusinessModule::NAME, 'config_options' => ['package_name' => 'PEC Standard']])->id,
        'server_id' => $server->id, 'status' => 'active', 'next_due_date' => '2027-11-15',
        'module_data' => $moduleData ?: null,
    ], $over));
}

function abModule(): ArubaBusinessModule
{
    return app(ArubaBusinessModule::class);
}

it('lists the mailboxes and certified domains, and nothing else of the account', function () {
    abAccount([
        abRow(),
        abRow(['Id' => 2, 'Article' => 'Certificazione Dominio esterno', 'UserNameAccount' => '', 'Domain' => 'Bianchi.it']),
        abRow(['Id' => 3, 'State' => 'Canceled']),
        abRow(['Id' => 4, 'Article' => 'Registrazione dominio .it', 'UserNameAccount' => '']),
        abRow(['Id' => 5, 'State' => 'ToCancel', 'UserNameAccount' => 'a@pec.verdi.it']),
    ]);

    expect(abModule()->listAccountDetails(abServer()))->toBe([
        ['id' => '5', 'domain' => 'a@pec.verdi.it', 'owner' => '', 'email' => 'a@pec.verdi.it', 'status' => 'suspended', 'plan' => 'PEC Standard', 'created' => '2017-11-15'],
        ['id' => '2', 'domain' => 'bianchi.it', 'owner' => '', 'email' => '', 'status' => 'active', 'plan' => 'Certificazione Dominio esterno', 'created' => '2017-11-15'],
        ['id' => '569906', 'domain' => 'info@pec.rossi.it', 'owner' => '', 'email' => 'info@pec.rossi.it', 'status' => 'active', 'plan' => 'PEC Standard', 'created' => '2017-11-15'],
    ]);
});

it('signs in with the delegated user and the account key, then asks with the token', function () {
    abAccount([abRow()]);

    abModule()->listAccountDetails(abServer());

    Http::assertSent(fn (Request $request) => $request->url() === AB_API.'auth/token'
        && $request->hasHeader('Authorization-Key', 'la-chiave')
        && $request['grant_type'] === 'password' && $request['username'] === 'delegato' && $request['password'] === 'la-password');
    Http::assertSent(fn (Request $request) => $request->url() === AB_API.'api/services'
        && $request->hasHeader('Authorization', 'Bearer token-of-the-day')
        && $request->hasHeader('Authorization-Key', 'la-chiave'));
});

it('signs in again when Aruba ends the token early', function () {
    abAccount([abRow()], [401, 200]);

    expect(abModule()->listAccountDetails(abServer()))->toHaveCount(1);
    Http::assertSentCount(4);
});

it('links a billing service to a service of the account and remembers what Aruba says', function () {
    abAccount([abRow()]);
    $service = abService(abServer());

    expect(abModule()->linkAccount($service, '569906')['success'])->toBeTrue();
    expect($service->fresh()->module_data)->toMatchArray([
        'aruba_service_id' => '569906', 'aruba_account' => 'info@pec.rossi.it', 'aruba_article' => 'PEC Standard',
        'aruba_state' => 'Active', 'aruba_expires' => '2027-11-15', 'aruba_autorenew' => true, 'aruba_renewal_price' => '10,00 €',
    ]);
    expect($service->fresh()->username)->toBe('info@pec.rossi.it');
    expect(abModule()->linkedPanelAccount($service->fresh()))->toBe('569906');
});

it('does not link a service the account does not have among its PEC services', function () {
    abAccount([abRow(), abRow(['Id' => 4, 'Article' => 'Registrazione dominio .it'])]);
    $service = abService(abServer());

    expect(abModule()->linkAccount($service, '4')['success'])->toBeFalse();
    expect($service->fresh()->module_data)->toBeNull();
});

it('asks for the automatic renewal to be turned off when a service ends here', function () {
    abAccount([abRow()]);
    $service = abService(abServer(), ['aruba_service_id' => '569906']);

    $result = abModule()->terminate($service);

    expect($result['success'])->toBeTrue();
    expect($result['message'])->toContain('info@pec.rossi.it');
    expect($service->fresh()->module_data)->toMatchArray(['ended' => true, 'todo' => 'autorenew_off']);
});

it('has nothing left to ask once Aruba no longer renews the ended service', function (array $row) {
    $server = abServer();
    $service = abService($server, ['aruba_service_id' => '569906', 'ended' => true, 'todo' => 'autorenew_off'], ['status' => 'terminated']);
    abAccount([abRow($row)]);

    expect(abModule()->usageUpdate($server))->toBe(['updated' => 1, 'errors' => 0]);
    expect($service->fresh()->module_data['todo'])->toBeNull();
})->with([
    'automatic renewal off' => [['AutomaticRenewal' => false]],
    'cancelled at Aruba' => [['State' => 'Canceled']],
]);

it('keeps asking while Aruba still renews the ended service', function () {
    $server = abServer();
    $service = abService($server, ['aruba_service_id' => '569906', 'ended' => true, 'todo' => 'autorenew_off'], ['status' => 'terminated']);
    abAccount([abRow()]);

    abModule()->usageUpdate($server);

    expect($service->fresh()->module_data['todo'])->toBe('autorenew_off');
    expect(abModule()->screenData($service->fresh())['warnings'])->toHaveCount(1);
});

it('warns staff when an active service will not be renewed by Aruba', function () {
    $server = abServer();
    $service = abService($server, ['aruba_service_id' => '569906']);
    abAccount([abRow(['AutomaticRenewal' => false, 'ExpirationDate' => '2027-03-01T00:00:00'])]);

    abModule()->usageUpdate($server);

    expect(abModule()->screenData($service->fresh())['warnings'])->toHaveCount(2);
});

it('counts a linked service Aruba no longer lists as an error and leaves it as it was', function () {
    $server = abServer();
    $service = abService($server, ['aruba_service_id' => '999', 'aruba_state' => 'Active']);
    abAccount([abRow()]);

    expect(abModule()->usageUpdate($server))->toBe(['updated' => 0, 'errors' => 1]);
    expect($service->fresh()->module_data['aruba_state'])->toBe('Active');
});

it('changes nothing at Aruba: every request but the sign-in is a GET', function () {
    abAccount([abRow()]);
    $server = abServer();
    $service = abService($server, ['aruba_service_id' => '569906']);
    $module = abModule();

    $module->create($service);
    $module->suspend($service);
    $module->unsuspend($service);
    $module->renew($service);
    $module->changePassword($service, 'x');
    $module->changePackage($service, []);
    $module->terminate($service);
    $module->usageUpdate($server);

    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET' && $request->url() !== AB_API.'auth/token');
});

it('does not call Aruba without the three credentials, and says the connection fails', function (array $missing) {
    abAccount([abRow()]);
    $server = abServer($missing);

    expect(abModule()->testConnection($server))->toBeFalse();
    expect(abModule()->listAccountDetails($server))->toBe([]);
    Http::assertNothingSent();
})->with([
    'no key' => [['access_hash' => '']],
    'no username' => [['username' => '']],
    'no password' => [['password' => '']],
]);

it('never shows the credentials when Aruba refuses the sign-in', function () {
    Cache::flush();
    Http::preventStrayRequests();
    Http::fake([AB_API.'auth/token' => Http::response(['error' => 'invalid_grant'], 400)]);
    $service = abService(abServer(), ['aruba_service_id' => '569906']);

    $result = abModule()->refresh($service);

    expect($result['success'])->toBeFalse();
    expect($result['message'])->not->toContain('la-password')->not->toContain('la-chiave');
});
