<?php

namespace Modules\Addons\BankReconciliation;

use App\Contracts\AddonModuleInterface;
use App\Models\Invoice;
use App\Services\PaymentService;
use Illuminate\Http\Request;

/**
 * Bank reconciliation: incoming transfers read from a Qonto business account
 * and paid onto the invoices they name; the doubtful ones wait for staff.
 */
class BankReconciliationModule implements AddonModuleInterface
{
    public function getName(): string
    {
        return BankReconciliationSettings::ADDON;
    }

    public function getDisplayName(): string
    {
        return __('messages.bank_reconciliation.title');
    }

    public function getDescription(): string
    {
        return __('messages.bank_reconciliation.description');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getAuthor(): string
    {
        return 'PNLCS';
    }

    /** @return array<int, array{version:string, date:string, changes:list<string>}> */
    public function changelog(): array
    {
        return [['version' => '1.0.0', 'date' => '2026-11-12', 'changes' => ['Incoming transfers read from Qonto every 15 minutes and matched to invoices by number or payment reference; doubtful ones wait for staff.']]];
    }

    /** @return array{success: bool, message: string} */
    public function activate(): array
    {
        return ['success' => true, 'message' => __('messages.bank_reconciliation.activated')];
    }

    /** @return array{success: bool, message: string} */
    public function deactivate(): array
    {
        return ['success' => true, 'message' => __('messages.bank_reconciliation.deactivated')];
    }

    /** @return array<int, array<string, mixed>> */
    public function config(): array
    {
        return BankReconciliationSettings::fields();
    }

    /** @return array<int, array<string, mixed>> */
    public function sidebar(): array
    {
        return [];
    }

    /** @return array{success: bool, message: string} */
    public function upgrade(string $fromVersion): array
    {
        return ['success' => true, 'message' => 'Bank reconciliation upgraded from '.e($fromVersion)];
    }

    public function output(Request $request): string
    {
        $t = fn (string $key, array $replace = []): string => e(__('messages.bank_reconciliation.'.$key, $replace));

        if (! BankReconciliationSettings::configured()) {
            return '<p style="font-size:13px;color:var(--pn-muted);margin:0;">'.$t('not_configured').'</p>';
        }

        $token = '<input type="hidden" name="_token" value="'.csrf_token().'">';
        $html = '<form method="POST" action="'.route('admin.bank_reconciliation.sync').'" style="margin:0 0 14px;">'.$token
            .'<button type="submit" class="btn btn-primary btn-sm" style="font-size:13px;padding:6px 14px;font-weight:600;">'.$t('sync_now').'</button> '
            .'<span style="font-size:12px;color:var(--pn-muted);">'.$t(BankReconciliationSettings::resolve()['auto_apply'] === '1' ? 'mode_auto' : 'mode_review').'</span></form>';

        $review = BankTransaction::where('status', BankTransaction::REVIEW)->orderBy('booked_at')->limit(100)->get();
        $done = BankTransaction::whereIn('status', [BankTransaction::MATCHED, BankTransaction::IGNORED])->latest('matched_at')->limit(20)->get();
        $ids = $review->flatMap(fn (BankTransaction $row) => $row->suggested_invoice_ids ?? [])
            ->merge($done->flatMap(fn (BankTransaction $row) => $row->invoice_ids ?? []))->unique()->all();
        $invoices = Invoice::with('client')->whereIn('id', $ids)->get()->keyBy('id');
        $payments = app(PaymentService::class);

        $html .= '<h4 style="font-size:14px;margin:6px 0;">'.$t('to_review', ['count' => $review->count()]).'</h4>';
        $rows = '';
        foreach ($review as $row) {
            $choices = '';
            foreach ($row->suggested_invoice_ids ?? [] as $id) {
                $invoice = $invoices->get($id);
                if (! $invoice) {
                    continue;
                }
                $checked = in_array($row->reason, [BankTransaction::REASON_READY, BankTransaction::REASON_AMOUNT], true) ? ' checked' : '';
                $choices .= '<label style="display:block;font-weight:normal;"><input type="checkbox" name="invoice_ids[]" value="'.$invoice->id.'"'.$checked.'> '
                    .e($invoice->invoice_num).' · '.e($this->clientName($invoice)).' · '.e(number_format($payments->balance($invoice), 2, ',', '.')).' ('.e((string) $invoice->status).')</label>';
            }
            $rows .= '<tr><td>'.e($row->booked_at?->format('d/m/Y')).'</td>'
                .'<td>'.e((string) $row->counterparty).($row->counterparty_iban ? '<br><small>'.e($row->counterparty_iban).'</small>' : '').'</td>'
                .'<td style="text-align:right;white-space:nowrap;">'.e(number_format((float) $row->amount, 2, ',', '.')).'</td>'
                .'<td>'.e((string) $row->reference).'<br><small style="color:var(--pn-muted);">'.$t('reason_'.($row->reason ?: BankTransaction::REASON_NO_REFERENCE)).'</small></td>'
                .'<td><form method="POST" action="'.route('admin.bank_reconciliation.record', ['bankTransaction' => $row->id]).'" style="margin:0;">'.$token.$choices
                .'<input type="text" name="invoice_number" class="form-control" placeholder="'.$t('other_invoice').'" style="font-size:12px;margin:4px 0;max-width:180px;">'
                .'<button type="submit" class="btn btn-primary btn-xs">'.$t('confirm').'</button></form>'
                .'<form method="POST" action="'.route('admin.bank_reconciliation.ignore', ['bankTransaction' => $row->id]).'" style="margin:4px 0 0;">'.$token
                .'<button type="submit" class="btn btn-default btn-xs">'.$t('ignore').'</button></form></td></tr>';
        }
        $html .= $this->table([$t('date'), $t('counterparty'), $t('amount'), $t('reference'), $t('pays')], $rows, $t('nothing_to_review'));

        $html .= '<h4 style="font-size:14px;margin:18px 0 6px;">'.$t('recent').'</h4>';
        $rows = '';
        foreach ($done as $row) {
            $paid = collect($row->invoice_ids ?? [])->map(fn ($id) => $invoices->get($id)?->invoice_num)->filter()->implode(', ');
            $rows .= '<tr><td>'.e($row->booked_at?->format('d/m/Y')).'</td><td>'.e((string) $row->counterparty).'</td>'
                .'<td style="text-align:right;">'.e(number_format((float) $row->amount, 2, ',', '.')).'</td>'
                .'<td>'.($row->status === BankTransaction::IGNORED ? $t('status_ignored') : e($paid)).'</td><td>'.e((string) $row->matched_by).'</td></tr>';
        }

        return $html.$this->table([$t('date'), $t('counterparty'), $t('amount'), $t('invoices'), $t('by')], $rows, $t('nothing_yet'));
    }

    /** @param list<string> $headings */
    private function table(array $headings, string $rows, string $empty): string
    {
        $head = implode('', array_map(fn (string $heading) => '<th>'.$heading.'</th>', $headings));

        return '<table class="table" style="font-size:13px;"><thead><tr>'.$head.'</tr></thead><tbody>'
            .($rows !== '' ? $rows : '<tr><td colspan="'.count($headings).'">'.$empty.'</td></tr>').'</tbody></table>';
    }

    private function clientName(Invoice $invoice): string
    {
        $client = $invoice->client;

        return $client ? ($client->company_name ?: trim($client->first_name.' '.$client->last_name)) : '';
    }
}
