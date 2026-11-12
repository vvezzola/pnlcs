<?php

use App\Models\AddonSetting;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Addons\Invoicetronic\InvoicetronicServiceProvider;
use Modules\Addons\Invoicetronic\SdiSender;
use Modules\Addons\Invoicetronic\SdiSubmission;

/*
 * Issued invoices sent to the SDI through Invoicetronic: on their own once
 * they have waited the delay and are dated from the start date, by hand from
 * the addon page; a refused one waits for staff, an unreachable Invoicetronic
 * is retried without sending twice.
 */

const SDI_SEND_URL = 'https://api.invoicetronic.com/v1/send/xml*';

function sdiSettings(array $overrides = []): void
{
    $sdi = $overrides + ['api_key' => 'ik_test_abc', 'auto_send' => '1', 'send_from' => '2027-01-01', 'delay_minutes' => '60'];
    foreach ($sdi as $setting => $value) {
        AddonSetting::setSetting('invoicetronic', $setting, $value);
    }

    $seller = [
        'vat_number' => '03268930983', 'fiscal_code' => 'VZZVTR70H25D940G', 'first_name' => 'Vittorio', 'last_name' => 'Vezzola',
        'tax_regime' => 'RF01', 'address' => 'Via Ariosto 60', 'postcode' => '25088', 'city' => 'Toscolano-Maderno', 'province' => 'BS',
    ];
    foreach ($seller as $setting => $value) {
        AddonSetting::setSetting('fatturapa', $setting, $value);
    }
}

function sdiInvoice(array $attributes = []): Invoice
{
    $invoice = Invoice::factory()->create($attributes + [
        'invoice_num' => '1/ELH', 'date' => '2027-01-01', 'due_date' => '2027-02-28', 'status' => 'unpaid', 'type' => 'vat',
        'tax_mode' => null, 'source_currency' => 'EUR', 'total' => 439.20,
        'buyer_company_name' => 'Rossi Srl', 'buyer_address1' => 'Via Roma 1', 'buyer_postcode' => '24121', 'buyer_city' => 'Bergamo',
        'buyer_state' => 'BG', 'buyer_country' => 'IT', 'buyer_tax_id' => 'IT01234567890', 'buyer_client_type' => 'company',
        'buyer_custom_fields' => ['Codice destinatario SDI / PEC' => 'M5UXCR1'],
        'created_at' => '2027-01-01 08:00:00',
    ]);
    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id, 'client_id' => $invoice->client_id, 'description' => 'Hosting WebPro50 rossi.it',
        'amount' => 360.0, 'qty' => 1, 'taxed' => true, 'tax_rate' => 22.0,
    ]);

    return $invoice->fresh();
}

function acceptedSend(string $id = '23601', ?string $state = null): array
{
    return ['id' => (int) $id, 'file_name' => 'IT01180680397ITRON_04EP2.xml', 'identifier' => null, 'latest_state' => $state, 'format' => 'FPR12'];
}

function sdiAdmin(): Admin
{
    $role = AdminRole::create(['name' => 'Billing', 'permissions' => ['manage_invoices']]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

beforeEach(function () {
    $this->travelTo('2027-01-01 12:00:00');
    Http::preventStrayRequests();
});

it('sends a due invoice and records what Invoicetronic answered', function () {
    sdiSettings();
    $invoice = sdiInvoice();
    Http::fake([SDI_SEND_URL => Http::response(acceptedSend(), 201)]);

    expect(app(SdiSender::class)->sendDue())->toBe(['sent' => 1, 'errors' => 0]);

    $submission = SdiSubmission::sole();
    expect($submission->invoice_id)->toBe($invoice->id)
        ->and($submission->external_id)->toBe('23601')
        ->and($submission->state)->toBe(SdiSubmission::SUBMITTED)
        ->and($submission->sent_at)->not->toBeNull();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'send/xml?validate=true')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('ik_test_abc:'))
        && $request->hasHeader('Idempotency-Key')
        && str_contains($request->body(), '<Numero>1/ELH</Numero>'));
});

