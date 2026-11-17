<?php

use App\Contracts\RegistrarModuleInterface;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\Module\ModuleRegistry;
use App\Services\PaymentService;
use Carbon\Carbon;

/*
 * DomainRenewOnInvoice: a customer who pays 30 days end of month pays after
 * the domain's expiry, so renewing on payment let it lapse in between. With
 * the setting on, the domain is renewed the day its renewal is invoiced, and
 * paying the invoice does not buy a second year.
 */

/**
 * A registrar that records each renewal and answers with $answers in turn.
 *
 * @param  list<bool>  $answers
 */
function drRegistrar(ArrayObject $calls, array $answers = [true, true]): void
{
    $fake = Mockery::mock(RegistrarModuleInterface::class);
    $fake->shouldReceive('renew')->andReturnUsing(function (Domain $domain, int $years) use ($calls, &$answers) {
        $calls->append($domain->domain);

        if (! array_shift($answers)) {
            return ['success' => false, 'message' => 'insufficient balance'];
        }

        $new = $domain->expiry_date->copy()->addYears($years);
        $domain->update(['expiry_date' => $new, 'next_due_date' => $new]);

        return ['success' => true];
    });
    app()->instance(RegistrarModuleInterface::class, $fake);
    app(ModuleRegistry::class)->registerRegistrar('testreg', RegistrarModuleInterface::class);
}

function drDomain(): Domain
{
    return Domain::factory()->create([
        'client_id' => Client::factory()->create()->id, 'registrar' => 'testreg', 'status' => 'active',
        'domain' => 'example.it', 'registration_period' => 1, 'recurring_amount' => 15.00,
        'expiry_date' => now()->addDays(5)->toDateString(), 'next_due_date' => now()->addDays(5)->toDateString(),
    ]);
}

function drInvoiceFor(Domain $domain): Invoice
{
    return Invoice::whereHas('items', fn ($q) => $q->where('type', 'Domain')->where('rel_id', $domain->id))->firstOrFail();
}

test('with the setting on the domain is renewed when invoiced, and payment does not renew it again', function () {
    Setting::set('DomainRenewOnInvoice', '1');
    $calls = new ArrayObject;
    drRegistrar($calls);
    $domain = drDomain();
    $renewedTo = $domain->expiry_date->copy()->addYear()->toDateString();

    $this->artisan('pnlcs:generate-invoices');

    expect($calls->getArrayCopy())->toBe(['example.it'])
        ->and($domain->refresh()->expiry_date->toDateString())->toBe($renewedTo);

    $invoice = drInvoiceFor($domain);
    app(PaymentService::class)->applyPayment($invoice, 'banktransfer', 'TXN-1', (float) $invoice->total);

    expect($calls->count())->toBe(1)
        ->and($domain->refresh()->expiry_date->toDateString())->toBe($renewedTo);
});

test('with the setting off the domain is renewed only when the invoice is paid', function () {
    $calls = new ArrayObject;
    drRegistrar($calls);
    $domain = drDomain();

    $this->artisan('pnlcs:generate-invoices');

    expect($calls->count())->toBe(0);

    $invoice = drInvoiceFor($domain);
    app(PaymentService::class)->applyPayment($invoice, 'banktransfer', 'TXN-2', (float) $invoice->total);

    expect($calls->count())->toBe(1);
});

test('a renewal the registrar refused on the invoice day is tried again on payment', function () {
    Setting::set('DomainRenewOnInvoice', '1');
    $calls = new ArrayObject;
    drRegistrar($calls, [false, true]);
    $domain = drDomain();
    $expiry = $domain->expiry_date->toDateString();

    $this->artisan('pnlcs:generate-invoices');

    expect($domain->refresh()->expiry_date->toDateString())->toBe($expiry);

    $invoice = drInvoiceFor($domain);
    app(PaymentService::class)->applyPayment($invoice, 'banktransfer', 'TXN-3', (float) $invoice->total);

    expect($calls->count())->toBe(2)
        ->and($domain->refresh()->expiry_date->toDateString())->toBe(Carbon::parse($expiry)->addYear()->toDateString());
});
