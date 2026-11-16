<?php

use App\Mail\InvoiceCreatedMail;
use App\Mail\RenewalNoticeMail;
use App\Models\CancellationRequest;
use App\Models\Client;
use App\Models\Domain;
use App\Models\EmailTemplate;
use App\Models\GatewaySettings;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Models\Setting;
use App\Services\InvoiceGenerationService;
use App\Services\InvoicePdfService;
use App\Services\InvoiceService;
use Carbon\Carbon;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

/*
 * The monthly cycle business customers in Italy are used to: on the 15th a
 * notice lists what renews two months on, by the end of the month they can
 * cancel, on the 1st one invoice per customer, paid 30 days end of month by
 * bank transfer with the invoice number as the reference.
 */

function mbcSettings(): void
{
    Setting::set('ConsolidatedBillingDay', '1');
    Setting::set('ConsolidatedBillingMonths', '1');
    Setting::set('InvoiceDaysBeforeDue', '0');
    Setting::set('InvoiceDueDays', '30');
    Setting::set('InvoiceDueEndOfMonth', '1');
    Setting::set('RenewalNoticeDay', '15');
}

function mbcService(Client $client, string $due, float $amount = 10.0, string $domain = 'example.it'): Service
{
    return Service::factory()->create([
        'client_id' => $client->id,
        'product_id' => Product::factory()->create(['name' => 'Hosting Base', 'group_id' => ProductGroup::factory()->create()->id, 'tax' => false])->id,
        'status' => 'active', 'auto_renew' => true, 'billing_cycle' => 'Annually',
        'amount' => $amount, 'next_due_date' => $due, 'domain' => $domain,
    ]);
}

/** @return list<string> the item types and ids on the client's invoices */
function mbcInvoices(Client $client): array
{
    return Invoice::where('client_id', $client->id)->orderBy('id')->get()
        ->map(fn ($i) => InvoiceItem::where('invoice_id', $i->id)->orderBy('id')->get()->map(fn ($it) => $it->type.'#'.$it->rel_id)->implode(','))
        ->all();
}

test('the billing day invoices everything due up to the end of the month after, due 30 days end of month', function () {
    mbcSettings();
    $client = Client::factory()->create(['tax_exempt' => true]);
    $feb = mbcService($client, '2027-02-20');
    $endOfMarch = mbcService($client, '2027-03-31');
    $april = mbcService($client, '2027-04-01');
    $this->travelTo(Carbon::parse('2027-02-01 06:00:00'));

    app(InvoiceGenerationService::class)->generateDueInvoices();

    expect(mbcInvoices($client))->toBe(["Hosting#{$feb->id},Hosting#{$endOfMarch->id}"])
        ->and(Invoice::where('client_id', $client->id)->value('due_date')->toDateString())->toBe('2027-03-31');
});

test('between billing days nothing more is raised for what the next billing day covers', function () {
    mbcSettings();
    $client = Client::factory()->create(['tax_exempt' => true]);
    mbcService($client, '2027-04-10');

    foreach (['2027-02-01', '2027-02-15', '2027-02-28'] as $day) {
        $this->travelTo(Carbon::parse($day.' 06:00:00'));
        app(InvoiceGenerationService::class)->generateDueInvoices();
    }

    expect(Invoice::count())->toBe(0);
});

test('the due date moves to the end of the month only when asked', function (string $endOfMonth, string $issued, string $due) {
    Setting::set('InvoiceDueDays', '30');
    Setting::set('InvoiceDueEndOfMonth', $endOfMonth);

    expect(InvoiceService::defaultDueDate(Carbon::parse($issued)))->toBe($due);
})->with([
    'end of month, 1 February' => ['1', '2027-02-01', '2027-03-31'],
    'end of month, 15 January' => ['1', '2027-01-15', '2027-02-28'],
    'plain days' => ['0', '2027-02-01', '2027-03-03'],
]);

test('on the notice day each customer is sent what the next billing day will invoice', function () {
    mbcSettings();
    Mail::fake();
    $client = Client::factory()->create(['tax_exempt' => true, 'email' => 'owner@example.it', 'billing_email' => 'conti@example.it']);
    mbcService($client, '2027-03-10', 25.0, 'shop.example.it');
    Domain::factory()->create(['client_id' => $client->id, 'domain' => 'example.it', 'next_due_date' => '2027-03-20', 'recurring_amount' => 12, 'payment_method' => 'banktransfer']);
    mbcService($client, '2027-04-05', 30.0, 'later.example.it');
    $cancelled = mbcService($client, '2027-03-12', 40.0, 'gone.example.it');
    CancellationRequest::create(['service_id' => $cancelled->id, 'type' => 'end_of_billing', 'reason' => 'no longer needed']);
    $this->travelTo(Carbon::parse('2027-01-15 09:30:00'));

    $this->artisan('pnlcs:renewal-notice')->assertSuccessful();

    Mail::assertQueued(RenewalNoticeMail::class, fn (RenewalNoticeMail $mail) => $mail->hasTo('conti@example.it')
        && $mail->billingDay === '2027-02-01'
        && array_column($mail->lines, 'description') === ['Hosting Base — shop.example.it', 'example.it']
        && array_column($mail->lines, 'amount') === [25.0, 12.0]);
    Mail::assertQueuedCount(1);
});

