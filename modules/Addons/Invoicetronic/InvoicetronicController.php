<?php

namespace Modules\Addons\Invoicetronic;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class InvoicetronicController extends Controller
{
    public function __construct(private SdiSender $sender) {}

    /** The scheduled pass, run now. */
    public function sync(): RedirectResponse
    {
        try {
            $counts = $this->sender->run();
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('messages.invoicetronic.synced', $counts));
    }

    /** One invoice, by its number: sent for the first time, or again after a refusal. */
    public function send(Request $request): RedirectResponse
    {
        $validated = $request->validate(['number' => ['required', 'string', 'max:100']]);
        $invoice = Invoice::where('invoice_num', trim($validated['number']))->first();

        if ($invoice === null) {
            return back()->with('error', __('messages.invoicetronic.invoice_not_found', ['number' => $validated['number']]));
        }

        try {
            $submission = $this->sender->send($invoice, auth('admin')->user()?->full_name ?: 'admin');
        } catch (InvoicetronicUnavailable $e) {
            return back()->with('error', __('messages.invoicetronic.unavailable', ['reason' => $e->getMessage()]));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $submission->state === SdiSubmission::ERROR
            ? back()->with('error', __('messages.invoicetronic.refused', ['number' => $invoice->invoice_num, 'reason' => (string) $submission->message]))
            : back()->with('success', __('messages.invoicetronic.sent', ['number' => $invoice->invoice_num]));
    }
}
