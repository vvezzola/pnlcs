<?php

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\ApiCredential;
use App\Models\Client;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Illuminate\Support\Str;

/*
 * Client custom fields through the API: written by updateclient (as WHMCS
 * sends them, or as JSON, by id or by name) and read back by
 * getclientsdetails. A field that does not exist, or a value its type
 * refuses, stops the whole update.
 */

function cfApiHeaders(): array
{
    $role = AdminRole::where('is_full_admin', true)->first() ?? AdminRole::factory()->fullAdmin()->create();
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    $secret = Str::random(64);
    $cred = ApiCredential::create(['admin_id' => $admin->id, 'identifier' => Str::random(32), 'secret' => ApiCredential::hashSecret($secret), 'description' => 't', 'active' => true]);

    return ['X-API-Key' => $cred->identifier, 'X-API-Secret' => $secret, 'Accept' => 'application/json'];
}

function cfField(string $name, string $type = 'text', string $options = ''): CustomField
{
    return CustomField::create(['type' => 'client', 'rel_id' => 0, 'field_name' => $name, 'field_type' => $type, 'field_options' => $options]);
}

test('fields are written as WHMCS sends them and by name, and read back', function () {
    $sdi = cfField('Codice destinatario SDI / PEC');
    $pec = cfField('PEC');
    $client = Client::factory()->create();
    CustomFieldValue::create(['field_id' => $sdi->id, 'rel_id' => $client->id, 'value' => 'old@pec.it']);
    $h = cfApiHeaders();

    $this->withHeaders($h)->post('/api/v1/updateclient', [
        'clientid' => $client->id, 'customfields' => base64_encode(serialize([$sdi->id => 'M5UXCR1'])),
    ])->assertOk();
    $this->withHeaders($h)->post('/api/v1/updateclient', [
        'clientid' => $client->id, 'customfields' => json_encode(['PEC' => 'rossi@pec.it']),
    ])->assertOk();

    expect($sdi->valueFor($client->id))->toBe('M5UXCR1')
        ->and($pec->valueFor($client->id))->toBe('rossi@pec.it');

    $this->withHeaders($h)->get('/api/v1/getclientsdetails?clientid='.$client->id)->assertOk()
        ->assertJsonFragment(['id' => $pec->id, 'name' => 'PEC', 'value' => 'rossi@pec.it']);

    $this->withHeaders($h)->post('/api/v1/updateclient', ['clientid' => $client->id, 'customfields' => ['PEC' => '']])->assertOk();
    expect($pec->valueFor($client->id))->toBeNull();
});

test('an unknown field or a refused value changes nothing at all', function () {
    $choice = cfField('Tipo', 'select', "Azienda\nPrivato");
    $client = Client::factory()->create(['company_name' => 'Rossi Srl']);
    $h = cfApiHeaders();

    $this->withHeaders($h)->post('/api/v1/updateclient', [
        'clientid' => $client->id, 'companyname' => 'Altro', 'customfields' => json_encode(['Inesistente' => 'x']),
    ])->assertStatus(422);
    $this->withHeaders($h)->post('/api/v1/updateclient', [
        'clientid' => $client->id, 'companyname' => 'Altro', 'customfields' => json_encode([$choice->id => 'Ente']),
    ])->assertStatus(422);

    expect($client->fresh()->company_name)->toBe('Rossi Srl')
        ->and($choice->valueFor($client->id))->toBeNull();
});

test('a serialised object is never brought to life', function () {
    cfField('PEC');
    $client = Client::factory()->create();

    $this->withHeaders(cfApiHeaders())->post('/api/v1/updateclient', [
        'clientid' => $client->id, 'customfields' => base64_encode(serialize(new ArrayObject(['PEC' => 'x@pec.it']))),
    ])->assertStatus(422);
});
