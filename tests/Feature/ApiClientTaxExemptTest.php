<?php

use App\Models\ApiCredential;
use App\Models\Client;
use Database\Factories\ApiCredentialFactory;

function taxExemptApiHeaders(): array
{
    $credential = ApiCredential::factory()->create();

    return [
        'X-API-Key' => $credential->identifier,
        'X-API-Secret' => ApiCredentialFactory::PLAINTEXT_SECRET,
    ];
}

it('switches a client tax exemption on and off over updateclient', function () {
    $client = Client::factory()->create(['tax_exempt' => false]);

    $this->withHeaders(taxExemptApiHeaders())->postJson('/api/v1/updateclient', [
        'clientid' => $client->id,
        'taxexempt' => true,
    ])->assertSuccessful();
    expect($client->fresh()->tax_exempt)->toBeTrue();

    $this->withHeaders(taxExemptApiHeaders())->postJson('/api/v1/updateclient', [
        'clientid' => $client->id,
        'taxexempt' => false,
    ])->assertSuccessful();
    expect($client->fresh()->tax_exempt)->toBeFalse();
});

it('leaves the exemption alone when another field changes, and refuses a value that is not a yes or no', function () {
    $client = Client::factory()->create(['tax_exempt' => true]);

    $this->withHeaders(taxExemptApiHeaders())->postJson('/api/v1/updateclient', [
        'clientid' => $client->id,
        'city' => 'Wien',
    ])->assertSuccessful();

    $this->withHeaders(taxExemptApiHeaders())->postJson('/api/v1/updateclient', [
        'clientid' => $client->id,
        'taxexempt' => 'maybe',
    ])->assertStatus(422);

    expect($client->fresh()->tax_exempt)->toBeTrue();
});
