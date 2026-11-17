<?php

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Models\Setting;
use App\Services\InvoiceGenerationService;
use App\Services\InvoiceService;
use Carbon\Carbon;

/*
 * Payment terms per customer and per invoice, as in an ordinary accounting
 * program: most business customers pay 30 days end of month (the general
 * setting), some at 60 or 90 days, and an extension agreed on one invoice
 * moves its due date, which reminders and suspension read.
 */

function cptAdmin(array $permissions = []): Admin
{
    $role = $permissions === []
        ? AdminRole::factory()->fullAdmin()->create()
        : AdminRole::factory()->create(['permissions' => $permissions]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function cptGeneralTerms(): void
{
    Setting::set('InvoiceDueDays', '30');
    Setting::set('InvoiceDueEndOfMonth', '1');
}

test('a client\'s own terms set the due date, each empty one falling back to the general setting', function (?int $days, ?bool $endOfMonth, string $due) {
    cptGeneralTerms();
    $client = Client::factory()->create();
    $client->forceFill(['payment_terms_days' => $days, 'payment_terms_end_of_month' => $endOfMonth])->save();

    expect(InvoiceService::defaultDueDate(Carbon::parse('2027-02-01'), $client->refresh()))->toBe($due);
})->with([
    'general terms' => [null, null, '2027-03-31'],
    '60 days end of month' => [60, null, '2027-04-30'],
    '60 days, not end of month' => [60, false, '2027-04-02'],
    'immediate' => [0, false, '2027-02-01'],
]);

test('the billing day raises a client\'s renewals with the client\'s terms', function () {
    cptGeneralTerms();
    Setting::set('ConsolidatedBillingDay', '1');
    Setting::set('ConsolidatedBillingMonths', '1');
    Setting::set('InvoiceDaysBeforeDue', '0');
    $client = Client::factory()->create(['tax_exempt' => true]);
    $client->forceFill(['payment_terms_days' => 90])->save();
    Service::factory()->create([
        'client_id' => $client->id,
        'product_id' => Product::factory()->create(['group_id' => ProductGroup::factory()->create()->id, 'tax' => false])->id,
        'status' => 'active', 'auto_renew' => true, 'billing_cycle' => 'Annually',
        'amount' => 100, 'next_due_date' => '2027-03-10',
    ]);
    $this->travelTo(Carbon::parse('2027-02-01 06:00:00'));

    app(InvoiceGenerationService::class)->generateDueInvoices();

    expect(Invoice::where('client_id', $client->id)->value('due_date')->toDateString())->toBe('2027-05-31');
});

test('the client form sets the terms and clears them back to the general setting', function () {
    $client = Client::factory()->create();
    $form = fn (array $terms) => [
        'first_name' => $client->first_name, 'last_name' => $client->last_name, 'email' => $client->email,
        'country' => $client->country ?: 'IT', 'status' => 'active', 'default_payment_method' => 'banktransfer',
    ] + $terms;

    $this->actingAs(cptAdmin(), 'admin')
        ->put(route('admin.clients.update', $client), $form(['payment_terms_days' => '60', 'payment_terms_end_of_month' => '0']))
        ->assertSessionHasNoErrors();

    expect($client->refresh()->payment_terms_days)->toBe(60)
        ->and($client->payment_terms_end_of_month)->toBeFalse();

    $this->actingAs(cptAdmin(), 'admin')
        ->put(route('admin.clients.update', $client), $form(['payment_terms_days' => '', 'payment_terms_end_of_month' => '']))
        ->assertSessionHasNoErrors();

    expect($client->refresh()->payment_terms_days)->toBeNull()
        ->and($client->payment_terms_end_of_month)->toBeNull();
});

test('an extension moves the due date of an unpaid invoice, takes it out of overdue and is logged', function () {
    $invoice = Invoice::factory()->create(['date' => '2027-02-01', 'due_date' => '2027-03-31', 'status' => 'overdue']);
    $this->travelTo(Carbon::parse('2027-04-05 10:00:00'));

    $this->actingAs(cptAdmin(), 'admin')
        ->put(route('admin.invoices.due-date', $invoice), ['due_date' => '2027-04-30'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    expect($invoice->refresh()->due_date->toDateString())->toBe('2027-04-30')
        ->and($invoice->status->value ?? $invoice->status)->toBe('unpaid')
        ->and(ActivityLog::where('invoice_id', $invoice->id)->latest('id')->value('description'))
        ->toContain(Carbon::parse('2027-04-30')->format(date_fmt()));
});

test('the due date of a settled invoice stays as it is', function (string $status) {
    $invoice = Invoice::factory()->create(['date' => '2027-02-01', 'due_date' => '2027-03-31', 'status' => $status]);

    $this->actingAs(cptAdmin(), 'admin')
        ->put(route('admin.invoices.due-date', $invoice), ['due_date' => '2027-04-30'])
        ->assertSessionHas('error');

    expect($invoice->refresh()->due_date->toDateString())->toBe('2027-03-31');
})->with(['paid', 'cancelled', 'refunded']);

test('a due date before the invoice date is refused', function () {
    $invoice = Invoice::factory()->create(['date' => '2027-02-01', 'due_date' => '2027-03-31', 'status' => 'unpaid']);

    $this->actingAs(cptAdmin(), 'admin')
        ->put(route('admin.invoices.due-date', $invoice), ['due_date' => '2027-01-15'])
        ->assertSessionHasErrors('due_date');

    expect($invoice->refresh()->due_date->toDateString())->toBe('2027-03-31');
});

test('an admin who may only view invoices cannot move a due date', function () {
    $invoice = Invoice::factory()->create(['date' => '2027-02-01', 'due_date' => '2027-03-31', 'status' => 'unpaid']);

    $this->actingAs(cptAdmin(['view_invoices']), 'admin')
        ->put(route('admin.invoices.due-date', $invoice), ['due_date' => '2027-04-30'])
        ->assertForbidden();

    expect($invoice->refresh()->due_date->toDateString())->toBe('2027-03-31');
});
