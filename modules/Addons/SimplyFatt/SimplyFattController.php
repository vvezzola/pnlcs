<?php

namespace Modules\Addons\SimplyFatt;

use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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
}
