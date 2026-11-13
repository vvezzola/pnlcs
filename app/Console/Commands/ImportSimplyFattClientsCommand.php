<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Addons\SimplyFatt\SimplyFattClientImporter;
use RuntimeException;

/**
 * Esalogica only: the SimplyFatt customer import from the console. The rules
 * live in SimplyFattClientImporter, shared with the SimplyFatt addon page.
 */
class ImportSimplyFattClientsCommand extends Command
{
    protected $signature = 'esalogica:import-simplyfatt-clients
        {file : The SimplyFatt "Clienti" export (TSV, Windows-1252)}
        {--pec= : Optional TSV "codice<TAB>pec" with the customers\' PEC addresses}
        {--write : Write to the database; without it nothing is changed}';

    protected $description = 'Esalogica: import the customers exported from SimplyFatt (dry run unless --write)';

    public function handle(SimplyFattClientImporter $importer): int
    {
        $file = (string) $this->argument('file');
        if (! is_readable($file)) {
            $this->error("Cannot read {$file}");

            return self::FAILURE;
        }

        try {
            $lines = $importer->run($file, $this->option('pec'), (bool) $this->option('write'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($lines as $line) {
            $this->line($line);
        }
        if (! $this->option('write')) {
            $this->line('Aggiungi --write per importare.');
        }

        return self::SUCCESS;
    }
}
