<?php

namespace Modules\Addons\FatturaPA;

use App\Models\Invoice;
use DOMDocument;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Turns an issued invoice into an Italian e-invoice (FatturaPA, format FPR12
 * for businesses and private buyers), checked against the official schema.
 *
 * The file is what the operator hands to whoever transmits to the SDI:
 * Aruba's e-invoicing service, an accountant, or another accounting program.
 */
class FatturaPABuilder
{
    private const NS = 'http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2';

    /** @param  array<string, string>|null  $settings  defaults to the addon's saved settings */
    public function __construct(private ?array $settings = null) {}

    /** The file name the SDI expects: IT + transmitter code + "_" + progressive. */
    public function fileName(Invoice $invoice): string
    {
        return 'IT'.$this->transmitterId().'_'.$this->progressive($invoice, 5).'.xml';
    }

    /**
     * @throws RuntimeException when the invoice cannot be an e-invoice, or the
     *                          result does not pass the schema
     */
    public function build(Invoice $invoice): string
    {
        if ($invoice->isProforma()) {
            throw new RuntimeException("Invoice {$invoice->invoice_num} is a proforma: only VAT invoices become e-invoices.");
        }

        $invoice->loadMissing('items');
        $settings = $this->settings();
        $lines = $this->lines($invoice);
        $isCreditNote = array_sum(array_column($lines, 'net')) < 0;
        $sign = $isCreditNote ? -1 : 1;

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElementNS(self::NS, 'p:FatturaElettronica');
        $root->setAttribute('versione', 'FPR12');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', 'http://www.w3.org/2000/09/xmldsig#');
        $doc->appendChild($root);

        $header = $this->add($root, 'FatturaElettronicaHeader');
        $this->transmission($this->add($header, 'DatiTrasmissione'), $invoice);
        $this->seller($this->add($header, 'CedentePrestatore'), $settings);
        $this->buyer($this->add($header, 'CessionarioCommittente'), $invoice);

        $body = $this->add($root, 'FatturaElettronicaBody');
        $general = $this->add($this->add($body, 'DatiGenerali'), 'DatiGeneraliDocumento');
        $this->add($general, 'TipoDocumento', $isCreditNote ? 'TD04' : 'TD01');
        $this->add($general, 'Divisa', strtoupper((string) ($invoice->source_currency ?: 'EUR')));
        $this->add($general, 'Data', ($invoice->date ?? now())->format('Y-m-d'));
        $this->add($general, 'Numero', $this->text((string) ($invoice->invoice_num ?: $invoice->id), 20));

        $goods = $this->add($body, 'DatiBeniServizi');
        $summary = [];
        foreach ($lines as $index => $line) {
            $net = $sign * $line['net'];
            $row = $this->add($goods, 'DettaglioLinee');
            $this->add($row, 'NumeroLinea', (string) ($index + 1));
            $this->add($row, 'Descrizione', $this->text($line['description'], 1000));
            $this->add($row, 'Quantita', $this->money($line['qty']));
            $this->add($row, 'PrezzoUnitario', $this->price($net / $line['qty']));
            $this->add($row, 'PrezzoTotale', $this->money($net));
            $this->add($row, 'AliquotaIVA', $this->money($line['rate']));
            if ($line['rate'] <= 0) {
                $this->add($row, 'Natura', $settings['zero_rate_nature']);
            }

            $key = $this->money($line['rate']);
            $summary[$key] = ($summary[$key] ?? 0.0) + $net;
        }

        $documentTotal = 0.0;
        krsort($summary, SORT_NUMERIC);
        foreach ($summary as $rate => $taxable) {
            $tax = round($taxable * (float) $rate / 100, 2);
            $documentTotal += round($taxable, 2) + $tax;

            $row = $this->add($goods, 'DatiRiepilogo');
            $this->add($row, 'AliquotaIVA', $rate);
            if ((float) $rate <= 0) {
                $this->add($row, 'Natura', $settings['zero_rate_nature']);
            }
            $this->add($row, 'ImponibileImporto', $this->money($taxable));
            $this->add($row, 'Imposta', $this->money($tax));
            if ((float) $rate > 0) {
                $this->add($row, 'EsigibilitaIVA', $settings['vat_due']);
            } else {
                $this->add($row, 'RiferimentoNormativo', $this->text($settings['zero_rate_reference'], 100));
            }
        }

        // The schema wants the document total right after the number.
        $general->appendChild($doc->createElement('ImportoTotaleDocumento', $this->money($documentTotal)));

        if (! $isCreditNote && $documentTotal > 0) {
            $this->payment($this->add($body, 'DatiPagamento'), $invoice, $documentTotal, $settings);
        }

        $xml = (string) $doc->saveXML();
        $this->validate($xml);

        return $xml;
    }

