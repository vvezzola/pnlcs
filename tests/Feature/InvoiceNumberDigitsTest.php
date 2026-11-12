<?php

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\InvoiceService;

/*
 * Invoice and proforma numbers built from a format. {num:N} sets how many
 * digits the sequence is written with, and a date placeholder written right
 * before it no longer runs into the number when the next one is worked out.
 */

function proformaNumbered(string $number, string $date): Invoice
{
    return Invoice::factory()->create([
        'client_id' => Client::factory()->create()->id,
        'type' => 'proforma',
        'invoice_num' => $number,
        'date' => $date,
    ]);
}

it('writes the sequence with the digits {num:N} asks for', function () {
    $this->travelTo('2027-01-10');
    Setting::set('ProformaNumberFormat', 'ESA{yy}{num:4}', 'general');

    expect(app(InvoiceService::class)->generateInvoiceNumber('proforma'))->toBe('ESA270001')
        ->and(app(InvoiceService::class)->renderInvoiceNumber('INV-{num}', 42))->toBe('INV-000042');
});

it('counts on from the last number without reading the year into it', function () {
    $this->travelTo('2027-03-02');
    Setting::set('ProformaNumberFormat', 'ESA{yy}{num:4}', 'general');
    proformaNumbered('ESA270001', '2027-01-10');
    proformaNumbered('ESA270002', '2027-02-11');

    expect(app(InvoiceService::class)->generateInvoiceNumber('proforma'))->toBe('ESA270003');
});

it('starts again from one when the year in the prefix changes', function () {
    $this->travelTo('2028-01-03');
    Setting::set('ProformaNumberFormat', 'ESA{yy}{num:4}', 'general');
    proformaNumbered('ESA270815', '2027-12-30');

    expect(app(InvoiceService::class)->generateInvoiceNumber('proforma'))->toBe('ESA280001');
});

it('previews the next proforma number under any prefix', function () {
    $this->travelTo('2027-03-02');
    Setting::set('ProformaEnabled', '1', 'general');
    Setting::set('ProformaNumberFormat', 'ESA{yy}{num:4}', 'general');
    proformaNumbered('ESA270007', '2027-02-11');
    $admin = Admin::factory()->create(['role_id' => AdminRole::factory()->fullAdmin()->create()->id]);

    $this->actingAs($admin)->get(route('admin.settings.general'))
        ->assertOk()
        ->assertSee('ESA270007')
        ->assertSee('ESA270008');
});

function vatNumbered(string $number, string $date): Invoice
{
    return Invoice::factory()->create([
        'client_id' => Client::factory()->create()->id,
        'type' => 'vat',
        'invoice_num' => $number,
        'date' => $date,
    ]);
}

it('counts on a register numbered number first', function () {
    $this->travelTo('2027-02-01');
    Setting::set('InvoiceNumberFormat', '{num:1}/ELH', 'general');
    vatNumbered('1/ELH', '2027-01-01');
    vatNumbered('2/ELH', '2027-01-01');
    vatNumbered('7/ELS', '2027-01-15');

    expect(app(InvoiceService::class)->generateInvoiceNumber('vat'))->toBe('3/ELH');
});

it('starts a number-first register again in a new year with the yearly reset', function () {
    $this->travelTo('2028-01-01');
    Setting::set('InvoiceNumberFormat', '{num:1}/ELH', 'general');
    Setting::set('InvoiceNumberYearlyReset', '1', 'general');
    vatNumbered('214/ELH', '2027-12-01');

    expect(app(InvoiceService::class)->generateInvoiceNumber('vat'))->toBe('1/ELH');
});
