<?php

namespace Modules\Addons\SimplyFatt;

use App\Models\CustomField;
use App\Models\Invoice;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Carbon;
use Locale;

/**
 * Issued invoices as a SimplyFatt "SF-XML" import file, for an operator who
 * keeps transmitting to the SDI from SimplyFatt: PNLCS bills, SimplyFatt
 * imports the documents and sends them on as it does today.
 *
 * The layout follows a file SimplyFatt 2.9 exported itself. Fields PNLCS has
 * no value for are written empty, the way SimplyFatt writes them.
 */
class SimplyFattBuilder
{
    /** @param  array<string, string>|null  $settings  defaults to the addon's saved settings */
    public function __construct(private ?array $settings = null) {}

    /** @param  iterable<Invoice>  $invoices */
    public function build(iterable $invoices): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $root = $doc->createElement('SimplyFattDocuments');
        $root->setAttribute('Version', '1.0');
        $root->setAttribute('Creator', 'PNLCS');
        $doc->appendChild($root);
        $documents = $this->add($root, 'Documents');

        foreach ($invoices as $invoice) {
            if ($invoice->isProforma()) {
                continue;
            }
            $this->document($this->add($documents, 'Document'), $invoice);
        }

        return (string) $doc->saveXML();
    }

    private function document(DOMElement $node, Invoice $invoice): void
    {
        $settings = $this->settings();
        $invoice->loadMissing('items');

        $rows = [];
        $net = 0.0;
        $vat = 0.0;
        foreach ($invoice->items as $item) {
            $tax = $invoice->lineTax($item);
            $qty = (float) max(1, (int) ($item->qty ?? 1));
            $lineNet = round((float) $tax['net'], 2);
            $rows[] = ['item' => $item, 'qty' => $qty, 'net' => $lineNet, 'rate' => (float) $tax['rate']];
            $net += $lineNet;
            $vat += round($lineNet * (float) $tax['rate'] / 100, 2);
        }
        $total = $net + $vat;
        [$number, $numbering] = $this->number((string) $invoice->invoice_num);

        $this->fields($node, [
            'Document_Type' => $total < 0 ? 'Nota di credito' : 'Fattura',
            'Document_Number' => $number,
            'Document_Numbering' => $numbering,
            'Document_Date' => Carbon::parse($invoice->date ?? now())->format('Y-m-d'),
            'Document_PriceList' => '',
            'Document_Warehouse' => 'Principale',
            'Document_Currency' => strtoupper((string) ($invoice->source_currency ?: 'EUR')),
            'Document_TotalWithoutTax' => $this->amount(abs($net)),
            'Document_VatAmount' => $this->amount(abs($vat)),
            'Document_Total' => $this->amount(abs($total)),
            'Document_PricesIncludeVat' => 'False',
            'Document_WithholdingTaxPerc' => '0.0',
            'Document_WithholdingTaxPercOfTot' => '0.0',
            'Document_PaymentName' => $settings['payment_name'],
            'Document_PaymentBank' => $settings['bank_name'],
            'Document_PaymentBankCoordinates' => $settings['bank_coordinates'],
            'Document_InternalComment' => 'PNLCS #'.$invoice->id,
            'Document_FootNotes' => $settings['foot_notes'],
        ]);

        $country = strtoupper((string) ($invoice->buyer('country') ?: 'IT'));
        $company = trim((string) $invoice->buyer('company_name'));
        $person = trim($invoice->buyer('last_name').' '.$invoice->buyer('first_name'));
        $vatNumber = preg_replace('/^'.$country.'(?=\d)/', '', strtoupper(preg_replace('/\s+/', '', (string) $invoice->buyer('tax_id'))));

        $this->fields($node, [
            'Customer_Type' => 'C',
            'Customer_Code' => $this->customerCode($invoice),
            'Customer_Name' => $company !== '' ? $company : $person,
            'Customer_Address' => trim($invoice->buyer('address1').' '.$invoice->buyer('address2')),
            'Customer_Postcode' => (string) $invoice->buyer('postcode'),
            'Customer_City' => (string) $invoice->buyer('city'),
            'Customer_Province' => $country === 'IT' ? strtoupper((string) $invoice->buyer('state')) : '',
            'Customer_Country' => $this->countryName($country),
            'Customer_VatNumber' => $vatNumber,
            'Customer_FiscalCode' => strtoupper((string) $invoice->buyer('national_id')),
            'Customer_Email' => (string) $invoice->buyer('email'),
            'FEPA_IsFatturaPA' => 'True',
            'FEPA_CodiceDestinatario' => $this->recipient($invoice, $country),
        ]);

        $rowsNode = $this->add($node, 'Rows');
        foreach ($rows as $row) {
            $sign = $total < 0 ? -1 : 1;
            $this->fields($this->add($rowsNode, 'Row'), [
                'Row_Code' => $this->articleCode((string) $row['item']->type),
                'Row_Description' => (string) $row['item']->description,
                'Row_Qty' => $this->amount($row['qty']),
                'Row_Um' => '',
                'Row_Price' => $this->amount($sign * $row['net'] / $row['qty']),
                'Row_Discounts' => '',
                'Row_VatCode' => $row['rate'] > 0 ? rtrim(rtrim(number_format($row['rate'], 2, '.', ''), '0'), '.') : $settings['zero_vat_code'],
                'Row_Total' => $this->amount($sign * $row['net']),
                'Row_WithholdingTax' => 'False',
                'Row_Notes' => '',
            ]);
        }

        $payment = $this->add($this->add($node, 'Payments'), 'Payment');
        $paid = (string) $invoice->status === 'paid';
        $this->fields($payment, [
            'Payment_ExpirationDate' => Carbon::parse($invoice->due_date ?? $invoice->date ?? now())->format('Y-m-d'),
            'Payment_Amount' => $this->amount(abs($total)),
            'Payment_PaymentDate' => $paid && $invoice->date_paid ? Carbon::parse($invoice->date_paid)->format('Y-m-d') : '',
            'Payment_Paid' => $paid ? 'true' : 'false',
            'Payment_Description' => '',
            'Payment_FinancialResource' => 'Banca',
        ]);
    }

    /**
     * "116/ELH" is number 116 in the "/ELH" series, the way SimplyFatt
     * splits it; a prefix series ("INV-12") keeps its prefix as the series.
     *
     * @return array{0: string, 1: string}
     */
    private function number(string $invoiceNumber): array
    {
        if (preg_match('/^(\d+)(.*)$/', $invoiceNumber, $m)) {
            return [ltrim($m[1], '0') ?: '0', $m[2]];
        }
        if (preg_match('/^(.*?)(\d+)$/', $invoiceNumber, $m)) {
            return [ltrim($m[2], '0') ?: '0', $m[1]];
        }

        return [$invoiceNumber, ''];
    }

    /** The SimplyFatt customer code kept in a client custom field, when there is one. */
    private function customerCode(Invoice $invoice): string
    {
        $field = CustomField::where('type', 'client')->where('field_name', $this->settings()['customer_code_field'])->first();

        return $field && $invoice->client_id ? (string) $field->valueFor($invoice->client_id) : '';
    }

    /** SimplyFatt takes the SDI code or the PEC address in the same field. */
    private function recipient(Invoice $invoice, string $country): string
    {
        if ($country !== 'IT') {
            return 'XXXXXXX';
        }

        $value = trim((string) ($invoice->buyerCustomFields()[$this->settings()['recipient_field']] ?? ''));

        return $value !== '' ? (str_contains($value, '@') ? $value : strtoupper($value)) : '0000000';
    }

    private function articleCode(string $type): string
    {
        $type = strtolower($type);

        return match (true) {
            str_contains($type, 'domain'), in_array($type, ['register', 'transfer', 'renew'], true) => 'DNS',
            in_array($type, ['hosting', 'upgrade'], true) => 'HOST',
            default => '',
        };
    }

    private function countryName(string $code): string
    {
        $name = class_exists(Locale::class) ? Locale::getDisplayRegion('-'.$code, 'it') : '';

        return $name !== '' && $name !== $code ? ucfirst($name) : $code;
    }

    /** Decimals the way SimplyFatt writes them: "350.0", "77.5", "12.34". */
    private function amount(float $value): string
    {
        $text = rtrim(number_format(round($value, 2), 2, '.', ''), '0');

        return str_ends_with($text, '.') ? $text.'0' : $text;
    }

    /** @param  array<string, string>  $fields */
    private function fields(DOMElement $node, array $fields): void
    {
        foreach ($fields as $name => $value) {
            $this->add($node, $name, $value);
        }
    }

    private function add(DOMElement $parent, string $name, ?string $value = null): DOMElement
    {
        $node = $parent->ownerDocument->createElement($name);
        if ($value !== null && $value !== '') {
            $node->appendChild($parent->ownerDocument->createTextNode($value));
        }
        $parent->appendChild($node);

        return $node;
    }

    /** @return array<string, string> */
    private function settings(): array
    {
        return $this->settings ??= SimplyFattSettings::resolve();
    }
}
