<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Domain;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Esalogica only: the services and domains customers have today, from the
 * reviewed subscription sheets (out/import-pnlcs.csv, made by
 * pnlcs-tools/import/import_abbonamenti.py with every id and date resolved).
 *
 * "crea" makes a service or a domain, "aggiorna" brings a service already in
 * PNLCS (made by the Plesk sync page) to the sheet's product, price, cycle
 * and due date, keeping its link to Plesk. Nothing is provisioned, no mail
 * is sent, nothing is invoiced: services and domains at 0 are never
 * invoiced, the others on their due dates. Dry run unless --write; running
 * it again creates nothing twice.
 */
class ImportAbbonamentiCommand extends Command
{
    protected $signature = 'esalogica:import-abbonamenti
        {file : The import-pnlcs.csv file}
        {--write : Write to the database; without it nothing is changed}';

    protected $description = 'Esalogica: import the customers\' services and domains from the reviewed sheet (dry run unless --write)';

    private const NOTE = 'Import abbonamenti 2026-11';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (! is_readable($file)) {
            $this->error("Non riesco a leggere {$file}");

            return self::FAILURE;
        }

        $rows = $this->rows($file);
        $problems = $this->check($rows);
        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->error($problem);
            }

            return self::FAILURE;
        }

        $write = (bool) $this->option('write');
        $done = ['servizi creati' => 0, 'servizi aggiornati' => 0, 'domini creati' => 0, 'già presenti' => 0];

        DB::transaction(function () use ($rows, $write, &$done) {
            foreach ($rows as $row) {
                $key = match (true) {
                    $row['tipo'] === 'dominio' => $this->domain($row, $write),
                    $row['azione'] === 'aggiorna' => $this->update($row, $write),
                    default => $this->service($row, $write),
                };
                $done[$key]++;
            }
        });

        foreach ($done as $what => $count) {
            $this->line(str_pad((string) $count, 5, ' ', STR_PAD_LEFT)."  {$what}");
        }
        $this->line($write ? 'Fatto.' : 'Prova: nulla è stato scritto. Aggiungi --write per importare.');

        return self::SUCCESS;
    }

    /** @return list<array<string, string>> */
    private function rows(string $file): array
    {
        $handle = fopen($file, 'r');
        $header = fgetcsv($handle, escape: '\\');
        $rows = [];
        while (($line = fgetcsv($handle, escape: '\\')) !== false) {
            if ($line !== [null]) {
                $rows[] = array_combine($header, array_pad($line, count($header), ''));
            }
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Every id the sheet names must exist: a wrong one stops the import
     * before anything is written.
     *
     * @param  list<array<string, string>>  $rows
     * @return list<string>
     */
    private function check(array $rows): array
    {
        $clients = Client::pluck('id')->flip();
        $products = Product::pluck('id')->flip();
        $servers = Server::pluck('id')->flip();
        $problems = [];
        foreach ($rows as $i => $row) {
            $line = $i + 2;
            if (! isset($clients[(int) $row['client_id']])) {
                $problems[] = "riga {$line}: cliente {$row['client_id']} inesistente";
            }
            if ($row['tipo'] === 'servizio' && ! isset($products[(int) $row['product_id']])) {
                $problems[] = "riga {$line}: prodotto {$row['product_id']} inesistente";
            }
            if ($row['server_id'] !== '' && ! isset($servers[(int) $row['server_id']])) {
                $problems[] = "riga {$line}: server {$row['server_id']} inesistente";
            }
            if ($row['azione'] === 'aggiorna') {
                $service = Service::find((int) $row['service_id']);
                if (! $service || (int) $service->client_id !== (int) $row['client_id']) {
                    $problems[] = "riga {$line}: il servizio #{$row['service_id']} non c'è o è di un altro cliente";
                }
            }
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['prossima_scadenza'])) {
                $problems[] = "riga {$line}: scadenza \"{$row['prossima_scadenza']}\" non valida";
            }
        }

        return $problems;
    }

    /** @param  array<string, string>  $row */
    private function note(array $row): string
    {
        return self::NOTE.' ['.substr(sha1($row['tipo'].'|'.$row['client_id'].'|'.$row['product_id'].'|'.$row['oggetto'].'|'.$row['nota']), 0, 10).']'
            .($row['nota'] !== '' ? ': '.$row['nota'] : '');
    }

    /** @param  array<string, string>  $row */
    private function service(array $row, bool $write): string
    {
        $note = $this->note($row);
        $tag = substr($note, 0, strpos($note, ']') + 1);
        $exists = Service::where('client_id', (int) $row['client_id'])
            ->where(fn ($q) => $q->where('notes', 'like', $tag.'%')
                ->orWhere(fn ($q) => $row['oggetto'] !== ''
                    ? $q->where('product_id', (int) $row['product_id'])->where('domain', $row['oggetto'])
                    : $q->whereRaw('0 = 1')))
            ->exists();
        if ($exists) {
            return 'già presenti';
        }

        if ($write) {
            (new Service([
                'client_id' => (int) $row['client_id'],
                'product_id' => (int) $row['product_id'],
                'server_id' => $row['server_id'] !== '' ? (int) $row['server_id'] : null,
                'domain' => $row['oggetto'] !== '' ? $row['oggetto'] : null,
                'qty' => 1,
                'billing_cycle' => $row['ciclo'],
                'amount' => $row['importo'],
                'first_payment_amount' => $row['importo'],
                'next_due_date' => $row['prossima_scadenza'],
                'registration_date' => now()->toDateString(),
                'status' => 'active',
                'auto_renew' => true,
                'notes' => $note,
            ]))->saveQuietly();
        }

        return 'servizi creati';
    }

    /** @param  array<string, string>  $row */
    private function update(array $row, bool $write): string
    {
        $service = Service::find((int) $row['service_id']);
        $changes = [
            'product_id' => (int) $row['product_id'],
            'billing_cycle' => $row['ciclo'],
            'amount' => $row['importo'],
            'next_due_date' => $row['prossima_scadenza'],
        ];
        $same = (int) $service->product_id === $changes['product_id'] && $service->billing_cycle === $changes['billing_cycle']
            && number_format((float) $service->amount, 2, '.', '') === $changes['amount']
            && $service->next_due_date?->toDateString() === $changes['next_due_date'];
        if ($same) {
            return 'già presenti';
        }

        if ($write) {
            $service->forceFill($changes + [
                'notes' => trim((string) $service->notes."\n".$this->note($row)),
            ])->saveQuietly();
        }

        return 'servizi aggiornati';
    }

    /** @param  array<string, string>  $row */
    private function domain(array $row, bool $write): string
    {
        if (Domain::where('domain', $row['oggetto'])->exists()) {
            return 'già presenti';
        }

        if ($write) {
            (new Domain([
                'client_id' => (int) $row['client_id'],
                'type' => 'register',
                'domain' => $row['oggetto'],
                'registrar' => $row['registrar'] ?: 'manual',
                'registration_period' => (int) ($row['anni'] ?: 1),
                'expiry_date' => $row['scadenza_registrar'] ?: $row['prossima_scadenza'],
                'next_due_date' => $row['prossima_scadenza'],
                'status' => 'active',
                'first_payment_amount' => $row['importo'],
                'recurring_amount' => $row['importo'],
                'notes' => $this->note($row).(($row['presso'] ?? '') !== '' ? ' (presso '.$row['presso'].')' : ''),
            ]))->saveQuietly();
        }

        return 'domini creati';
    }
}