    /**
     * The invoice lines with their net amounts, in the shape the e-invoice
     * needs them: amounts before VAT, one rate per line.
     *
     * @return list<array{description: string, qty: float, net: float, rate: float}>
     */
    private function lines(Invoice $invoice): array
    {
        $lines = [];
        foreach ($invoice->items as $item) {
            $tax = $invoice->lineTax($item);
            $lines[] = [
                'description' => (string) $item->description,
                'qty' => (float) max(1, (int) ($item->qty ?? 1)),
                'net' => round((float) $tax['net'], 2),
                'rate' => (float) $tax['rate'],
            ];
        }

        if ($lines === []) {
            throw new RuntimeException("Invoice {$invoice->invoice_num} has no lines.");
        }

        return $lines;
    }

    private function transmission(\DOMElement $node, Invoice $invoice): void
    {
        $id = $this->add($node, 'IdTrasmittente');
        $this->add($id, 'IdPaese', 'IT');
        $this->add($id, 'IdCodice', $this->transmitterId());
        $this->add($node, 'ProgressivoInvio', $this->progressive($invoice, 10));
        $this->add($node, 'FormatoTrasmissione', 'FPR12');

        $recipient = $this->recipient($invoice);
        $this->add($node, 'CodiceDestinatario', $recipient['code']);
        if ($recipient['pec'] !== null) {
            $this->add($node, 'PECDestinatario', $recipient['pec']);
        }
    }

    /** @param  array<string, string>  $settings */
    private function seller(\DOMElement $node, array $settings): void
    {
        $vat = $this->digits($settings['vat_number']);
        if (strlen($vat) !== 11) {
            throw new RuntimeException('Set the seller VAT number (11 digits) on the FatturaPA addon page.');
        }

        $data = $this->add($node, 'DatiAnagrafici');
        $id = $this->add($data, 'IdFiscaleIVA');
        $this->add($id, 'IdPaese', 'IT');
        $this->add($id, 'IdCodice', $vat);
        if ($settings['fiscal_code'] !== '') {
            $this->add($data, 'CodiceFiscale', strtoupper($settings['fiscal_code']));
        }
        $this->name($this->add($data, 'Anagrafica'), $settings['company_name'], $settings['first_name'], $settings['last_name']);
        $this->add($data, 'RegimeFiscale', $settings['tax_regime']);

        $this->address($this->add($node, 'Sede'), $settings['address'], $settings['postcode'], $settings['city'], $settings['province'], 'IT');

        if ($settings['phone'] !== '' || $settings['email'] !== '') {
            $contacts = $this->add($node, 'Contatti');
            if ($settings['phone'] !== '') {
                $this->add($contacts, 'Telefono', $this->text($settings['phone'], 12));
            }
            if ($settings['email'] !== '') {
                $this->add($contacts, 'Email', $settings['email']);
            }
        }
    }

    private function buyer(\DOMElement $node, Invoice $invoice): void
    {
        $country = strtoupper((string) ($invoice->buyer('country') ?: 'IT'));
        $company = trim((string) $invoice->buyer('company_name'));
        $vat = strtoupper(preg_replace('/\s+/', '', (string) $invoice->buyer('tax_id')));
        $vat = preg_replace('/^'.$country.'(?=[0-9A-Z])/', '', $vat);
        $nationalId = strtoupper(trim((string) $invoice->buyer('national_id')));
        $isPerson = $invoice->buyer('client_type') === 'individual' || $company === '';

        $data = $this->add($node, 'DatiAnagrafici');
        // A private buyer is identified by fiscal code alone; a business by its
        // VAT number, with the fiscal code only when it is a different one.
        if ($vat !== '' && ! ($isPerson && $nationalId !== '')) {
            $id = $this->add($data, 'IdFiscaleIVA');
            $this->add($id, 'IdPaese', $country);
            $this->add($id, 'IdCodice', $this->text($vat, 28));
        }
        if ($nationalId !== '' && $nationalId !== $vat) {
            $this->add($data, 'CodiceFiscale', $nationalId);
        } elseif ($vat === '' && $country === 'IT') {
            throw new RuntimeException("Invoice {$invoice->invoice_num}: the buyer has neither a VAT number nor a fiscal code.");
        }

        $this->name(
            $this->add($data, 'Anagrafica'),
            $isPerson ? '' : $company,
            (string) $invoice->buyer('first_name'),
            (string) $invoice->buyer('last_name'),
        );

        $street = trim($invoice->buyer('address1').' '.$invoice->buyer('address2'));
        $this->address(
            $this->add($node, 'Sede'),
            $street,
            $country === 'IT' ? (string) $invoice->buyer('postcode') : '00000',
            (string) $invoice->buyer('city'),
            $country === 'IT' ? (string) $invoice->buyer('state') : '',
            $country,
        );
    }

