<?php

use App\Models\AddonSetting;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Addons\BankReconciliation\BankReconciler;
use Modules\Addons\BankReconciliation\BankReconciliationModule;
use Modules\Addons\BankReconciliation\BankReconciliationServiceProvider;
use Modules\Addons\BankReconciliation\BankTransaction;

/*
 * Incoming bank transfers read from Qonto and paid onto the invoices they
 * name. A transfer is paid only when its reference names open invoices and
 * its amount is exactly what they owe; anything else waits for staff.
 */

function reconciliationSettings(array $overrides = []): void
{
    $settings = $overrides + [
        'qonto_login' => 'esalogica-1234',
        'qonto_secret_key' => 'sk-test-secret',
        'iban' => 'IT60X0542811101000000123456',
        'sandbox' => '0',
        'auto_apply' => '1',
        'lookback_days' => '30',
    ];
    foreach ($settings as $setting => $value) {
        AddonSetting::setSetting('bank_reconciliation', $setting, $value);
    }
}

/**
 * A Qonto account whose incoming transfers are the given ones.
 *
 * @param  list<array{id: string, amount: float, reference?: string, label?: string, settled_at?: string}>  $transfers
 */
function fakeQonto(array $transfers): void
{
    Http::preventStrayRequests();
    Http::fake(['https://thirdparty.qonto.com/v2/transactions*' => Http::response([
        'transactions' => array_map(fn (array $transfer) => [
            'id' => $transfer['id'],
            'amount' => $transfer['amount'],
            'amount_cents' => (int) round($transfer['amount'] * 100),
            'currency' => 'EUR',
            'side' => 'credit',
            'status' => 'completed',
            'operation_type' => 'income',
            'label' => $transfer['label'] ?? 'ROSSI SRL',
            'reference' => $transfer['reference'] ?? null,
            'note' => null,
            'settled_at' => $transfer['settled_at'] ?? '2027-01-15T09:30:00.000Z',
            'income' => ['counterparty_account_number' => 'IT02A0301503200000003517230'],
        ], $transfers),
        'meta' => ['current_page' => 1, 'next_page' => null, 'prev_page' => null, 'total_pages' => 1, 'total_count' => count($transfers), 'per_page' => 100],
    ])]);
}

function openInvoice(string $number, float $total, ?Client $client = null): Invoice
{
    return Invoice::factory()->create([
        'client_id' => ($client ?? Client::factory()->create())->id,
        'invoice_num' => $number,
        'type' => 'proforma',
        'subtotal' => $total,
        'total' => $total,
        'status' => 'unpaid',
    ]);
}

function bootReconciliationRoutes(): void
{
    (new BankReconciliationServiceProvider(app()))->boot();
    app('router')->getRoutes()->refreshNameLookups();
}

beforeEach(function () {
    $this->travelTo('2027-01-15 12:00:00');
});

it('pays an invoice whose number and amount the transfer carries', function () {
    reconciliationSettings();
    $invoice = openInvoice('ESA270001', 120.78);
    fakeQonto([['id' => 'tx-1', 'amount' => 120.78, 'reference' => 'Saldo fattura ESA 27 0001']]);

    $counts = app(BankReconciler::class)->sync();

    expect($counts)->toMatchArray(['new' => 1, 'paid' => 1, 'review' => 0])
        ->and($invoice->fresh()->status)->toBe('paid')
        ->and(Transaction::where('invoice_id', $invoice->id)->sole())
        ->gateway->toBe('banktransfer')
        ->transaction_id->toBe('qonto:tx-1:'.$invoice->id)
        ->and(BankTransaction::sole())->status->toBe('matched')->invoice_ids->toBe([$invoice->id]);

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'esalogica-1234:sk-test-secret')
        && str_contains($request->url(), 'iban=IT60X0542811101000000123456')
        && str_contains($request->url(), 'side=credit'));
});

it('pays every invoice a transfer names when it covers them all', function () {
    reconciliationSettings();
    $client = Client::factory()->create();
    $first = openInvoice('ESA270001', 100, $client);
    $second = openInvoice('ESA270002', 50, $client);
    fakeQonto([['id' => 'tx-2', 'amount' => 150, 'reference' => 'ESA270001 ESA270002']]);

    app(BankReconciler::class)->sync();

    expect($first->fresh()->status)->toBe('paid')
        ->and($second->fresh()->status)->toBe('paid')
        ->and(Transaction::whereIn('invoice_id', [$first->id, $second->id])->sum('amount_in'))->toEqual(150);
});

