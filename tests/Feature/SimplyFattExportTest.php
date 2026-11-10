<?php

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Modules\Addons\SimplyFatt\SimplyFattBuilder;
use Modules\Addons\SimplyFatt\SimplyFattServiceProvider;

/*
 * Esalogica's bridge to SimplyFatt: PNLCS bills, SimplyFatt imports the
 * period as SF-XML and transmits to the SDI as it did before. The expected
 * values come from an invoice SimplyFatt exported itself (116/ELH).
 */

function sfSettings(): array
{
    return [
        'customer_code_field' => 'Codice SimplyFatt', 'recipient_field' => 'Codice destinatario SDI / PEC',
        'payment_name' => 'Bonifico 30gg FM', 'bank_name' => 'BPER', 'bank_coordinates' => 'IBAN: IT00X0000000000000000000000',
        'zero_vat_code' => 'N2.2', 'foot_notes' => '',
    ];
}

function sfInvoice(array $attributes = [], float $amount = 350.0): Invoice
{
    $invoice = Invoice::factory()->create(array_merge([
        'invoice_num' => '116/ELH', 'date' => '2026-11-05', 'due_date' => '2026-12-31', 'status' => 'unpaid',
        'type' => 'vat', 'tax_mode' => null, 'source_currency' => 'EUR',
        'buyer_company_name' => 'OMATEX SRL', 'buyer_address1' => 'Via Pizzigoni, 2/B', 'buyer_postcode' => '24060',
        'buyer_city' => 'VILLONGO', 'buyer_state' => 'BG', 'buyer_country' => 'IT', 'buyer_tax_id' => '01258680170',
        'buyer_custom_fields' => ['Codice destinatario SDI / PEC' => 'omatex@pec.example.it'],
    ], $attributes));
    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id, 'client_id' => $invoice->client_id, 'type' => 'Hosting',
        'description' => 'Aggiornamento Software Sito OMATEX.IT', 'amount' => $amount, 'qty' => 1, 'taxed' => true, 'tax_rate' => 22,
    ]);

    return $invoice->fresh();
}

it('writes the invoice the way SimplyFatt exports its own', function () {
    $field = CustomField::create(['type' => 'client', 'field_name' => 'Codice SimplyFatt', 'field_type' => 'text']);
    $invoice = sfInvoice();
    CustomFieldValue::create(['field_id' => $field->id, 'rel_id' => $invoice->client_id, 'value' => '0047']);

    $xml = simplexml_load_string((new SimplyFattBuilder(sfSettings()))
        ->build([$invoice, sfInvoice(['invoice_num' => 'PRO-9', 'type' => 'proforma'])]));

    $document = $xml->Documents->Document;
    expect($xml->Documents->Document->count())->toBe(1)
        ->and((string) $document->Document_Type)->toBe('Fattura')
        ->and((string) $document->Document_Number)->toBe('116')
        ->and((string) $document->Document_Numbering)->toBe('/ELH')
        ->and((string) $document->Document_TotalWithoutTax)->toBe('350.0')
        ->and((string) $document->Document_VatAmount)->toBe('77.0')
        ->and((string) $document->Document_Total)->toBe('427.0')
        ->and((string) $document->Document_PaymentName)->toBe('Bonifico 30gg FM')
        ->and((string) $document->Customer_Code)->toBe('0047')
        ->and((string) $document->Customer_Name)->toBe('OMATEX SRL')
        ->and((string) $document->Customer_Country)->toBe('Italia')
        ->and((string) $document->FEPA_CodiceDestinatario)->toBe('omatex@pec.example.it')
        ->and((string) $document->Rows->Row->Row_Code)->toBe('HOST')
        ->and((string) $document->Rows->Row->Row_Price)->toBe('350.0')
        ->and((string) $document->Rows->Row->Row_VatCode)->toBe('22')
        ->and((string) $document->Payments->Payment->Payment_ExpirationDate)->toBe('2026-12-31')
        ->and((string) $document->Payments->Payment->Payment_Paid)->toBe('false');
});

it('writes money given back as a credit note with positive amounts', function () {
    $xml = simplexml_load_string((new SimplyFattBuilder(sfSettings()))->build([sfInvoice(amount: -100.0)]));

    expect((string) $xml->Documents->Document->Document_Type)->toBe('Nota di credito')
        ->and((string) $xml->Documents->Document->Document_Total)->toBe('122.0')
        ->and((string) $xml->Documents->Document->Rows->Row->Row_Total)->toBe('100.0');
});

it('downloads a period from the addon page', function () {
    (new SimplyFattServiceProvider(app()))->boot();
    app('router')->getRoutes()->refreshNameLookups();
    sfInvoice();
    sfInvoice(['invoice_num' => '1/ELH', 'date' => '2026-10-01']);

    $response = $this->actingAs(Admin::factory()->create(['role_id' => AdminRole::factory()->fullAdmin()->create()->id]), 'admin')
        ->post(route('admin.simplyfatt.export'), ['from' => '2026-11-01', 'to' => '2026-11-30'])
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="simplyfatt_2026-11-01_2026-11-30.xml"');

    expect(simplexml_load_string($response->getContent())->Documents->Document->count())->toBe(1);
});
