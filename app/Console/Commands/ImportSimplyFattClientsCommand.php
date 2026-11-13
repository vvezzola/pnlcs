<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Currency;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Esalogica only: loads the customers exported from SimplyFatt (Clienti,
 * tab-separated, Windows-1252) into PNLCS.
 *
 * The SimplyFatt code is the key: a customer already imported is updated, not
 * added again, so the command can run as often as the export changes. It
 * creates customer records only - no logins, no emails, no ClientCreated
 * event: the customers are invited to the panel later, once checked.
 *
 * Rules agreed on 2026-11-08:
 *  - two rows with the same VAT number or fiscal code are one customer: the
 *    highest code is kept;
 *  - no VAT number = a private person, the last word of the name is the
 *    first name;
 *  - the login is the customer's own email; an email shared by several
 *    customers stays as their billing email, and the login becomes their PEC,
 *    or cliente-<code>@clienti.invalid when they have none.
 */
class ImportSimplyFattClientsCommand extends Command
{
    protected $signature = 'esalogica:import-simplyfatt-clients
        {file : The SimplyFatt "Clienti" export (TSV, Windows-1252)}
        {--pec= : Optional TSV "codice<TAB>pec" with the customers\' PEC addresses}
        {--write : Write to the database; without it nothing is changed}';

    protected $description = 'Esalogica: import the customers exported from SimplyFatt (dry run unless --write)';

    public const FIELD_SDI = 'Codice destinatario SDI / PEC';

    public const FIELD_CODE = 'Codice SimplyFatt';

    public const FIELD_PEC = 'PEC';

    private const COUNTRIES = ['italia' => 'IT', 'italy' => 'IT', '' => 'IT', 'austria' => 'AT', 'repubblica di san marino' => 'SM', 'san marino' => 'SM'];

    /** @var array<string, list<string>> section => lines */
    private array $report = [];

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (! is_readable($file)) {
            $this->error("Cannot read {$file}");

            return self::FAILURE;
        }

        $rows = $this->readRows($file);
        $pecs = $this->readPecs($this->option('pec'));
        $customers = $this->buildCustomers($this->dropDuplicates($rows), $pecs);

        $write = (bool) $this->option('write');
        $counts = ['new' => 0, 'updated' => 0, 'unchanged' => 0];
        if ($write) {
            $counts = DB::transaction(fn () => $this->save($customers));
        } else {
            foreach ($customers as $customer) {
                $counts[$this->existingClient($customer['code']) ? 'updated' : 'new']++;
            }
        }

        $this->printReport($rows, $customers, $counts, $write);

        return self::SUCCESS;
    }

    /** @return list<array<string, string>> one array per row, keyed by column name */
    private function readRows(string $file): array
    {
        $text = mb_convert_encoding((string) file_get_contents($file), 'UTF-8', 'Windows-1252');
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        $header = array_map('trim', str_getcsv((string) array_shift($lines), "\t", '"', ''));
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            // NULs, doubled spaces and stray quotes are leftovers of SimplyFatt's own fields.
            $cells = array_map(fn ($cell) => trim((string) preg_replace('/\s+/u', ' ', str_replace("\0", '', $cell)), ' "“”'), str_getcsv($line, "\t", '"', ''));
            $rows[] = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
        }

