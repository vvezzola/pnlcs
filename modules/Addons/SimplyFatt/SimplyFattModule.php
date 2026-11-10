<?php

namespace Modules\Addons\SimplyFatt;

use App\Contracts\AddonModuleInterface;
use Illuminate\Http\Request;

/**
 * Esalogica's own bridge to SimplyFatt: issued invoices as an SF-XML file
 * that SimplyFatt imports ("Importa documenti SF-XML") and then transmits
 * to the SDI as before. Kept out of upstream PNLCS.
 */
class SimplyFattModule implements AddonModuleInterface
{
    public function getName(): string
    {
        return 'simplyfatt';
    }

    public function getDisplayName(): string
    {
        return 'SimplyFatt (SF-XML)';
    }

    public function getDescription(): string
    {
        return 'Esporta le fatture di un periodo in formato SF-XML, da importare in SimplyFatt.';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getAuthor(): string
    {
        return 'Esalogica';
    }

    /** @return array<int, array{version:string, date:string, changes:list<string>}> */
    public function changelog(): array
    {
        return [['version' => '1.0.0', 'date' => '2026-11-10', 'changes' => ['SF-XML export of a period of VAT invoices.']]];
    }

    /** @return array{success: bool, message: string} */
    public function activate(): array
    {
        return ['success' => true, 'message' => 'SimplyFatt attivato.'];
    }

    /** @return array{success: bool, message: string} */
    public function deactivate(): array
    {
        return ['success' => true, 'message' => 'SimplyFatt disattivato.'];
    }

    /** @return array<int, array<string, mixed>> */
    public function config(): array
    {
        return SimplyFattSettings::fields();
    }

    /** @return array<int, array<string, mixed>> */
    public function sidebar(): array
    {
        return [];
    }

    /** @return array{success: bool, message: string} */
    public function upgrade(string $fromVersion): array
    {
        return ['success' => true, 'message' => 'SimplyFatt upgraded from '.e($fromVersion)];
    }

    public function output(Request $request): string
    {
        return '<form method="POST" action="'.route('admin.simplyfatt.export').'" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin:0;">'
            .'<input type="hidden" name="_token" value="'.csrf_token().'">'
            .'<label style="font-size:13px;">Dal<br><input type="date" name="from" value="'.now()->startOfMonth()->toDateString().'" class="form-control" required></label>'
            .'<label style="font-size:13px;">Al<br><input type="date" name="to" value="'.now()->toDateString().'" class="form-control" required></label>'
            .'<button type="submit" class="btn btn-primary btn-sm" style="font-size:13px;padding:6px 14px;font-weight:600;">Scarica SF-XML</button>'
            .'</form>'
            .'<p style="font-size:13px;color:var(--pn-muted);margin:10px 0 0;">In SimplyFatt: Importa documenti SF-XML. Solo fatture IVA, non le proforma.</p>';
    }
}
