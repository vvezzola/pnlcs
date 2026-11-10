<?php

use App\Models\AddonSetting;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use Modules\Addons\FatturaPA\FatturaPABuilder;
use Modules\Addons\FatturaPA\FatturaPAServiceProvider;

/*
 * Italian e-invoices: an issued invoice becomes a FatturaPA file that passes
 * the official schema (the builder refuses to return one that does not), so
 * the transmitting service accepts it as it is.
 */

function fpaSettings(array $overrides = []): array
{
    return array_merge([
        'vat_number' => '03268930983', 'fiscal_code' => 'VZZVTR70H25D940G', 'company_name' => '',
        'first_name' => 'Vittorio', 'last_name' => 'Vezzola', 'tax_regime' => 'RF01',
        'address' => 'Via Ariosto 60', 'postcode' => '25088', 'city' => 'Toscolano-Maderno', 'province' => 'BS',
        'phone' => '', 'email' => 'info@esalogica.test', 'transmitter_id' => '',
        'recipient_field' => 'Codice destinatario SDI / PEC',
        'zero_rate_nature' => 'N2.2', 'zero_rate_reference' => 'Operazione non soggetta', 'vat_due' => 'I',
        'payment_method' => 'MP05', 'iban' => 'IT60 X054 2811 1010 0000 0123 456', 'bank_name' => '', 'beneficiary' => '',
    ], $overrides);
}

/** @param  list<array{0: string, 1: float, 2?: float, 3?: int}>  $lines  description, amount, rate, qty */
function fpaInvoice(array $buyer = [], array $lines = [['Hosting WebPro50 rossi.it', 360.0, 22.0]], array $invoice = []): Invoice
{
    $record = Invoice::factory()->create(array_merge([
        'invoice_num' => '30/ELH', 'date' => '2027-01-15', 'due_date' => '2027-02-28', 'status' => 'paid',
        'type' => 'vat', 'tax_mode' => null, 'source_currency' => 'EUR',
        'buyer_company_name' => 'Rossi Srl', 'buyer_first_name' => 'Mario', 'buyer_last_name' => 'Rossi',
        'buyer_address1' => 'Via Roma 1', 'buyer_postcode' => '24121', 'buyer_city' => 'Bergamo', 'buyer_state' => 'BG',
        'buyer_country' => 'IT', 'buyer_tax_id' => 'IT01234567890', 'buyer_national_id' => null, 'buyer_client_type' => 'company',
        'buyer_custom_fields' => ['Codice destinatario SDI / PEC' => 'm5uxcr1'],
    ], $buyer, $invoice));

    foreach ($lines as $line) {
        [$description, $amount, $rate, $qty] = $line + [2 => 22.0, 3 => 1];
        InvoiceItem::factory()->create([
            'invoice_id' => $record->id, 'client_id' => $record->client_id, 'description' => $description,
            'amount' => $amount, 'qty' => $qty, 'taxed' => $rate > 0, 'tax_rate' => $rate,
        ]);
    }

    return $record->fresh();
}

function fpaXml(Invoice $invoice, array $settings = []): SimpleXMLElement
{
    return simplexml_load_string((new FatturaPABuilder(fpaSettings($settings)))->build($invoice));
}

it('turns a business invoice into a valid e-invoice delivered by recipient code', function () {
    $xml = fpaXml(fpaInvoice());

    $header = $xml->FatturaElettronicaHeader;
    $body = $xml->FatturaElettronicaBody;
    expect((string) $header->DatiTrasmissione->CodiceDestinatario)->toBe('M5UXCR1')
        ->and($header->DatiTrasmissione->PECDestinatario->count())->toBe(0)
        ->and((string) $header->CedentePrestatore->DatiAnagrafici->IdFiscaleIVA->IdCodice)->toBe('03268930983')
        ->and((string) $header->CedentePrestatore->DatiAnagrafici->Anagrafica->Cognome)->toBe('Vezzola')
        ->and((string) $header->CessionarioCommittente->DatiAnagrafici->IdFiscaleIVA->IdCodice)->toBe('01234567890')
        ->and((string) $header->CessionarioCommittente->DatiAnagrafici->Anagrafica->Denominazione)->toBe('Rossi Srl')
        ->and((string) $body->DatiGenerali->DatiGeneraliDocumento->TipoDocumento)->toBe('TD01')
        ->and((string) $body->DatiGenerali->DatiGeneraliDocumento->Numero)->toBe('30/ELH')
        ->and((string) $body->DatiGenerali->DatiGeneraliDocumento->ImportoTotaleDocumento)->toBe('439.20')
        ->and((string) $body->DatiBeniServizi->DatiRiepilogo->ImponibileImporto)->toBe('360.00')
        ->and((string) $body->DatiBeniServizi->DatiRiepilogo->Imposta)->toBe('79.20')
        ->and((string) $body->DatiPagamento->DettaglioPagamento->IBAN)->toBe('IT60X0542811101000000123456');
});

it('identifies a private buyer by fiscal code and delivers to their PEC', function () {
    $xml = fpaXml(fpaInvoice([
        'buyer_company_name' => null, 'buyer_client_type' => 'individual', 'buyer_tax_id' => null,
        'buyer_national_id' => 'rssmra80a01a794x', 'buyer_custom_fields' => ['Codice destinatario SDI / PEC' => 'mario@pec.it'],
    ]));

    $buyer = $xml->FatturaElettronicaHeader->CessionarioCommittente->DatiAnagrafici;
    expect((string) $xml->FatturaElettronicaHeader->DatiTrasmissione->CodiceDestinatario)->toBe('0000000')
        ->and((string) $xml->FatturaElettronicaHeader->DatiTrasmissione->PECDestinatario)->toBe('mario@pec.it')
        ->and($buyer->IdFiscaleIVA->count())->toBe(0)
        ->and((string) $buyer->CodiceFiscale)->toBe('RSSMRA80A01A794X')
        ->and((string) $buyer->Anagrafica->Nome)->toBe('Mario');
});