        return $rows;
    }

    /** @return array<string, string> code => PEC */
    private function readPecs(?string $file): array
    {
        if ($file === null || $file === '') {
            return [];
        }
        $pecs = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            [$code, $pec] = array_pad(explode("\t", $line), 2, '');
            if (filter_var(trim($pec), FILTER_VALIDATE_EMAIL)) {
                $pecs[trim($code)] = strtolower(trim($pec));
            }
        }

        return $pecs;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<array<string, string>>
     */
    private function dropDuplicates(array $rows): array
    {
        usort($rows, fn ($a, $b) => strcmp($b['Codice'], $a['Codice']));
        $seen = [];
        $kept = [];
        foreach ($rows as $row) {
            $keys = array_filter([$this->vat($row), strtoupper($row['Codice Fiscale'] ?? '')]);
            $twin = null;
            foreach ($keys as $key) {
                $twin ??= $seen[$key] ?? null;
            }
            if ($twin !== null) {
                $this->note('Doppioni scartati', "{$row['Codice']} {$row['Denominazione']}  (doppione di {$twin['Codice']} {$twin['Denominazione']})");

                continue;
            }
            foreach ($keys as $key) {
                $seen[$key] = $row;
            }
            $kept[] = $row;
        }
        usort($kept, fn ($a, $b) => strcmp($a['Codice'], $b['Codice']));

        return $kept;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  array<string, string>  $pecs
     * @return list<array<string, mixed>>
     */
    private function buildCustomers(array $rows, array $pecs): array
    {
        $emailUse = [];
        foreach ($rows as $row) {
            $email = strtolower($row['e-mail'] ?? '');
            if ($email !== '') {
                $emailUse[$email] = ($emailUse[$email] ?? 0) + 1;
            }
        }

        // A contact written on several customers is the agency that manages them, not their own person.
        $referentUse = array_count_values(array_filter(array_map(fn ($row) => strtolower($this->cleanReferent($row['Rif. Persona'] ?? '')), $rows)));

        // Logins already handed out: own emails first, then PECs as they are given.
        $logins = array_filter($emailUse, fn ($uses) => $uses === 1);
        $customers = [];
        foreach ($rows as $row) {
            $code = $row['Codice'];
            $name = $row['Denominazione'];
            $vat = $this->vat($row);
            $isPerson = $vat === '';
            $sdi = $row['CodiceSDI/PEC'] ?? '';
            $pec = $pecs[$code] ?? (str_contains($sdi, '@') ? strtolower($sdi) : '');

            [$first, $last] = $this->names($row, $isPerson, $referentUse);

            $email = strtolower($row['e-mail'] ?? '');
            $billing = null;
            if ($email === '' || ($emailUse[$email] ?? 0) > 1) {
                $billing = $email !== '' ? $email : null;
                $shared = $billing ? ", condivisa {$billing} tenuta per le fatture" : '';
                if ($pec !== '' && ! isset($logins[$pec]) && ! isset($emailUse[$pec])) {
                    $email = $pec;
                    $logins[$pec] = 1;
                    $this->note('Login con la PEC', "{$code} {$name}: login con la PEC {$pec}".($billing ? " (email condivisa {$billing} tenuta per le fatture)" : ''));
                } else {
                    $email = 'cliente-'.$code.'@clienti.invalid';
                    $this->note('Login segnaposto', "{$code} {$name}: nessuna email propria{$shared}");
                }
            }

            $country = self::COUNTRIES[strtolower($row['Nazione'] ?? '')] ?? null;
            if ($country === null) {
                $this->note('Nazioni da controllare', "{$code} {$name}: nazione \"{$row['Nazione']}\" non riconosciuta, messa IT");
                $country = 'IT';
            }

            $customers[] = [
                'code' => $code,
                'attributes' => [
                    'company_name' => $isPerson ? null : $name,
                    'first_name' => $first,
                    'last_name' => $last,
                    'client_type' => $isPerson ? 'individual' : 'company',
                    'email' => $email,
                    'billing_email' => $billing,
                    'address1' => $this->tidyCase($row['Indirizzo'] ?? ''),
                    'city' => $this->tidyCase($row['Città'] ?? ''),
                    'state' => $row['Prov.'] ?? '',
                    'postcode' => $this->postcode($row, $code, $name),
                    'country' => $country,
                    'phone_number' => collect(['Telefono1', 'Telefono2', 'Telefono3', 'Cellulare'])->map(fn ($column) => $row[$column] ?? '')->first(fn ($phone) => $phone !== '') ?? '',
                    'tax_id' => $vat !== '' ? $vat : null,
                    'national_id' => ($row['Codice Fiscale'] ?? '') !== '' ? strtoupper($row['Codice Fiscale']) : null,
                    'language' => 'it',
                    'default_payment_method' => 'banktransfer',
                    'notes' => $this->notes($row),
                ],
                'fields' => [
                    // 0000000 and XXXXXXX say "no code"; the e-invoice works that out from the country and the PEC.
                    self::FIELD_SDI => str_contains($sdi, '@') ? strtolower($sdi) : (in_array(strtoupper($sdi), ['0000000', 'XXXXXXX'], true) ? '' : strtoupper($sdi)),
                    self::FIELD_CODE => $code,
                    self::FIELD_PEC => $pec,
                ],
            ];
        }

        return $customers;
    }

    /**
     * @param  list<array<string, mixed>>  $customers
     * @return array{new: int, updated: int, unchanged: int}
     */
    private function save(array $customers): array
    {
        $counts = ['new' => 0, 'updated' => 0, 'unchanged' => 0];
        $fields = $this->ensureFields();
        $currencyId = Currency::where('code', 'EUR')->value('id');

        foreach ($customers as $customer) {
            $client = $this->existingClient($customer['code']);
            $attributes = $customer['attributes'] + ['currency_id' => $currencyId];

            if ($client === null) {
                // Quietly: no ClientCreated listeners, no hooks, no email - and so
                // no "creating" hook either, which is what fills the uuid.
                $client = new Client($attributes + ['status' => 'active']);
                $client->uuid = (string) Str::uuid();
                $client->saveQuietly();
                $counts['new']++;
            } else {
                $client->fill($attributes);
                if ($client->isDirty()) {
                    $client->saveQuietly();
                    $counts['updated']++;
                } else {
                    $counts['unchanged']++;
                }
            }

            foreach ($customer['fields'] as $name => $value) {
                CustomFieldValue::updateOrCreate(['field_id' => $fields[$name], 'rel_id' => $client->id], ['value' => (string) $value]);
            }
        }

        return $counts;
    }

    private function existingClient(string $code): ?Client
    {
        $fieldId = CustomField::where('type', 'client')->where('field_name', self::FIELD_CODE)->value('id');
        if ($fieldId === null) {
            return null;
        }
        $clientId = CustomFieldValue::where('field_id', $fieldId)->where('value', $code)->value('rel_id');

        return $clientId ? Client::find($clientId) : null;
    }

    /** @return array<string, int> field name => id, created when missing */
    private function ensureFields(): array
    {
        $definitions = [
            self::FIELD_SDI => ['Codice destinatario a 7 caratteri oppure PEC per la fattura elettronica', false, true, true, 1],
            self::FIELD_CODE => ['Codice cliente nel gestionale SimplyFatt', true, false, false, 2],
            self::FIELD_PEC => ['Indirizzo PEC del cliente, per gli invii certificati', false, false, false, 3],
        ];
        $ids = [];
        foreach ($definitions as $name => [$description, $adminOnly, $onInvoice, $onOrder, $sort]) {
            $ids[$name] = CustomField::firstOrCreate(
                ['type' => 'client', 'field_name' => $name],
                ['rel_id' => 0, 'field_type' => 'text', 'description' => $description, 'admin_only' => $adminOnly, 'required' => false,
                    'sort_order' => $sort, 'show_on_invoice' => $onInvoice, 'show_on_order' => $onOrder],
            )->id;
        }

        return $ids;
    }

    /** @param array<string, string> $row */
    private function vat(array $row): string
    {
        return strtoupper(preg_replace('/\s+/', '', $row['Partita IVA'] ?? $row['Partita IVA '] ?? ''));
    }

    /**
     * First and last name for the account.
     *
     * A company's own contact person when it has one; a sole trader or a
     * private person from the name itself, written "Surname Name" (after
     * "di" in "Officina di Rossi Mario"); otherwise the company name.
     *
     * @param  array<string, string>  $row
     * @param  array<string, int>  $referentUse
     * @return array{0: string, 1: string}
     */
    private function names(array $row, bool $isPerson, array $referentUse): array
    {
        $code = $row['Codice'];
        $company = $row['Denominazione'];
        $referent = $this->cleanReferent($row['Rif. Persona'] ?? '');

        if (! $isPerson && $referent !== '' && ($referentUse[strtolower($referent)] ?? 0) === 1 && str_contains($referent, ' ')) {
            $words = explode(' ', $this->tidy($referent));

            return [(string) array_shift($words), implode(' ', $words)];
        }

        $personal = (bool) preg_match('/^[A-Z]{6}\d\d[A-Z]\d\d[A-Z]\d{3}[A-Z]$/', strtoupper($row['Codice Fiscale'] ?? ''));
        $hasLegalForm = (bool) preg_match('/\b(s\.?r\.?l|s\.?p\.?a|s\.?n\.?c|s\.?a\.?s|s\.?s)\b\.?|&/i', $company);
        if (($isPerson || $personal) && ! $hasLegalForm) {
            $person = preg_match('/\sdi\s(.+)$/i', $company, $m) ? $m[1] : $company;
            $person = trim((string) preg_replace(['/\bc\/o\b.*$/i', '/\b(ing|geom|arch|dott|avv|rag)\.\s*/i'], '', $person));

            return $this->splitByFiscalCode($this->tidy($person), strtoupper($row['Codice Fiscale'] ?? ''), $code);
        }

        $this->note('Nomi da controllare', "{$code} {$company}: nessun referente, nome e cognome lasciati alla ragione sociale");

        return [$this->tidy($company), ''];
    }

    /** "Sig.ra Federica Pravettoni", "Gaudenzi Luca (Uff. Acquisti)" → the name alone. */
    private function cleanReferent(string $referent): string
    {
        $referent = (string) preg_replace('/\(.*?\)/', '', $referent);
        $referent = (string) preg_replace('/^(sig\.?(ra|na)?|dott\.?(ssa)?|ing\.?|arch\.?|geom\.?|avv\.?|rag\.?)\s+/i', '', trim($referent));

        return trim($referent);
    }

    /**
     * Which words are the surname: the ones whose letters give the first three
     * of the fiscal code ("Luca Genovese" with GNV…: surname last), else the
     * Italian order, surname first ("De Pascale Salvatore").
     *
     * @return array{0: string, 1: string} first name, surname
     */
    private function splitByFiscalCode(string $name, string $fiscalCode, string $code): array
    {
        $words = explode(' ', $name);
        if (count($words) < 2) {
            return [$name, ''];
        }

        // The shortest run of words that gives the code: only the first three
        // consonants count, so "Morelli Marco" gives MRL as "Morelli" does.
        $wanted = substr($fiscalCode, 0, 3);
        if ($wanted !== '') {
            for ($k = 1; $k < count($words); $k++) {
                if ($this->surnameCode(implode(' ', array_slice($words, 0, $k))) === $wanted) {
                    return [implode(' ', array_slice($words, $k)), implode(' ', array_slice($words, 0, $k))];
                }
            }
            for ($k = 1; $k < count($words); $k++) {
                if ($this->surnameCode(implode(' ', array_slice($words, $k))) === $wanted) {
                    return [implode(' ', array_slice($words, 0, $k)), implode(' ', array_slice($words, $k))];
                }
            }
        }

        if (count($words) > 2) {
            $this->note('Nomi da controllare', "{$code} {$name}: nome e cognome divisi a tentativo, il codice fiscale non aiuta");
        }
        $surname = [array_shift($words)];
        while (count($words) > 1 && preg_match('/^(de|di|da|del|della|dal|dalla|lo|la|li)$/i', end($surname))) {
            $surname[] = array_shift($words);
        }

        return [implode(' ', $words), implode(' ', $surname)];
    }

    /** The three letters a surname gives the Italian fiscal code: consonants, then vowels, then X. */
    private function surnameCode(string $surname): string
    {
        $letters = strtoupper((string) preg_replace('/[^a-z]/i', '', Str::ascii($surname)));
        $consonants = (string) preg_replace('/[AEIOU]/', '', $letters);
        $vowels = (string) preg_replace('/[^AEIOU]/', '', $letters);

        return substr(str_pad($consonants.$vowels, 3, 'X'), 0, 3);
    }

    /** An address or a town typed all in capitals, in ordinary capitals. */
    private function tidyCase(string $text): string
    {
        if ($text === '' || $text !== mb_strtoupper($text)) {
            return $text;
        }

        // "D'adda" → "D'Adda": a capital after the apostrophe too.
        return (string) preg_replace_callback("/'(\p{Ll})/u", fn ($m) => "'".mb_strtoupper($m[1]), mb_convert_case(mb_strtolower($text), MB_CASE_TITLE));
    }

    /** Words written all in capitals get ordinary capitals; legal forms stay as they are. */
    private function tidy(string $text): string
    {
        if ($text !== mb_strtoupper($text)) {
            return $text;
        }

        return (string) preg_replace_callback('/\S+/u', function ($m) {
            $word = $m[0];
            // Legal forms and dotted initials (I.T.A.) stay in capitals.
            if (preg_match('/^(S\.?R\.?L\.?S?\.?|S\.?P\.?A\.?|S\.?N\.?C\.?|S\.?A\.?S\.?|S\.?S\.?)$|^(\p{Lu}\.){2,}$/u', $word)) {
                return $word;
            }

            return Str::ucfirst(mb_strtolower($word));
        }, $text);
    }

    /** @param array<string, string> $row */
    private function postcode(array $row, string $code, string $name): string
    {
        $cap = $row['C.A.P.'] ?? '';
        // A doubled zero typed in a Milan-area code: 200081 for 20081.
        if (preg_match('/^(\d\d)0(0\d\d)$/', $cap, $m)) {
            $this->note('CAP', "{$code} {$name}: CAP {$cap} corretto in {$m[1]}{$m[2]}");

            return $m[1].$m[2];
        }

        return $cap;
    }

    /** @param array<string, string> $row */
    private function notes(array $row): string
    {
        $payment = $row['Pagamento'] ?? '';
        if (($row['Banca Predefinita'] ?? '') !== '') {
            $payment .= " ({$row['Banca Predefinita']})";
        }
        $lines = ['SimplyFatt: codice '.$row['Codice']];
        if ($payment !== '') {
            $lines[] = 'Pagamento SimplyFatt: '.$payment;
        }
        if (($row['HomePage'] ?? '') !== '') {
            $lines[] = 'Sito: '.$row['HomePage'];
        }
        if (! in_array($row['Saldo'] ?? '', ['', '0,00', '-0,00'], true)) {
            $lines[] = "Saldo aperto in SimplyFatt all'esportazione: {$row['Saldo']} EUR";
        }
        if (($row['Note'] ?? '') !== '') {
            $lines[] = 'Note SimplyFatt: '.$row['Note'];
        }

        return implode("\n", $lines);
    }

    private function note(string $section, string $line): void
    {
        $this->report[$section][] = $line;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  list<array<string, mixed>>  $customers
     * @param  array{new: int, updated: int, unchanged: int}  $counts
     */
    private function printReport(array $rows, array $customers, array $counts, bool $write): void
    {
        $people = count(array_filter($customers, fn ($c) => $c['attributes']['client_type'] === 'individual'));
        $this->line($write ? 'IMPORTAZIONE ESEGUITA' : 'PROVA: nessuna modifica al database (aggiungi --write per importare)');
        $this->line(sprintf('Righe lette: %d   tenute: %d   doppioni scartati: %d', count($rows), count($customers), count($this->report['Doppioni scartati'] ?? [])));
        $this->line(sprintf('Aziende: %d   Privati: %d', count($customers) - $people, $people));
        $this->line(sprintf('Login con PEC: %d   con segnaposto: %d', count($this->report['Login con la PEC'] ?? []), count($this->report['Login segnaposto'] ?? [])));
        $this->line($write
            ? sprintf('Clienti: %d nuovi, %d aggiornati, %d invariati', $counts['new'], $counts['updated'], $counts['unchanged'])
            : sprintf('Clienti che verrebbero: %d nuovi, %d già presenti (da aggiornare se cambiati)', $counts['new'], $counts['updated']));

        foreach (['Doppioni scartati', 'Login con la PEC', 'Login segnaposto', 'CAP', 'Nazioni da controllare', 'Nomi da controllare'] as $section) {
            if (! empty($this->report[$section])) {
                $this->newLine();
                $this->line("== {$section} (".count($this->report[$section]).')');
                foreach ($this->report[$section] as $line) {
                    $this->line('  '.$line);
                }
            }
        }
    }
}