    /**
     * Where the SDI delivers the invoice: a 7-character recipient code, or
     * "0000000" with the buyer's PEC address, or "0000000" alone (the buyer
     * finds it in their tax drawer); "XXXXXXX" for a foreign buyer.
     *
     * @return array{code: string, pec: ?string}
     */
    private function recipient(Invoice $invoice): array
    {
        if (strtoupper((string) ($invoice->buyer('country') ?: 'IT')) !== 'IT') {
            return ['code' => 'XXXXXXX', 'pec' => null];
        }

        $value = trim((string) ($invoice->buyerCustomFields()[$this->settings()['recipient_field']] ?? ''));

        if (str_contains($value, '@')) {
            return ['code' => '0000000', 'pec' => $value];
        }
        if (preg_match('/^[A-Za-z0-9]{7}$/', $value)) {
            return ['code' => strtoupper($value), 'pec' => null];
        }

        return ['code' => '0000000', 'pec' => null];
    }

    /** @param  array<string, string>  $settings */
    private function payment(\DOMElement $node, Invoice $invoice, float $total, array $settings): void
    {
        $this->add($node, 'CondizioniPagamento', 'TP02');
        $detail = $this->add($node, 'DettaglioPagamento');
        if ($settings['beneficiary'] !== '') {
            $this->add($detail, 'Beneficiario', $this->text($settings['beneficiary'], 200));
        }
        $this->add($detail, 'ModalitaPagamento', $settings['payment_method']);
        if ($invoice->due_date) {
            $this->add($detail, 'DataScadenzaPagamento', Carbon::parse($invoice->due_date)->format('Y-m-d'));
        }
        $this->add($detail, 'ImportoPagamento', $this->money($total));
        if ($settings['bank_name'] !== '') {
            $this->add($detail, 'IstitutoFinanziario', $this->text($settings['bank_name'], 80));
        }
        if ($settings['iban'] !== '') {
            $this->add($detail, 'IBAN', strtoupper(preg_replace('/\s+/', '', $settings['iban'])));
        }
    }

    private function name(\DOMElement $node, string $company, string $first, string $last): void
    {
        if (trim($company) !== '') {
            $this->add($node, 'Denominazione', $this->text($company, 80));

            return;
        }

        $this->add($node, 'Nome', $this->text($first, 60));
        $this->add($node, 'Cognome', $this->text($last, 60));
    }

    private function address(\DOMElement $node, string $street, string $postcode, string $city, string $province, string $country): void
    {
        $this->add($node, 'Indirizzo', $this->text($street !== '' ? $street : '-', 60));
        $this->add($node, 'CAP', preg_match('/^\d{5}$/', trim($postcode)) ? trim($postcode) : '00000');
        $this->add($node, 'Comune', $this->text($city !== '' ? $city : '-', 60));
        if (preg_match('/^[A-Za-z]{2}$/', trim($province))) {
            $this->add($node, 'Provincia', strtoupper(trim($province)));
        }
        $this->add($node, 'Nazione', $country);
    }

    /** Checks the file against the FatturaPA schema; the errors become the message. */
    private function validate(string $xml): void
    {
        $doc = new DOMDocument;
        $doc->loadXML($xml);

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $valid = $doc->schemaValidate(__DIR__.'/xsd/FatturaPA_v1.2.2.xsd');
        $errors = array_map(fn ($error) => trim($error->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $valid) {
            throw new RuntimeException('FatturaPA schema: '.implode(' | ', array_unique($errors)));
        }
    }

    private function transmitterId(): string
    {
        $settings = $this->settings();
        $id = strtoupper(preg_replace('/\s+/', '', $settings['transmitter_id']));

        return $id !== '' ? $id : $this->digits($settings['vat_number']);
    }

    /** A short code unique per invoice, made of the invoice id. */
    private function progressive(Invoice $invoice, int $length): string
    {
        return str_pad(strtoupper(base_convert((string) $invoice->id, 10, 36)), $length, '0', STR_PAD_LEFT);
    }

    /**
     * Text as the schema accepts it: Latin-1 characters only (a "€" or a
     * curly quote would be refused), one line, cut to the field length.
     */
    private function text(string $value, int $max): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        $value = strtr($value, ['€' => 'EUR', '’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-', '…' => '...']);
        $latin = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $value);
        $value = mb_convert_encoding($latin !== false ? $latin : $value, 'UTF-8', 'ISO-8859-1');

        return mb_substr($value !== '' ? $value : '-', 0, $max);
    }

    private function digits(string $value): string
    {
        return (string) preg_replace('/\D/', '', $value);
    }

    private function money(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }

    /** A unit price: two decimals, up to eight when the division needs them. */
    private function price(float $value): string
    {
        $text = rtrim(number_format($value, 8, '.', ''), '0');
        [$whole, $decimals] = explode('.', $text) + [1 => ''];

        return $whole.'.'.str_pad($decimals, 2, '0');
    }

    private function add(\DOMElement $parent, string $name, ?string $value = null): \DOMElement
    {
        $node = $parent->ownerDocument->createElement($name);
        if ($value !== null) {
            $node->appendChild($parent->ownerDocument->createTextNode($value));
        }
        $parent->appendChild($node);

        return $node;
    }

    /** @return array<string, string> */
    private function settings(): array
    {
        return $this->settings ??= FatturaPASettings::resolve();
    }
}
