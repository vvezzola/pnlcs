<?php

use App\Models\GatewaySettings;
use App\Models\Invoice;
use App\Models\Setting;
use Modules\Gateways\BankTransfer\BankTransferModule;

/*
 * What a customer copies into a bank transfer description: the short code
 * (prefix + invoice id), or, where the invoice numbers are already short,
 * the invoice number itself - one code on the invoice and in the transfer.
 */

it('keeps the short code by default', function () {
    $invoice = Invoice::factory()->create(['invoice_num' => 'ESA270001']);

    expect(payment_ref($invoice))->toBe('INV'.$invoice->id);
});

it('gives the invoice number when the reference is set to it', function () {
    Setting::set('PaymentReferenceSource', 'number', 'general');
    $invoice = Invoice::factory()->create(['invoice_num' => 'ESA270001']);

    expect(payment_ref($invoice))->toBe('ESA270001');
});

it('shows the invoice number as the reference in the bank transfer instructions', function () {
    Setting::set('PaymentReferenceSource', 'number', 'general');
    foreach (['active' => '1', 'account_name' => 'Esalogica Srl', 'bank_active' => '1', 'bank_name' => 'Qonto', 'bank_currency' => 'EUR', 'iban' => 'IT60X0542811101000000123456'] as $setting => $value) {
        GatewaySettings::updateOrCreate(['gateway' => 'banktransfer', 'setting' => $setting], ['value' => $value]);
    }
    $invoice = Invoice::factory()->create(['invoice_num' => 'ESA270001', 'total' => 120, 'subtotal' => 120]);

    $form = app(BankTransferModule::class)->getPaymentForm($invoice);

    expect($form)->toContain('<strong>ESA270001</strong>')
        ->not->toContain('INV'.$invoice->id);
});
