<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Addons\FatturaPA\FatturaPASettings;

/**
 * Esalogica only: the SDI recipient field used to hold either a recipient
 * code or a PEC address. The PEC addresses move to the PEC field, so the
 * recipient field keeps codes only.
 *
 * An address moves when the client's PEC field is empty or already says the
 * same; a different PEC there is listed and both are left as they are. Dry
 * run unless --write; running it again changes nothing.
 */
class SplitSdiPecCommand extends Command
{
    protected $signature = 'esalogica:split-sdi-pec {--write : Write to the database; without it nothing is changed}';

    protected $description = 'Esalogica: move the PEC addresses out of the SDI recipient field into the PEC field (dry run unless --write)';

    public function handle(): int
    {
        $sdi = CustomField::where('type', 'client')->where('field_name', FatturaPASettings::DEFAULT_RECIPIENT_FIELD)->first();
        if (! $sdi) {
            $this->error('Il campo "'.FatturaPASettings::DEFAULT_RECIPIENT_FIELD.'" non esiste.');

            return self::FAILURE;
        }

        $write = (bool) $this->option('write');
        $done = ['spostate' => 0, 'già uguali' => 0, 'diverse, lasciate' => 0];

        DB::transaction(function () use ($sdi, $write, &$done) {
            $pec = CustomField::firstOrCreate(
                ['type' => 'client', 'field_name' => FatturaPASettings::DEFAULT_PEC_FIELD],
                ['rel_id' => 0, 'field_type' => 'text', 'description' => 'Indirizzo PEC del cliente, per gli invii certificati', 'admin_only' => false,
                    'required' => false, 'sort_order' => 3, 'show_on_invoice' => false, 'show_on_order' => false],
            );

            $rows = CustomFieldValue::where('field_id', $sdi->id)->where('value', 'like', '%@%')->orderBy('rel_id')->get();
            foreach ($rows as $row) {
                $address = strtolower(trim((string) $row->value));
                $current = CustomFieldValue::where('field_id', $pec->id)->where('rel_id', $row->rel_id)->first();
                $existing = strtolower(trim((string) $current?->value));
                $name = Client::find($row->rel_id)?->company_name ?: "#{$row->rel_id}";

                if ($existing !== '' && $existing !== $address) {
                    $done['diverse, lasciate']++;
                    $this->line("  diverse, lasciate: cliente #{$row->rel_id} {$name}: SDI {$address}, PEC {$existing}");

                    continue;
                }

                $key = $existing === $address ? 'già uguali' : 'spostate';
                $done[$key]++;
                $this->line("  {$key}: cliente #{$row->rel_id} {$name}: {$address}");

                if ($write) {
                    if ($current === null) {
                        CustomFieldValue::create(['field_id' => $pec->id, 'rel_id' => $row->rel_id, 'value' => $address]);
                    }
                    $row->update(['value' => '']);
                }
            }
        });

        foreach ($done as $what => $count) {
            $this->line(str_pad((string) $count, 5, ' ', STR_PAD_LEFT)."  {$what}");
        }
        $this->line($write ? 'Fatto.' : 'Prova: nulla è stato scritto. Aggiungi --write per spostarle.');

        return self::SUCCESS;
    }
}
