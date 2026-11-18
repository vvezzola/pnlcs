<?php

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Pricing;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Models\Setting;
use App\Services\InvoiceGenerationService;
use App\Services\OrderService;
use App\Services\UpgradeService;
use Carbon\Carbon;

/*
 * A product group billed on terms of its own: licences sold to partners on a
 * proforma payable in 15 days, while the shop's other services are invoiced
 * together at 30 days end of month.
 */

function pgbGeneralTerms(): void
{
    Setting::set('InvoiceDueDays', '30');
    Setting::set('InvoiceDueEndOfMonth', '1');
    Setting::set('ConsolidatedBillingDay', '1');
    Setting::set('ConsolidatedBillingMonths', '1');
    Setting::set('InvoiceDaysBeforeDue', '0');
    Setting::set('ProformaEnabled', '0');
}

function pgbProduct(?ProductGroup $group = null): Product
{
    return Product::factory()->create([
        'group_id' => ($group ?? ProductGroup::factory()->create())->id,
        'type' => 'hosting', 'pay_type' => 'recurring', 'auto_setup' => 'manual', 'tax' => false,
    ]);
}

function pgbService(Client $client, Product $product, string $due = '2027-03-10'): Service
{
    return Service::factory()->create([
        'client_id' => $client->id, 'product_id' => $product->id,
        'status' => 'active', 'auto_renew' => true, 'billing_cycle' => 'Annually',
        'amount' => 100, 'next_due_date' => $due,
    ]);
}

test('on the billing day a group with its own terms gets a proforma of its own, the rest one invoice as before', function () {
    pgbGeneralTerms();
    $licences = ProductGroup::factory()->create(['invoice_type' => 'proforma', 'invoice_due_days' => 15]);
    $client = Client::factory()->create(['tax_exempt' => true]);
    $licence = pgbService($client, pgbProduct($licences));
    $hosting = pgbService($client, pgbProduct());
    $this->travelTo(Carbon::parse('2027-02-01 06:00:00'));

    app(InvoiceGenerationService::class)->generateDueInvoices();

    $invoices = Invoice::with('items')->where('client_id', $client->id)->get();
    $proforma = $invoices->firstWhere('type', 'proforma');
    $invoice = $invoices->firstWhere('type', 'vat');

    expect($invoices)->toHaveCount(2)
        ->and($proforma->due_date->toDateString())->toBe('2027-02-16')
        ->and($proforma->items->pluck('rel_id')->all())->toBe([$licence->id])
        ->and($invoice->due_date->toDateString())->toBe('2027-03-31')
        ->and($invoice->items->pluck('rel_id')->all())->toBe([$hosting->id]);
});

test('a group without terms of its own is invoiced with everything else', function () {
    pgbGeneralTerms();
    $client = Client::factory()->create(['tax_exempt' => true]);
    pgbService($client, pgbProduct());
    pgbService($client, pgbProduct());
    $this->travelTo(Carbon::parse('2027-02-01 06:00:00'));

    app(InvoiceGenerationService::class)->generateDueInvoices();

    expect(Invoice::where('client_id', $client->id)->count())->toBe(1)
        ->and(Invoice::where('client_id', $client->id)->value('type'))->toBe('vat');
});

test('an order of the group alone is a proforma on its terms; one mixed with other products is not', function () {
    pgbGeneralTerms();
    $licences = ProductGroup::factory()->create(['invoice_type' => 'proforma', 'invoice_due_days' => 15]);
    $licence = pgbProduct($licences);
    $hosting = pgbProduct();
    $client = Client::factory()->create();
    $this->travelTo(Carbon::parse('2027-02-03 10:00:00'));
    $line = fn (Product $product, string $domain) => [
        'type' => 'product', 'product_id' => $product->id, 'billing_cycle' => 'annually', 'amount' => 100.0, 'domain' => $domain,
    ];

    $alone = app(OrderService::class)->processOrder($client, [$line($licence, 'a.test')], 'banktransfer');
    $mixed = app(OrderService::class)->processOrder($client, [$line($licence, 'b.test'), $line($hosting, 'c.test')], 'banktransfer');

    $aloneInvoice = Invoice::findOrFail($alone->invoice_id);
    expect($aloneInvoice->type)->toBe('proforma')
        ->and($aloneInvoice->due_date->toDateString())->toBe('2027-02-18')
        ->and(Invoice::findOrFail($mixed->invoice_id)->type)->toBe('vat');
});

