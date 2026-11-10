<?php

namespace Modules\Addons\Pec;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PecController extends Controller
{
    /** One certified message per recipient; a refused one does not stop the rest. */
    public function send(Request $request, PecMailer $mailer): RedirectResponse
    {
        $validated = $request->validate([
            'recipients' => ['required', 'string', 'max:20000'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:50000'],
            'attachments' => ['array', 'max:5'],
            'attachments.*' => ['file', 'max:20480'],
        ]);

        $recipients = array_values(array_unique(array_filter(array_map(
            fn ($address) => strtolower(trim($address)),
            preg_split('/[\s,;]+/', $validated['recipients']) ?: [],
        ))));

        $sent = 0;
        $errors = [];
        foreach ($recipients as $recipient) {
            try {
                $mailer->send($recipient, $validated['subject'], $validated['body'], $request->file('attachments', []), auth('admin')->id());
                $sent++;
            } catch (\Throwable $e) {
                $errors[] = $recipient.': '.$e->getMessage();
            }
        }

        $redirect = back()->with('success', __('messages.pec.sent_count', ['count' => $sent]));

        return $errors === [] ? $redirect : $redirect->with('error', implode(' | ', $errors));
    }

    public function refresh(PecReceipts $receipts): RedirectResponse
    {
        try {
            return back()->with('success', __('messages.pec.receipts_updated', ['count' => $receipts->refresh()]));
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** A receipt as the provider sent it: the legal proof of sending or delivery. */
    public function receipt(PecMessage $message, int $index): StreamedResponse
    {
        $receipt = ($message->receipts ?? [])[$index] ?? null;
        abort_unless($receipt && Storage::disk('local')->exists($receipt['path']), 404);

        return Storage::disk('local')->download($receipt['path'], basename($receipt['path']), ['Content-Type' => 'message/rfc822']);
    }
}