test('the notice goes out once a month and only on its day', function () {
    mbcSettings();
    Mail::fake();
    mbcService(Client::factory()->create(['tax_exempt' => true]), '2027-03-10');

    $this->travelTo(Carbon::parse('2027-01-14 09:30:00'));
    $this->artisan('pnlcs:renewal-notice')->assertSuccessful();
    $this->travelTo(Carbon::parse('2027-01-15 09:30:00'));
    $this->artisan('pnlcs:renewal-notice')->assertSuccessful();
    $this->artisan('pnlcs:renewal-notice')->assertSuccessful();

    Mail::assertQueuedCount(1);
});

test('the notice, in Italian, names the billing day, the deadline to cancel and the domain', function () {
    mbcSettings();
    $client = Client::factory()->create(['language' => 'it', 'first_name' => 'Mario']);
    $mail = new RenewalNoticeMail($client, [
        ['description' => 'Hosting Base — shop.example.it', 'due' => '10/03/2027', 'amount' => 25.0],
        ['description' => 'example.it', 'due' => '20/03/2027', 'amount' => 12.0, 'domain' => true],
    ], '2027-02-01');

    $html = $mail->locale('it')->render();

    expect($html)->toContain('Li fattureremo il '.Carbon::parse('2027-02-01')->format(date_fmt()))
        ->toContain('entro il '.Carbon::parse('2027-01-31')->format(date_fmt()))
        ->toContain('Dominio example.it')
        ->toContain('Hosting Base — shop.example.it');
});

test('a notice template rewritten by the operator is filled with the billing day, the deadline and the list', function () {
    EmailTemplate::updateOrCreate(['name' => 'Renewal Notice', 'language' => 'en'], [
        'type' => 'invoice', 'custom' => true, 'disabled' => false,
        'subject' => 'Invoice on {billing_date}, cancel by {cancel_by}',
        'message' => "Hello {client_name}\n{renewal_list}",
    ]);
    $client = Client::factory()->create(['first_name' => 'Ayse', 'last_name' => 'Yilmaz', 'language' => 'en']);
    $sent = new ArrayObject;
    Event::listen(MessageSent::class, fn ($event) => $sent->append([$event->message->getSubject(), $event->message->getTextBody()]));

    Mail::to('ayse@example.com')->send(new RenewalNoticeMail($client, [['description' => 'example.it', 'due' => '20/03/2027', 'amount' => 12.0, 'domain' => true]], '2027-02-01'));

    [$subject, $body] = $sent[0];
    expect($subject)->toBe('Invoice on '.Carbon::parse('2027-02-01')->format(date_fmt()).', cancel by '.Carbon::parse('2027-01-31')->format(date_fmt()))
        ->and($body)->toContain('Hello Ayse Yilmaz')
        ->toContain('20/03/2027  Domain example.it');
});

function mbcBankAccount(): void
{
    Setting::set('PaymentReferenceSource', 'number', 'general');
    foreach (['active' => '1', 'account_name' => 'Esalogica Srl', 'bank_active' => '1', 'bank_name' => 'Qonto', 'bank_currency' => 'EUR', 'iban' => 'IT60X0542811101000000123456'] as $setting => $value) {
        GatewaySettings::updateOrCreate(['gateway' => 'banktransfer', 'setting' => $setting], ['value' => $value]);
    }
}

test('the invoice email tells the customer the account and the reference to pay by transfer', function () {
    mbcBankAccount();
    $invoice = Invoice::factory()->create(['invoice_num' => '12/ELH', 'status' => 'unpaid', 'total' => 122, 'subtotal' => 100, 'payment_method' => null]);

    $html = (new InvoiceCreatedMail($invoice))->render();

    expect($html)->toContain('IT60X0542811101000000123456')
        ->toContain('Esalogica Srl')
        ->toContain('<strong>12/ELH</strong>');
});

test('no transfer details for a paid invoice or one paid by card', function (array $state) {
    mbcBankAccount();
    $invoice = Invoice::factory()->create(['invoice_num' => '12/ELH', 'total' => 122, 'subtotal' => 100] + $state);

    expect(app(InvoicePdfService::class)->transferDetails($invoice))->toBeNull();
})->with([
    'paid' => [['status' => 'paid', 'payment_method' => null]],
    'card' => [['status' => 'unpaid', 'payment_method' => 'stripe']],
]);

test('the invoice PDF carries the transfer details', function () {
    mbcBankAccount();
    $invoice = Invoice::factory()->create(['invoice_num' => '12/ELH', 'status' => 'unpaid', 'total' => 122, 'subtotal' => 100, 'payment_method' => null]);

    $html = view('pdf.invoice', ['invoice' => $invoice, 'company' => app(InvoicePdfService::class)->companyDetails(), 'ksef' => null])->render();

    expect($html)->toContain('IT60X0542811101000000123456')
        ->toContain('<strong>12/ELH</strong>');
});
