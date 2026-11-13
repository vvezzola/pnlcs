<?php

use App\Events\ClientCreated;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Client;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Modules\Addons\SimplyFatt\SimplyFattModule;
use Modules\Addons\SimplyFatt\SimplyFattServiceProvider;

/*
 * Esalogica only: the customers exported from SimplyFatt become PNLCS
 * customers, keyed by their SimplyFatt code so the import can run again.
 */

const SF_HEADER = "Codice\tCodiceSDI/PEC\tDenominazione\tIndirizzo\tC.A.P.\tCittà\tProv.\tRegione\tNazione\tPartita IVA \tCodice Fiscale\tRif. Persona\te-mail\tPagamento\tTelefono1\tCellulare\tHomePage\tSaldo";

/** @param list<list<string>> $rows */
function sfExport(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'sf');
    $lines = array_merge([SF_HEADER], array_map(fn ($row) => implode("\t", $row), $rows));
    file_put_contents($path, mb_convert_encoding(implode("\r\n", $lines)."\r\n", 'Windows-1252', 'UTF-8'));

    return $path;
}

function sfRows(): array
{
    return [
        // code, sdi, name, address, cap, city, prov, region, country, vat, cf, referent, email, payment, phone, mobile, site, balance
        ['0002', 'M5UXCR1', 'Feinrohren SpA', 'VIA REVERBERI 8', '25050', 'PASSIRANO', 'BS', 'Lombardia', 'Italia', '00551380983', '00551380983', 'Massimo Castelli', 'acquisti@feinrohren.it', 'Rimessa diretta', '', '0306549011', 'www.feinrohren.it', '120,00'],
        ['0010', '', 'Rossi Officina Srl', 'Via Roma 1', '25100', 'Brescia', 'BS', 'Lombardia', 'Italia', '01111111111', '', 'Serena Moretti', 'serena@agenzia.it', 'Bonifico 30gg FM', '', '', '', '0,00'],
        ['0011', 'bianchi@pec.it', 'Bianchi Mario', 'Via Po 2', '200081', 'Milano', 'MI', 'Lombardia', 'Italia', '', 'BNCMRA70A01F205X', 'Serena Moretti', 'serena@agenzia.it', 'Bonifico 30gg FM', '', '', '', '0,00'],
        ['0012', '', 'Verdi Snc', 'Via Po 3', '20100', 'Milano', 'MI', 'Lombardia', 'Italia', '02222222222', '', '', 'serena@agenzia.it', 'Bonifico 30gg FM', '', '', '', '0,00'],
        ['0020', '', 'Feinrohren S.p.A.', 'Via Reverberi 8', '25050', 'Passirano', 'BS', 'Lombardia', 'Italia', '00551380983', '00551380983', '', 'vecchio@feinrohren.it', '', '', '', '', '0,00'],
        ['0030', '', 'Città Nuova di Luca Genovese', 'Via Dante 4', '20121', 'Milano', 'MI', 'Lombardia', 'Italia', '07017250965', 'GNVLCU78R18F205U', '', 'luca@cittanuova.it', '', '', '', '', '0,00'],
    ];
}

it('changes nothing without --write', function () {
    $this->artisan('esalogica:import-simplyfatt-clients', ['file' => sfExport(sfRows())])
        ->expectsOutputToContain('Righe lette: 6   tenute: 5   doppioni scartati: 1')
        ->assertSuccessful();

    expect(Client::count())->toBe(0);
});