test('the group form sets the terms and clears them back to the general settings', function () {
    $group = ProductGroup::factory()->create();
    $admin = Admin::factory()->create(['role_id' => AdminRole::factory()->fullAdmin()->create()->id]);

    $this->actingAs($admin, 'admin')
        ->put(route('admin.products.groups.update', $group), ['name' => $group->name, 'invoice_type' => 'proforma', 'invoice_due_days' => '15'])
        ->assertSessionHasNoErrors();
    expect($group->refresh()->invoice_type)->toBe('proforma')
        ->and($group->invoice_due_days)->toBe(15);

    $this->actingAs($admin, 'admin')
        ->put(route('admin.products.groups.update', $group), ['name' => $group->name, 'invoice_type' => 'receipt', 'invoice_due_days' => '15'])
        ->assertSessionHasErrors('invoice_type');

    $this->actingAs($admin, 'admin')
        ->put(route('admin.products.groups.update', $group), ['name' => $group->name, 'invoice_type' => '', 'invoice_due_days' => ''])
        ->assertSessionHasNoErrors();
    expect($group->refresh()->invoice_type)->toBeNull()
        ->and($group->invoice_due_days)->toBeNull();
});

test('an upgrade to a product of the group is charged on the group\'s terms', function () {
    pgbGeneralTerms();
    $this->travelTo(Carbon::parse('2027-02-03 10:00:00'));
    $licences = ProductGroup::factory()->create(['invoice_type' => 'proforma', 'invoice_due_days' => 15]);
    $current = pgbProduct($licences);
    $bigger = pgbProduct($licences);
    Pricing::factory()->create(['type' => 'product', 'rel_id' => $current->id, 'monthly' => 10.00]);
    Pricing::factory()->create(['type' => 'product', 'rel_id' => $bigger->id, 'monthly' => 40.00]);
    $client = Client::factory()->create();
    $service = Service::factory()->create([
        'client_id' => $client->id, 'product_id' => $current->id, 'billing_cycle' => 'Monthly',
        'amount' => 10.00, 'next_due_date' => now()->addDays(15), 'status' => 'active',
    ]);

    $result = app(UpgradeService::class)->requestProductChange($service, $bigger);

    expect($result['invoice']->type)->toBe('proforma')
        ->and($result['invoice']->due_date->toDateString())->toBe('2027-02-18');
});

test('a group with days before due is invoiced that many days ahead of each renewal, not on the billing day', function () {
    pgbGeneralTerms();
    $licences = ProductGroup::factory()->create(['invoice_type' => 'proforma', 'invoice_due_days' => 15, 'invoice_days_before_due' => 20]);
    $client = Client::factory()->create(['tax_exempt' => true]);
    $licence = pgbService($client, pgbProduct($licences), '2027-03-05');
    $licence->update(['billing_cycle' => 'Monthly']);
    $hosting = pgbService($client, pgbProduct(), '2027-03-05');
    $generate = function (string $day) {
        $this->travelTo(Carbon::parse($day.' 06:00:00'));
        app(InvoiceGenerationService::class)->generateDueInvoices();
    };

    $generate('2027-02-01');
    expect(Invoice::where('client_id', $client->id)->get()->pluck('items')->flatten()->pluck('rel_id')->all())->toBe([$hosting->id]);

    $generate('2027-02-12');
    expect(Invoice::where('client_id', $client->id)->where('type', 'proforma')->exists())->toBeFalse();

    $generate('2027-02-13');
    $proforma = Invoice::with('items')->where('client_id', $client->id)->where('type', 'proforma')->sole();
    expect($proforma->due_date->toDateString())->toBe('2027-02-28')
        ->and($proforma->items->pluck('rel_id')->all())->toBe([$licence->id]);
});

test('the renewal notice leaves out a group invoiced on its own days', function () {
    pgbGeneralTerms();
    $licences = ProductGroup::factory()->create(['invoice_type' => 'proforma', 'invoice_due_days' => 15, 'invoice_days_before_due' => 20]);
    $client = Client::factory()->create();
    pgbService($client, pgbProduct($licences), '2027-03-05');
    $hosting = pgbService($client, pgbProduct(), '2027-03-05');
    $this->travelTo(Carbon::parse('2027-01-15 09:30:00'));

    $upcoming = app(InvoiceGenerationService::class)->upcomingRenewals(Carbon::parse('2027-02-01'));

    expect($upcoming['services']->pluck('id')->all())->toBe([$hosting->id]);
});
