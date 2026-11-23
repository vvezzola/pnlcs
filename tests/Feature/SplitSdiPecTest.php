<?php

use App\Models\Client;
use App\Models\CustomField;
use App\Models\CustomFieldValue;

/*
 * Esalogica only: PEC addresses leave the SDI recipient field for the PEC
 * field; a client whose PEC field already says something else keeps both.
 */

function sdiPecField(string $name): CustomField
{
    return CustomField::create(['type' => 'client', 'rel_id' => 0, 'field_name' => $name, 'field_type' => 'text']);
}

test('an address moves to the PEC field, a code stays, a conflicting PEC is left alone', function () {
    $sdi = sdiPecField('Codice destinatario SDI / PEC');
    $pec = sdiPecField('PEC');
    [$moving, $coded, $conflict] = Client::factory()->count(3)->create();
    CustomFieldValue::create(['field_id' => $sdi->id, 'rel_id' => $moving->id, 'value' => 'Rossi@PEC.it']);
    CustomFieldValue::create(['field_id' => $sdi->id, 'rel_id' => $coded->id, 'value' => 'M5UXCR1']);
    CustomFieldValue::create(['field_id' => $sdi->id, 'rel_id' => $conflict->id, 'value' => 'old@pec.it']);
    CustomFieldValue::create(['field_id' => $pec->id, 'rel_id' => $conflict->id, 'value' => 'new@pec.it']);

    $this->artisan('esalogica:split-sdi-pec')->expectsOutputToContain('Prova')->assertSuccessful();
    expect($sdi->valueFor($moving->id))->toBe('Rossi@PEC.it');

    $this->artisan('esalogica:split-sdi-pec --write')->expectsOutputToContain('diverse, lasciate')->assertSuccessful();

    expect($pec->valueFor($moving->id))->toBe('rossi@pec.it')
        ->and($sdi->valueFor($moving->id))->toBe('')
        ->and($sdi->valueFor($coded->id))->toBe('M5UXCR1')
        ->and($sdi->valueFor($conflict->id))->toBe('old@pec.it')
        ->and($pec->valueFor($conflict->id))->toBe('new@pec.it');

    $this->artisan('esalogica:split-sdi-pec --write')->expectsOutputToContain('0  spostate')->assertSuccessful();
});
