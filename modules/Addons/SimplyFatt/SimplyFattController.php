<?php

namespace Modules\Addons\SimplyFatt;

use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class SimplyFattController extends Controller
{
    /** Every VAT invoice dated within the range as one SF-XML import file. */
    public function export(Request $request, SimplyFattBuilder $builder): Response|RedirectResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $invoices = Invoice::query()
            ->whereBetween('date', [$validated['from'], $validated['to']])
            ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'proforma'))
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->orderBy('date')->orderBy('id')
            ->with('items', 'client')
            ->get();

        if ($invoices->isEmpty()) {
            return back()->with('error', 'Nessuna fattura in questo periodo.');
        }

        return response($builder->build($invoices), 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="simplyfatt_'.$validated['from'].'_'.$validated['to'].'.xml"',
        ]);
    }

    /** Session key of an uploaded customer export waiting for "Importa". */
    public const PENDING = 'simplyfatt_clients_pending';

    /** Session key of the report of the import just made. */
    public const DONE = 'simplyfatt_clients_done';

    private const DIR = 'simplyfatt-import';

    /**
     * The uploaded customer export, read without writing anything: the page
     * shows what an import would do, and keeps the files for "Importa".
     */
    public function previewClients(Request $request, SimplyFattClientImporter $importer): RedirectResponse
    {
        $request->validate([
            'clients' => ['required', 'file', 'max:5120'],
            'pec' => ['nullable', 'file', 'max:1024'],
        ]);
        $this->discardPending($request);

        $dir = self::DIR.'/'.Str::uuid();
        $clients = $request->file('clients')->storeAs($dir, 'clienti.txt', 'local');
        $pec = $request->file('pec')?->storeAs($dir, 'pec.tsv', 'local');

        try {
            $report = $importer->run(Storage::disk('local')->path($clients), $pec ? Storage::disk('local')->path($pec) : null, false);
        } catch (RuntimeException $e) {
            Storage::disk('local')->deleteDirectory($dir);

            return back()->with('error', $e->getMessage());
        }

        $request->session()->put(self::PENDING, [
            'dir' => $dir,
            'name' => $request->file('clients')->getClientOriginalName(),
            'with_pec' => $pec !== null,
            'report' => $report,
        ]);
        $request->session()->forget(self::DONE);

        return back();
    }

    /** Imports the export previewed last, then deletes the uploaded files. */
    public function importClients(Request $request, SimplyFattClientImporter $importer): RedirectResponse
    {
        $pending = $request->session()->get(self::PENDING);
        $disk = Storage::disk('local');
        if (! is_array($pending) || ! $disk->exists($pending['dir'].'/clienti.txt')) {
            $request->session()->forget(self::PENDING);

            return back()->with('error', 'Nessun file in attesa: carica di nuovo l\'esportazione dei clienti.');
        }

        $pec = $pending['with_pec'] ? $disk->path($pending['dir'].'/pec.tsv') : null;
        try {
            $report = $importer->run($disk->path($pending['dir'].'/clienti.txt'), $pec, true);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->discardPending($request);
        $request->session()->put(self::DONE, $report);

        return back()->with('success', 'Clienti importati da SimplyFatt.');
    }

    public function cancelClients(Request $request): RedirectResponse
    {
        $this->discardPending($request);

        return back();
    }

    private function discardPending(Request $request): void
    {
        $pending = $request->session()->pull(self::PENDING);
        if (is_array($pending) && isset($pending['dir'])) {
            Storage::disk('local')->deleteDirectory($pending['dir']);
        }
    }
}