it('creates the customers by the agreed rules, without the new-account event', function () {
    Event::fake([ClientCreated::class]);

    $this->artisan('esalogica:import-simplyfatt-clients', ['file' => sfExport(sfRows()), '--write' => true])->assertSuccessful();

    expect(Client::count())->toBe(5);
    Event::assertNotDispatched(ClientCreated::class);

    $byCode = fn (string $code) => Client::find(CustomFieldValue::where('field_id', CustomField::where('field_name', 'Codice SimplyFatt')->value('id'))->where('value', $code)->value('rel_id'));

    // Two rows with one VAT number: the highest code is kept.
    expect($byCode('0002'))->toBeNull();
    $fein = $byCode('0020');
    expect($fein->company_name)->toBe('Feinrohren S.p.A.');

    // A contact written on several customers is not their own person; a shared email is billing only.
    $rossi = $byCode('0010');
    expect($rossi->first_name)->toBe('Rossi Officina Srl')
        ->and($rossi->email)->toBe('cliente-0010@clienti.invalid')
        ->and($rossi->billing_email)->toBe('serena@agenzia.it');

    // A private person: login with the PEC, name from the fiscal code's order, the doubled zero in the CAP fixed.
    $bianchi = $byCode('0011');
    expect($bianchi->client_type)->toBe('individual')
        ->and([$bianchi->first_name, $bianchi->last_name])->toBe(['Mario', 'Bianchi'])
        ->and($bianchi->email)->toBe('bianchi@pec.it')
        ->and($bianchi->postcode)->toBe('20081');

    // "di Luca Genovese": the fiscal code says Genovese is the surname; the accent survives Windows-1252.
    $luca = $byCode('0030');
    expect([$luca->first_name, $luca->last_name])->toBe(['Luca', 'Genovese'])
        ->and($luca->company_name)->toBe('Città Nuova di Luca Genovese');
});

it('updates the same customers when run again', function () {
    $file = sfExport(sfRows());
    $this->artisan('esalogica:import-simplyfatt-clients', ['file' => $file, '--write' => true])->assertSuccessful();

    $rows = sfRows();
    $rows[5][3] = 'Via Dante 6';
    $this->artisan('esalogica:import-simplyfatt-clients', ['file' => sfExport($rows), '--write' => true])
        ->expectsOutputToContain('Clienti: 0 nuovi, 1 aggiornati, 4 invariati')
        ->assertSuccessful();

    expect(Client::count())->toBe(5)
        ->and(Client::where('address1', 'Via Dante 6')->count())->toBe(1);
});

/** The addon's routes, as when the addon is active, and an admin allowed to add customers. */
function sfPageAdmin(): Admin
{
    (new SimplyFattServiceProvider(app()))->boot();
    app('router')->getRoutes()->refreshNameLookups();

    return Admin::factory()->create(['role_id' => AdminRole::factory()->fullAdmin()->create()->id]);
}

function sfUpload(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('Clienti.txt', file_get_contents(sfExport(sfRows())));
}

/** The addon page as the admin sees it in this session. */
function sfPage(): string
{
    $request = Request::create('/admin/config/addons/modules/simplyfatt');
    $request->setLaravelSession(app('session.store'));

    return (new SimplyFattModule)->output($request);
}

it('previews an uploaded export on the addon page, then imports it and deletes the upload', function () {
    Storage::fake('local');
    $admin = sfPageAdmin();

    $this->actingAs($admin, 'admin')->post(route('admin.simplyfatt.clients.preview'), ['clients' => sfUpload()])->assertRedirect();

    expect(Client::count())->toBe(0)
        ->and(sfPage())->toContain('ANTEPRIMA')->toContain('tenute: 5')->toContain('Importa questi clienti');

    $this->actingAs($admin, 'admin')->post(route('admin.simplyfatt.clients.import'))->assertRedirect()->assertSessionHas('success');

    expect(Client::count())->toBe(5)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(sfPage())->toContain('IMPORTAZIONE ESEGUITA')->not->toContain('Importa questi clienti');
});

it('drops a previewed upload on cancel, and refuses a file that is not the customer export', function () {
    Storage::fake('local');
    $admin = sfPageAdmin();

    $this->actingAs($admin, 'admin')->post(route('admin.simplyfatt.clients.preview'), ['clients' => sfUpload()]);
    $this->actingAs($admin, 'admin')->post(route('admin.simplyfatt.clients.cancel'))->assertRedirect();

    expect(Storage::disk('local')->allFiles())->toBe([]);
    $this->actingAs($admin, 'admin')->post(route('admin.simplyfatt.clients.import'))->assertSessionHas('error');

    $wrong = UploadedFile::fake()->createWithContent('fatture.txt', "Numero\tData\n1\t2026-01-01\n");
    $this->actingAs($admin, 'admin')->post(route('admin.simplyfatt.clients.preview'), ['clients' => $wrong])->assertSessionHas('error');

    expect(Client::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});
