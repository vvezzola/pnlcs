<?php

namespace Modules\Addons\FatturaPA;

use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

class FatturaPAController extends Controller
{
    /** One invoice, looked up by its number, as an XML download. */
    public function single(Request $request, FatturaPABuilder $builder): Response|RedirectResponse
    {
        $validated = $request->validate(['number' => ['required', 'string', 'max:100']]);
        $invoice = Invoice::where('invoice_num', $validated['number'])->first();

        if (! $invoice) {
            return back()->with('error', __('messages.fatturapa.no_invoice', ['number' => $validated['number']]));
        }

        try {
            $xml = $builder->build($invoice);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="'.$builder->fileName($invoice).'"',
        ]);
    }

    /**
     * Every VAT invoice dated within the range, in one ZIP. An invoice that
     * cannot be built stays out, with its reason in ERRORI.txt, so one bad
     * customer record does not hold back the month.
     */
    public function export(Request $request, FatturaPABuilder $builder): BinaryFileResponse|RedirectResponse
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
            return back()->with('error', __('messages.fatturapa.none_in_period'));
        }

        $path = tempnam(sys_get_temp_dir(), 'fatturapa');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $errors = [];

        foreach ($invoices as $invoice) {
            try {
                $zip->addFromString($builder->fileName($invoice), $builder->build($invoice));
            } catch (\RuntimeException $e) {
                $errors[] = $invoice->invoice_num.': '.$e->getMessage();
            }
        }

        if ($errors !== []) {
            $zip->addFromString('ERRORI.txt', implode("\n", $errors)."\n");
        }
        $zip->close();

        return response()
            ->download($path, 'fatturapa_'.$validated['from'].'_'.$validated['to'].'.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }
}
