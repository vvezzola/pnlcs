<?php

namespace Modules\Addons\FatturaPA;

use App\Contracts\AddonModuleInterface;
use Illuminate\Http\Request;

/**
 * Italian e-invoices (FatturaPA).
 *
 * Exports issued VAT invoices as FatturaPA XML files, checked against the
 * official schema, for whoever transmits them to the SDI: an e-invoicing
 * service such as Aruba's, an accountant, or another accounting program.
 * Sending to the SDI is not part of this addon.
 */
class FatturaPAModule implements AddonModuleInterface
{
    public function getName(): string
    {
        return 'fatturapa';
    }

    public function getDisplayName(): string
    {
        return __('messages.fatturapa.name');
    }

    public function getDescription(): string
    {
        return __('messages.fatturapa.description');
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
        return [
            ['version' => '1.0.0', 'date' => '2026-11-10', 'changes' => ['Export of VAT invoices as FatturaPA FPR12 XML, one file or a ZIP for a date range.']],
        ];
    }

    /** @return array{success: bool, message: string} */
    public function activate(): array
    {
        return ['success' => true, 'message' => __('messages.fatturapa.activated')];
    }

    /** @return array{success: bool, message: string} */
    public function deactivate(): array
    {
        return ['success' => true, 'message' => __('messages.fatturapa.deactivated')];
    }

    /** @return array<int, array<string, mixed>> */
    public function config(): array
    {
        return FatturaPASettings::fields();
    }

    /** @return array<int, array<string, mixed>> */
    public function sidebar(): array
    {
        return [];
    }

    /** @return array{success: bool, message: string} */
    public function upgrade(string $fromVersion): array
    {
        return ['success' => true, 'message' => 'FatturaPA upgraded from '.e($fromVersion)];
    }

    public function output(Request $request): string
    {
        $token = '<input type="hidden" name="_token" value="'.csrf_token().'">';
        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();
        $t = fn (string $key): string => e(__('messages.fatturapa.'.$key));

        return '<form method="POST" action="'.route('admin.fatturapa.export').'" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin:0;">'.$token
            .'<label style="font-size:13px;">'.$t('from').'<br><input type="date" name="from" value="'.$from.'" class="form-control" required></label>'
            .'<label style="font-size:13px;">'.$t('to').'<br><input type="date" name="to" value="'.$to.'" class="form-control" required></label>'
            .'<button type="submit" class="btn btn-primary btn-sm" style="font-size:13px;padding:6px 14px;font-weight:600;">'.$t('download_zip').'</button>'
            .'</form>'
            .'<form method="GET" action="'.route('admin.fatturapa.single').'" style="display:flex;gap:8px;align-items:end;margin:12px 0 0;">'
            .'<label style="font-size:13px;">'.$t('number').'<br><input type="text" name="number" class="form-control" required></label>'
            .'<button type="submit" class="btn btn-secondary btn-sm" style="font-size:13px;padding:6px 14px;">'.$t('download_xml').'</button>'
            .'</form>'
            .'<p style="font-size:13px;color:var(--pn-muted);margin:10px 0 0;">'.$t('note').'</p>';
    }
}
