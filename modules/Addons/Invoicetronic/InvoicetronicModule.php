<?php

namespace Modules\Addons\Invoicetronic;

use App\Contracts\AddonModuleInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Italian e-invoices sent to the SDI through Invoicetronic. The file is the
 * FatturaPA addon's; this addon posts it, follows the SDI's answer, and lists
 * the invoices that were refused so staff can fix and resend them.
 */
class InvoicetronicModule implements AddonModuleInterface
{
    public function getName(): string
    {
        return InvoicetronicSettings::ADDON;
    }

    public function getDisplayName(): string
    {
        return __('messages.invoicetronic.title');
    }

    public function getDescription(): string
    {
        return __('messages.invoicetronic.description');
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
        return [['version' => '1.0.0', 'date' => '2026-11-12', 'changes' => ['Issued invoices sent to the SDI through Invoicetronic every 15 minutes; outcomes read back, refusals listed for staff to fix and resend.']]];
    }

    /** @return array{success: bool, message: string} */
    public function activate(): array
    {
        return ['success' => true, 'message' => __('messages.invoicetronic.activated')];
    }

    /** @return array{success: bool, message: string} */
    public function deactivate(): array
    {
        return ['success' => true, 'message' => __('messages.invoicetronic.deactivated')];
    }

    /** @return array<int, array<string, mixed>> */
    public function config(): array
    {
        return InvoicetronicSettings::fields();
    }

    /** @return array<int, array<string, mixed>> */
    public function sidebar(): array
    {
        return [];
    }

    /** @return array{success: bool, message: string} */
    public function upgrade(string $fromVersion): array
    {
        return ['success' => true, 'message' => 'Invoicetronic upgraded from '.e($fromVersion)];
    }

    public function output(Request $request): string
    {
        $t = fn (string $key, array $replace = []): string => e(__('messages.invoicetronic.'.$key, $replace));

        if (! InvoicetronicSettings::configured()) {
            return '<p style="font-size:13px;color:var(--pn-muted);margin:0;">'.$t('not_configured').'</p>';
        }

        $settings = InvoicetronicSettings::resolve();
        $from = InvoicetronicSettings::sendFrom();
        $mode = match (true) {
            $settings['auto_send'] !== '1' => $t('mode_manual'),
            $from === null => $t('mode_no_start'),
            default => $t('mode_auto', ['date' => $from->format('d/m/Y'), 'minutes' => InvoicetronicSettings::delayMinutes()]),
        };
        $environment = InvoicetronicSettings::isSandbox()
            ? '<strong style="color:var(--pn-warning, #b45309);">'.$t('environment_sandbox').'</strong>'
            : '<strong>'.$t('environment_live').'</strong>';

        $token = '<input type="hidden" name="_token" value="'.csrf_token().'">';
        $html = '<p style="font-size:13px;margin:0 0 10px;">'.$environment.' · '.$mode.'</p>'
            .'<div style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;margin:0 0 14px;">'
            .'<form method="POST" action="'.route('admin.invoicetronic.sync').'" style="margin:0;">'.$token
            .'<button type="submit" class="btn btn-primary btn-sm" style="font-size:13px;padding:6px 14px;font-weight:600;">'.$t('sync_now').'</button></form>'
            .'<form method="POST" action="'.route('admin.invoicetronic.send').'" style="display:flex;gap:8px;align-items:end;margin:0;">'.$token
            .'<label style="font-size:13px;">'.$t('number').'<br><input type="text" name="number" class="form-control" required></label>'
            .'<button type="submit" class="btn btn-secondary btn-sm" style="font-size:13px;padding:6px 14px;">'.$t('send_now').'</button></form></div>';

        $attention = $this->latestPerInvoice()->filter(fn (SdiSubmission $row) => in_array($row->state, SdiSubmission::NEEDS_ACTION, true));
        $html .= '<h4 style="font-size:14px;margin:6px 0;">'.$t('to_fix', ['count' => $attention->count()]).'</h4>'
            .$this->table($attention, $t, $t('nothing_to_fix'), $token);

        $recent = SdiSubmission::with('invoice')->latest('id')->limit(30)->get();

        return $html.'<h4 style="font-size:14px;margin:18px 0 6px;">'.$t('recent').'</h4>'
            .$this->table($recent, $t, $t('nothing_yet'));
    }

    /**
     * The last attempt of each invoice that has one; an invoice fixed and
     * sent again is judged by its new attempt.
     *
     * @return Collection<int, SdiSubmission>
     */
    private function latestPerInvoice(): Collection
    {
        $ids = SdiSubmission::selectRaw('MAX(id) as id')->groupBy('invoice_id')->pluck('id');

        return SdiSubmission::with('invoice')->whereIn('id', $ids)
            ->whereIn('state', SdiSubmission::NEEDS_ACTION)->orderBy('id')->limit(200)->get();
    }

    /**
     * @param  Collection<int, SdiSubmission>  $rows
     * @param  callable(string, array<string, mixed>=): string  $t
     */
    private function table(Collection $rows, callable $t, string $empty, ?string $resendToken = null): string
    {
        $headings = [$t('invoice'), $t('buyer'), $t('state'), $t('when'), $t('details')];
        if ($resendToken !== null) {
            $headings[] = '';
        }

        $body = '';
        foreach ($rows as $row) {
            $invoice = $row->invoice;
            $number = (string) ($invoice?->invoice_num ?? '#'.$row->invoice_id);
            $buyer = $invoice ? trim((string) ($invoice->buyer('company_name') ?: $invoice->buyer('first_name').' '.$invoice->buyer('last_name'))) : '';
            $details = $row->message !== null ? e($row->message) : '';
            if ($row->sdi_identifier) {
                $details .= ($details !== '' ? '<br>' : '').'<small style="color:var(--pn-muted);">SDI '.e($row->sdi_identifier).'</small>';
            }

            $body .= '<tr><td style="white-space:nowrap;">'.e($number).'</td><td>'.e($buyer).'</td>'
                .'<td style="white-space:nowrap;">'.$this->stateLabel($row->state, $t).'</td>'
                .'<td style="white-space:nowrap;">'.e(($row->state_at ?? $row->created_at)?->format('d/m/Y H:i')).'</td>'
                .'<td style="font-size:12px;">'.$details.'</td>';
            if ($resendToken !== null) {
                $body .= '<td><form method="POST" action="'.route('admin.invoicetronic.send').'" style="margin:0;">'.$resendToken
                    .'<input type="hidden" name="number" value="'.e($number).'">'
                    .'<button type="submit" class="btn btn-primary btn-xs">'.$t('send_again').'</button></form></td>';
            }
            $body .= '</tr>';
        }

        $head = implode('', array_map(fn (string $heading) => '<th>'.$heading.'</th>', $headings));

        return '<table class="table" style="font-size:13px;"><thead><tr>'.$head.'</tr></thead><tbody>'
            .($body !== '' ? $body : '<tr><td colspan="'.count($headings).'">'.$empty.'</td></tr>').'</tbody></table>';
    }

    /** @param  callable(string, array<string, mixed>=): string  $t */
    private function stateLabel(string $state, callable $t): string
    {
        $key = 'state_'.strtolower($state);
        $label = __('messages.invoicetronic.'.$key);

        return $label === 'messages.invoicetronic.'.$key ? e($state) : $t($key);
    }
}