it('sums each VAT rate apart and gives untaxed lines their nature', function () {
    $xml = fpaXml(fpaInvoice([], [
        ['Hosting', 100.0, 22.0, 2], ['Dominio .it', 15.0, 22.0], ['Marca da bollo rimborsata', 2.0, 0.0],
    ]));

    $summary = $xml->FatturaElettronicaBody->DatiBeniServizi->DatiRiepilogo;
    expect((string) $summary[0]->ImponibileImporto)->toBe('215.00')
        ->and((string) $summary[0]->Imposta)->toBe('47.30')
        ->and((string) $summary[1]->Natura)->toBe('N2.2')
        ->and((string) $xml->FatturaElettronicaBody->DatiBeniServizi->DettaglioLinee[0]->PrezzoUnitario)->toBe('100.00')
        ->and((string) $xml->FatturaElettronicaBody->DatiGenerali->DatiGeneraliDocumento->ImportoTotaleDocumento)->toBe('264.30');
});

it('takes VAT out of prices that include it and keeps text the schema accepts', function () {
    $xml = fpaXml(fpaInvoice([], [['Rinnovo “PEC” – 1 anno € 12', 12.2, 22.0]], ['tax_mode' => Invoice::TAX_INCLUSIVE]));

    $line = $xml->FatturaElettronicaBody->DatiBeniServizi->DettaglioLinee[0];
    expect((string) $line->PrezzoTotale)->toBe('10.00')
        ->and((string) $line->Descrizione)->toBe('Rinnovo "PEC" - 1 anno EUR 12');
});

it('makes a credit note of an invoice that gives money back', function () {
    $xml = fpaXml(fpaInvoice([], [['Storno hosting', -100.0, 22.0]]));

    expect((string) $xml->FatturaElettronicaBody->DatiGenerali->DatiGeneraliDocumento->TipoDocumento)->toBe('TD04')
        ->and((string) $xml->FatturaElettronicaBody->DatiBeniServizi->DettaglioLinee[0]->PrezzoTotale)->toBe('100.00')
        ->and((string) $xml->FatturaElettronicaBody->DatiGenerali->DatiGeneraliDocumento->ImportoTotaleDocumento)->toBe('122.00');
});

it('sends a foreign buyer to the conventional code', function () {
    $xml = fpaXml(fpaInvoice(['buyer_country' => 'DE', 'buyer_tax_id' => 'DE123456789', 'buyer_state' => 'Bayern']));

    expect((string) $xml->FatturaElettronicaHeader->DatiTrasmissione->CodiceDestinatario)->toBe('XXXXXXX')
        ->and((string) $xml->FatturaElettronicaHeader->CessionarioCommittente->DatiAnagrafici->IdFiscaleIVA->IdCodice)->toBe('123456789')
        ->and((string) $xml->FatturaElettronicaHeader->CessionarioCommittente->Sede->CAP)->toBe('00000');
});

it('refuses what cannot become an e-invoice', function (array $buyer, array $invoice, string $reason) {
    expect(fn () => (new FatturaPABuilder(fpaSettings()))->build(fpaInvoice($buyer, invoice: $invoice)))
        ->toThrow(RuntimeException::class, $reason);
})->with([
    'a proforma' => [[], ['type' => 'proforma'], 'proforma'],
    'an Italian buyer with no tax identifier' => [['buyer_tax_id' => null, 'buyer_national_id' => null], [], 'neither a VAT number nor a fiscal code'],
]);

it('refuses to build without the seller VAT number', function () {
    expect(fn () => (new FatturaPABuilder(fpaSettings(['vat_number' => ''])))->build(fpaInvoice()))
        ->toThrow(RuntimeException::class, 'seller VAT number');
});

it('exports a period as a zip and lists the invoices it could not build', function () {
    Setting::set('addon_fatturapa_active', '1', 'addons');
    foreach (fpaSettings() as $setting => $value) {
        AddonSetting::updateOrCreate(['addon' => 'fatturapa', 'setting' => $setting], ['value' => $value]);
    }
    (new FatturaPAServiceProvider(app()))->boot();
    app('router')->getRoutes()->refreshNameLookups();

    $good = fpaInvoice();
    fpaInvoice(['buyer_tax_id' => null], invoice: ['invoice_num' => '31/ELH']);
    fpaInvoice(invoice: ['invoice_num' => 'PRO-1', 'type' => 'proforma']);
    fpaInvoice(invoice: ['invoice_num' => '1/ELH', 'date' => '2026-12-31']);

    $response = $this->actingAs(Admin::factory()->create(['role_id' => AdminRole::factory()->fullAdmin()->create()->id]), 'admin')
        ->post(route('admin.fatturapa.export'), ['from' => '2027-01-01', 'to' => '2027-01-31'])
        ->assertOk();

    $zip = new ZipArchive;
    $zip->open($response->getFile()->getPathname());
    $names = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->sort()->values()->all();

    expect($names)->toBe(['ERRORI.txt', (new FatturaPABuilder(fpaSettings()))->fileName($good)])
        ->and($zip->getFromName('ERRORI.txt'))->toContain('31/ELH');
});
