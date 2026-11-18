<?php

use App\Models\Client;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;

/*
 * Esalogica only: the billing run of a day, played and rolled back, so the
 * invoices it would make can be compared with the real invoicing.
 */

test('it lists what the billing day would invoice and leaves nothing behind', function () {
    Currency::query()->update(['is_default' => false]);
    Currency::updateOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'prefix' => '€', 'suffix' => '', 'rate' => 1, 'is_default' => true]);
    Setting::set('ConsolidatedBillingDay', '1');
    Setting::set('ConsolidatedBillingMonths', '1');
    $client = Client::factory()->create(['company_name' => 'Rossi Srl']);
    $product = Product::factory()->create(['name' => 'EsaStart5']);
    Service::factory()->create(['client_id' => $client->id, 'product_id' => $product->id, 'domain' => 'rossi.it', 'status' => 'active',
        'billing_cycle' => 'annually', 'amount' => 72, 'next_due_date' => '2027-01-20', 'auto_renew' => true]);
    Service::factory()->create(['client_id' => $client->id, 'product_id' => $product->id, 'domain' => 'later.it', 'status' => 'active',
        'billing_cycle' => 'annually', 'amount' => 72, 'next_due_date' => '2027-05-20', 'auto_renew' => true]);
    $out = tempnam(sys_get_temp_dir(), 'sim');

    $this->artisan("esalogica:simula-fatture --date=2026-12-01 --out={$out}")
        ->expectsOutputToContain('fino al 2027-01-31')
        ->expectsOutputToContain('Rossi Srl')
        ->expectsOutputToContain('1 fatture')
        ->assertSuccessful();

    expect(Invoice::count())->toBe(0)
        ->and(Service::where('domain', 'rossi.it')->first()->next_due_date->toDateString())->toBe('2027-01-20')
        ->and(file_get_contents($out))->toContain('rossi.it')->not->toContain('later.it');
});
