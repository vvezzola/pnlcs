<?php

use App\Mail\InvoiceCreatedMail;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\BillableItem;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Models\Setting;
use App\Services\InvoiceGenerationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;

/*
 * The month's renewals prepared as drafts, checked by staff and then issued:
 * a draft has no number, is not mailed and is not sent on, so one thrown away
 * leaves no hole in the series, and the numbers run in the order they are
 * issued, dated the day they are.
 */

function draftRunTerms(): void
{
    Setting::set('InvoiceGenerateAsDraft', '1');
    Setting::set('InvoiceNumberFormat', '{num:1}/ELH');
    Setting::set('InvoiceDueDays', '30');
    Setting::set('InvoiceDueEndOfMonth', '1');
    Setting::set('ConsolidatedBillingDay', '1');
    Setting::set('ConsolidatedBillingMonths', '1');
    Setting::set('ProformaEnabled', '0');
}

function draftRunClient(string $name, string $due = '2027-03-10'): Client
{
    $client = Client::factory()->create(['tax_exempt' => true, 'company_name' => $name]);
    Service::factory()->create([
        'client_id' => $client->id, 'status' => 'active', 'auto_renew' => true, 'billing_cycle' => 'Annually',
        'amount' => 100, 'next_due_date' => $due,
        'product_id' => Product::factory()->create(['group_id' => ProductGroup::factory()->create()->id, 'type' => 'hosting', 'pay_type' => 'recurring'])->id,
    ]);

    return $client;
}

function draftRunAdmin(): Admin
{
    return Admin::factory()->create(['role_id' => AdminRole::factory()->fullAdmin()->create()->id]);
}

beforeEach(function () {
    draftRunTerms();
    Mail::fake();
    Invoice::factory()->create(['invoice_num' => '7/ELH', 'type' => 'vat', 'status' => 'paid', 'date' => '2027-01-15']);
});

test('the run prepares drafts with no number, not mailed', function () {
    $client = draftRunClient('Rossi Srl');
    $this->travelTo(Carbon::parse('2027-02-01 06:00:00'));

    app(InvoiceGenerationService::class)->generateDueInvoices();

    $draft = Invoice::where('client_id', $client->id)->sole();
    expect($draft->status)->toBe('draft')
        ->and($draft->invoice_num)->toBeNull();
    Mail::assertNotQueued(InvoiceCreatedMail::class);
});

test('drafts issued days later are numbered in turn, dated that day, due on the usual terms from it', function () {
    $first = draftRunClient('Bianchi Srl');
    $second = draftRunClient('Verdi Srl');
    $this->travelTo(Carbon::parse('2027-02-01 06:00:00'));
    app(InvoiceGenerationService::class)->generateDueInvoices();
    $this->travelTo(Carbon::parse('2027-03-03 10:00:00'));

    $this->actingAs(draftRunAdmin(), 'admin')
        ->post(route('admin.bulk.invoice-action'), ['action' => 'publish', 'invoice_ids' => Invoice::where('status', 'draft')->pluck('id')->all()])
        ->assertSessionHas('success');

    $issued = Invoice::whereIn('client_id', [$first->id, $second->id])->orderBy('id')->get();
    expect($issued->pluck('invoice_num')->all())->toBe(['8/ELH', '9/ELH'])
        ->and($issued->pluck('status')->unique()->all())->toBe(['unpaid'])
        ->and($issued[0]->date->toDateString())->toBe('2027-03-03')
        ->and($issued[0]->due_date->toDateString())->toBe('2027-04-30');
    Mail::assertQueued(InvoiceCreatedMail::class, 2);
});

test('a draft thrown away leaves no hole, and its one-off charges wait for the next invoice', function () {
    $wrong = draftRunClient('Disdetto Srl');
    $right = draftRunClient('Rossi Srl');
    $charge = BillableItem::create(['client_id' => $wrong->id, 'description' => 'Migrazione', 'amount' => 50]);
    $this->travelTo(Carbon::parse('2027-02-01 06:00:00'));
    app(InvoiceGenerationService::class)->generateDueInvoices();
    $admin = draftRunAdmin();
    $thrownAway = Invoice::where('client_id', $wrong->id)->sole();

    $this->actingAs($admin, 'admin')->delete(route('admin.invoices.destroy-draft', $thrownAway))->assertSessionHas('success');
    $this->actingAs($admin, 'admin')->post(route('admin.invoices.publish', Invoice::where('client_id', $right->id)->sole()));

    expect(Invoice::find($thrownAway->id))->toBeNull()
        ->and($charge->refresh()->invoice_id)->toBeNull()
        ->and(Invoice::where('client_id', $right->id)->value('invoice_num'))->toBe('8/ELH');
});

test('a draft is only issued or thrown away: not paid, cancelled or mailed, and an issued invoice is not deleted', function () {
    $client = draftRunClient('Rossi Srl');
    $this->travelTo(Carbon::parse('2027-02-01 06:00:00'));
    app(InvoiceGenerationService::class)->generateDueInvoices();
    $draft = Invoice::where('client_id', $client->id)->sole();
    $admin = draftRunAdmin();

    foreach (['paid', 'cancel', 'send'] as $action) {
        $this->actingAs($admin, 'admin')->post(route('admin.bulk.invoice-action'), ['action' => $action, 'invoice_ids' => [$draft->id]]);
    }
    expect($draft->refresh()->status)->toBe('draft');
    Mail::assertNotQueued(InvoiceCreatedMail::class);

    $issued = Invoice::where('invoice_num', '7/ELH')->sole();
    $this->actingAs($admin, 'admin')->delete(route('admin.invoices.destroy-draft', $issued))->assertSessionHas('error');
    expect(Invoice::find($issued->id))->not->toBeNull();
});

test('staff see the drafts to check, with the buttons to issue or delete them', function () {
    $client = draftRunClient('Rossi Srl');
    $this->travelTo(Carbon::parse('2027-02-01 06:00:00'));
    app(InvoiceGenerationService::class)->generateDueInvoices();
    $draft = Invoice::where('client_id', $client->id)->sole();
    $admin = draftRunAdmin();

    $this->actingAs($admin, 'admin')->get(route('admin.invoices.index', ['status' => 'draft']))
        ->assertOk()->assertSee(__('admin.invoices.draft_number'))->assertSee('data-action="publish"', false);
    $this->actingAs($admin, 'admin')->get(route('admin.invoices.show', $draft))
        ->assertOk()->assertSee(route('admin.invoices.publish', $draft))->assertSee(route('admin.invoices.destroy-draft', $draft))
        ->assertDontSee(route('admin.invoices.cancel', $draft));
});
