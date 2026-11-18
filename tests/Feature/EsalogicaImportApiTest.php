<?php

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\ApiCredential;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use Database\Factories\ApiCredentialFactory;

/*
 * Esalogica only: the subscriptions import and the billing simulation over
 * the API, for a full administrator's credential.
 */

function esaApiHeaders(?Admin $admin = null): array
{
    $credential = ApiCredential::factory()->create($admin ? ['admin_id' => $admin->id] : []);

    return ['X-API-Key' => $credential->identifier, 'X-API-Secret' => ApiCredentialFactory::PLAINTEXT_SECRET];
}

function esaImportCsv(Client $client, Product $product): string
{
    return "tipo,azione,service_id,client_id,product_id,server_id,oggetto,ciclo,anni,importo,prossima_scadenza,scadenza_registrar,registrar,presso,nota\n"
        ."servizio,crea,,{$client->id},{$product->id},,info@pec.rossi.it,annually,,12.00,2026-12-15,,,,casella PEC\n";
}

test('the import runs as a dry run, then writes with write=1, and leaves no file behind', function () {
    $client = Client::factory()->create();
    $product = Product::factory()->create();
    $before = glob(sys_get_temp_dir().'/esa-import-*') ?: [];

    $dry = $this->withHeaders(esaApiHeaders())->postJson('/api/v1/esalogicaimportabbonamenti', ['csv' => esaImportCsv($client, $product)]);
    $dry->assertOk()->assertJsonPath('written', false);
    expect($dry->json('output'))->toContain('Prova')->and(Service::count())->toBe(0);

    $this->withHeaders(esaApiHeaders())->postJson('/api/v1/esalogicaimportabbonamenti', ['csv' => esaImportCsv($client, $product), 'write' => 1])
        ->assertOk()->assertJsonPath('written', true);
    expect(Service::where('domain', 'info@pec.rossi.it')->exists())->toBeTrue()
        ->and(glob(sys_get_temp_dir().'/esa-import-*') ?: [])->toBe($before);
});

test('a refused sheet answers with the reason', function () {
    $client = Client::factory()->create();
    $csv = "tipo,azione,service_id,client_id,product_id,server_id,oggetto,ciclo,anni,importo,prossima_scadenza,scadenza_registrar,registrar,presso,nota\n"
        ."servizio,crea,,{$client->id},99999,,x.it,annually,,10.00,2027-01-01,,,,\n";

    $this->withHeaders(esaApiHeaders())->postJson('/api/v1/esalogicaimportabbonamenti', ['csv' => $csv, 'write' => 1])
        ->assertStatus(422);
    expect(Service::count())->toBe(0);
});

test('only a full administrator may call them', function () {
    $staff = Admin::factory()->create(['role_id' => AdminRole::factory()->create(['is_full_admin' => false, 'permissions' => ['manage_services', 'manage_settings']])->id]);

    $this->withHeaders(esaApiHeaders($staff))->postJson('/api/v1/esalogicaimportabbonamenti', ['csv' => 'x'])->assertForbidden();
    $this->withHeaders(esaApiHeaders($staff))->postJson('/api/v1/esalogicasimulafatture', [])->assertForbidden();
});

test('the simulation answers with the run\'s invoices and makes none', function () {
    $response = $this->withHeaders(esaApiHeaders())->postJson('/api/v1/esalogicasimulafatture', ['date' => '2026-12-01']);

    $response->assertOk();
    expect($response->json('output'))->toContain('Simulazione')
        ->and($response->json('csv'))->toContain('client_id')
        ->and(Invoice::count())->toBe(0);
});
