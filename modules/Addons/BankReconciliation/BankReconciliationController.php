<?php

namespace Modules\Addons\BankReconciliation;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class BankReconciliationController extends Controller
{
    public function __construct(private BankReconciler $reconciler) {}

    public function sync(): RedirectResponse
    {
        try {
            $counts = $this->reconciler->sync();
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('messages.bank_reconciliation.synced', $counts));
    }

    public function record(Request $request, BankTransaction $bankTransaction): RedirectResponse
    {
        $validated = $request->validate([
            'invoice_ids' => ['nullable', 'array'],
            'invoice_ids.*' => ['integer'],
            'invoice_number' => ['nullable', 'string', 'max:100'],
        ]);

        $ids = array_map('intval', $validated['invoice_ids'] ?? []);
        if (filled($validated['invoice_number'] ?? null)) {
            $typed = Invoice::where('invoice_num', trim($validated['invoice_number']))->value('id');
            if ($typed === null) {
                return back()->with('error', __('messages.bank_reconciliation.invoice_not_found', ['number' => $validated['invoice_number']]));
            }
            $ids[] = (int) $typed;
        }

        $result = $this->reconciler->payOnto($bankTransaction, array_values(array_unique($ids)), $this->by());

        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    public function ignore(BankTransaction $bankTransaction): RedirectResponse
    {
        $this->reconciler->ignore($bankTransaction, $this->by());

        return back()->with('success', __('messages.bank_reconciliation.ignored'));
    }

    private function by(): string
    {
        return auth('admin')->user()?->full_name ?: 'admin';
    }
}