it('recognises the payment reference printed for bank transfers', function () {
    reconciliationSettings();
    $invoice = openInvoice('ESA270005', 30);
    fakeQonto([['id' => 'tx-3', 'amount' => 30, 'reference' => 'pagamento '.payment_ref($invoice)]]);

    app(BankReconciler::class)->sync();

    expect($invoice->fresh()->status)->toBe('paid');
});

it('leaves a partial payment for staff to confirm', function () {
    reconciliationSettings();
    $invoice = openInvoice('ESA270001', 100);
    fakeQonto([['id' => 'tx-4', 'amount' => 40, 'reference' => 'acconto ESA270001']]);

    app(BankReconciler::class)->sync();

    expect($invoice->fresh()->status)->toBe('unpaid')
        ->and(BankTransaction::sole())->status->toBe('review')->reason->toBe('amount_mismatch')->suggested_invoice_ids->toBe([$invoice->id]);

    $admin = Admin::factory()->create(['role_id' => AdminRole::factory()->fullAdmin()->create()->id]);
    bootReconciliationRoutes();
    $this->actingAs($admin)
        ->post(route('admin.bank_reconciliation.confirm', ['bankTransaction' => BankTransaction::sole()->id]), ['invoice_ids' => [$invoice->id]])
        ->assertSessionHas('success');

    expect($invoice->fresh()->status)->toBe('partially_paid')
        ->and(BankTransaction::sole()->status)->toBe('matched');
});

it('suggests the invoice that owes the amount when a transfer names none', function () {
    reconciliationSettings();
    $owing = openInvoice('ESA270009', 59.9);
    openInvoice('ESA270010', 99);
    fakeQonto([['id' => 'tx-5', 'amount' => 59.9, 'reference' => 'bonifico hosting']]);

    app(BankReconciler::class)->sync();

    expect(BankTransaction::sole())->status->toBe('review')->reason->toBe('no_reference')->suggested_invoice_ids->toBe([$owing->id])
        ->and($owing->fresh()->status)->toBe('unpaid');
});

it('waits for confirmation of a certain match while automatic payment is off', function () {
    reconciliationSettings(['auto_apply' => '0']);
    $invoice = openInvoice('ESA270001', 120);
    fakeQonto([['id' => 'tx-6', 'amount' => 120, 'reference' => 'ESA270001']]);

    app(BankReconciler::class)->sync();

    expect(BankTransaction::sole())->status->toBe('review')->reason->toBe('ready')
        ->and($invoice->fresh()->status)->toBe('unpaid');
});

it('never records the same transfer twice', function () {
    reconciliationSettings();
    $invoice = openInvoice('ESA270001', 120);
    fakeQonto([['id' => 'tx-7', 'amount' => 120, 'reference' => 'ESA270001']]);

    app(BankReconciler::class)->sync();
    $again = app(BankReconciler::class)->sync();

    expect($again['new'])->toBe(0)
        ->and(BankTransaction::count())->toBe(1)
        ->and(Transaction::where('invoice_id', $invoice->id)->count())->toBe(1);
});

it('reads the sandbox with its staging token', function () {
    reconciliationSettings(['sandbox' => '1', 'staging_token' => 'stg-123']);
    Http::preventStrayRequests();
    Http::fake(['https://thirdparty-sandbox.staging.qonto.co/v2/transactions*' => Http::response(['transactions' => [], 'meta' => ['next_page' => null]])]);

    app(BankReconciler::class)->sync();

    Http::assertSent(fn (Request $request) => $request->hasHeader('X-Qonto-Staging-Token', 'stg-123'));
});

it('lists the transfers to review on the addon page', function () {
    reconciliationSettings(['auto_apply' => '0']);
    openInvoice('ESA270001', 120);
    fakeQonto([['id' => 'tx-8', 'amount' => 120, 'reference' => 'ESA270001', 'label' => 'BIANCHI SPA']]);
    app(BankReconciler::class)->sync();
    bootReconciliationRoutes();

    $html = app(BankReconciliationModule::class)->output(request());

    expect($html)->toContain('BIANCHI SPA', 'ESA270001', __('messages.bank_reconciliation.reason_ready'));
});
