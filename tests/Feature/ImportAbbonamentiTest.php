<?php

use App\Models\Client;
use App\Models\Domain;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;

/*
 * Esalogica only: the customers' services and domains come in from the
 * reviewed sheet. A service the Plesk sync page made is updated, not made
 * twice; nothing is made twice on a second run.
 */

function abbonamentiCsv(array $rows): string
{
    $header = ['tipo', 'azione', 'service_id', 'client_id', 'product_id', 'server_id', 'oggetto', 'ciclo', 'anni', 'importo',
        'prossima_scadenza', 'scadenza_registrar', 'registrar', 'presso', 'nota'];
    $path = tempnam(sys_get_temp_dir(), 'abb');
    $f = fopen($path, 'w');
    fputcsv($f, $header, escape: '\\');
    foreach ($rows as $row) {
        fputcsv($f, array_map(fn ($k) => $row[$k] ?? '', $header), escape: '\\');
    }
    fclose($f);

    return $path;
}

test('services and domains are created, a synced service updated, and nothing twice', function () {
    $client = Client::factory()->create();
    $hosting = Product::factory()->create();
    $pec = Product::factory()->create();
    $server = Server::factory()->create(['type' => 'plesk']);
    $synced = Service::factory()->create([
        'client_id' => $client->id, 'product_id' => $hosting->id, 'server_id' => $server->id, 'domain' => 'rossi.it',
        'billing_cycle' => 'annually', 'amount' => 60, 'next_due_date' => '2027-09-21', 'module_data' => ['plesk_domain_id' => '100'],
    ]);
    $file = abbonamentiCsv([
        ['tipo' => 'servizio', 'azione' => 'aggiorna', 'service_id' => $synced->id, 'client_id' => $client->id, 'product_id' => $hosting->id,
            'server_id' => $server->id, 'oggetto' => 'rossi.it', 'ciclo' => 'annually', 'importo' => '72.00', 'prossima_scadenza' => '2027-03-01', 'nota' => 'usa 2 GB'],
        ['tipo' => 'servizio', 'azione' => 'crea', 'client_id' => $client->id, 'product_id' => $pec->id,
            'oggetto' => 'info@pec.rossi.it', 'ciclo' => 'annually', 'importo' => '12.00', 'prossima_scadenza' => '2026-12-15', 'nota' => 'casella PEC'],
        ['tipo' => 'dominio', 'azione' => 'crea', 'client_id' => $client->id, 'oggetto' => 'rossi.it', 'ciclo' => 'annually', 'anni' => '1',
            'importo' => '0.00', 'prossima_scadenza' => '2027-01-08', 'scadenza_registrar' => '2027-01-08', 'registrar' => 'manual', 'presso' => 'Internet.bs'],
    ]);

    $this->artisan("esalogica:import-abbonamenti {$file}")->expectsOutputToContain('Prova')->assertSuccessful();
    expect(Service::count())->toBe(1)->and(Domain::count())->toBe(0);

    $this->artisan("esalogica:import-abbonamenti {$file} --write")->assertSuccessful();

    $synced->refresh();
    expect((float) $synced->amount)->toBe(72.0)
        ->and($synced->next_due_date->toDateString())->toBe('2027-03-01')
        ->and($synced->module_data)->toMatchArray(['plesk_domain_id' => '100'])
        ->and(Service::where('domain', 'info@pec.rossi.it')->first()->next_due_date->toDateString())->toBe('2026-12-15')
        ->and(Domain::first())->registrar->toBe('manual')
        ->and(Domain::first()->notes)->toContain('Internet.bs');

    $this->artisan("esalogica:import-abbonamenti {$file} --write")->expectsOutputToContain('già presenti')->assertSuccessful();
    expect(Service::count())->toBe(2)->and(Domain::count())->toBe(1);
});

test('a wrong id stops the import before anything is written', function () {
    $client = Client::factory()->create();
    $file = abbonamentiCsv([
        ['tipo' => 'servizio', 'azione' => 'crea', 'client_id' => $client->id, 'product_id' => 99999,
            'oggetto' => 'x.it', 'ciclo' => 'annually', 'importo' => '10.00', 'prossima_scadenza' => '2027-01-01'],
    ]);

    $this->artisan("esalogica:import-abbonamenti {$file} --write")->expectsOutputToContain('prodotto 99999 inesistente')->assertFailed();
    expect(Service::count())->toBe(0);
});