it('leaves alone invoices it must not send on its own', function (array $invoice, array $settings) {
    sdiSettings($settings);
    sdiInvoice($invoice);
    Http::fake();

    expect(app(SdiSender::class)->sendDue())->toBe(['sent' => 0, 'errors' => 0]);
    expect(SdiSubmission::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'dated before the start date' => [['date' => '2026-12-31'], []],
    'created within the delay' => [['created_at' => '2027-01-01 11:30:00'], []],
    'a proforma' => [['type' => 'proforma'], []],
    'a draft' => [['status' => 'draft'], []],
    'cancelled' => [['status' => 'cancelled'], []],
    'automatic sending off' => [[], ['auto_send' => '0']],
    'no start date' => [[], ['send_from' => '']],
]);

it('keeps a refused invoice for staff, who send it again once it is fixed', function () {
    sdiSettings();
    $invoice = sdiInvoice();
    Http::fake([SDI_SEND_URL => Http::sequence()
        ->push(['title' => 'Unprocessable Entity', 'validation_errors' => [['description' => 'Codice destinatario non valido', 'property_name' => 'CodiceDestinatario']]], 422)
        ->push(acceptedSend('23700'), 201)]);

    expect(app(SdiSender::class)->sendDue())->toBe(['sent' => 0, 'errors' => 1]);
    $refused = SdiSubmission::sole();
    expect($refused->state)->toBe(SdiSubmission::ERROR)
        ->and($refused->message)->toContain('CodiceDestinatario: Codice destinatario non valido');

    // Not retried on its own: the same file would be refused again.
    app(SdiSender::class)->sendDue();
    Http::assertSentCount(1);

    (new InvoicetronicServiceProvider(app()))->boot();
    app('router')->getRoutes()->refreshNameLookups();
    $this->actingAs(sdiAdmin(), 'admin')
        ->post(route('admin.invoicetronic.send'), ['number' => $invoice->invoice_num])
        ->assertSessionHas('success');

    expect(SdiSubmission::where('invoice_id', $invoice->id)->latest('id')->first())
        ->state->toBe(SdiSubmission::SUBMITTED)
        ->external_id->toBe('23700');
});

it('refuses to send an invoice a second time while the first is on its way', function () {
    sdiSettings();
    $invoice = sdiInvoice();
    Http::fake([SDI_SEND_URL => Http::response(acceptedSend(), 201)]);
    app(SdiSender::class)->send($invoice, 'staff');

    expect(fn () => app(SdiSender::class)->send($invoice, 'staff'))->toThrow(RuntimeException::class);
    Http::assertSentCount(1);
});

it('retries a post that did not get through under the same idempotency key', function () {
    sdiSettings();
    sdiInvoice();
    Http::fake([SDI_SEND_URL => Http::sequence()->push(['title' => 'Bad Gateway'], 502)->push(acceptedSend(), 201)]);

    expect(app(SdiSender::class)->sendDue())->toBe(['sent' => 0, 'errors' => 0]);
    expect(SdiSubmission::sole()->state)->toBe(SdiSubmission::SENDING);

    expect(app(SdiSender::class)->sendDue())->toBe(['sent' => 1, 'errors' => 0]);
    expect(SdiSubmission::sole()->state)->toBe(SdiSubmission::SUBMITTED);

    $keys = collect(Http::recorded())->map(fn (array $pair) => $pair[0]->header('Idempotency-Key')[0])->unique();
    expect($keys)->toHaveCount(1);
});

it('records an invoice that cannot become an e-invoice as an error without calling Invoicetronic', function () {
    sdiSettings();
    sdiInvoice(['buyer_tax_id' => null, 'buyer_national_id' => null]);
    Http::fake();

    expect(app(SdiSender::class)->sendDue())->toBe(['sent' => 0, 'errors' => 1]);
    expect(SdiSubmission::sole()->message)->toContain('neither a VAT number nor a fiscal code');
    Http::assertNothingSent();
});

it('reads the SDI outcome, with the reasons of a rejection', function () {
    sdiSettings();
    $delivered = SdiSubmission::create(['invoice_id' => sdiInvoice()->id, 'provider' => 'invoicetronic', 'external_id' => '1', 'state' => SdiSubmission::SUBMITTED, 'sent_at' => now()]);
    $rejected = SdiSubmission::create(['invoice_id' => sdiInvoice(['invoice_num' => '2/ELH'])->id, 'provider' => 'invoicetronic', 'external_id' => '2', 'state' => 'Inviato', 'sent_at' => now()]);
    Http::fake([
        'https://api.invoicetronic.com/v1/send?*' => Http::response([
            ['identifier' => '9001'] + acceptedSend('1', 'Consegnato'),
            ['identifier' => '9002'] + acceptedSend('2', 'Scartato'),
        ]),
        'https://api.invoicetronic.com/v1/update?*' => Http::response([
            ['send_id' => 2, 'state' => 'Scartato', 'description' => 'Fattura duplicata', 'errors' => [['code' => '00404', 'description' => 'Fattura duplicata', 'hint' => 'Verificare il campo Numero']]],
        ]),
    ]);

    expect(app(SdiSender::class)->refresh())->toBe(2);

    expect($delivered->fresh())->state->toBe(SdiSubmission::DELIVERED)->sdi_identifier->toBe('9001')->message->toBeNull()
        ->and($rejected->fresh())->state->toBe(SdiSubmission::REJECTED)
        ->and($rejected->fresh()->message)->toBe('00404 Fattura duplicata (Verificare il campo Numero)');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'ids=1%2C2') || str_contains($request->url(), 'ids=1,2'));
});
