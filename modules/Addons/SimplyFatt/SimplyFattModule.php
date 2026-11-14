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
        return '1.1.0';
    }

    public function getAuthor(): string
    {
        return 'Esalogica';
    }

    /** @return array<int, array{version:string, date:string, changes:list<string>}> */
    public function changelog(): array
    {
        return [
            ['version' => '1.1.0', 'date' => '2026-11-13', 'changes' => ['Customer import from the SimplyFatt "Clienti" export, with preview.']],
            ['version' => '1.0.0', 'date' => '2026-11-10', 'changes' => ['SF-XML export of a period of VAT invoices.']],
        ];
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
            .'<p style="font-size:13px;color:var(--pn-muted);margin:10px 0 0;">In SimplyFatt: Importa documenti SF-XML. Solo fatture IVA, non le proforma.</p>'
            .$this->clientsSection($request);
    }

    /**
     * Customers from SimplyFatt: upload the "Clienti" export, look at the
     * preview, then import. The rules are SimplyFattClientImporter's.
     */
    private function clientsSection(Request $request): string
    {
        $token = '<input type="hidden" name="_token" value="'.csrf_token().'">';
        $html = '<h4 style="font-size:14px;margin:22px 0 6px;">Importa clienti da SimplyFatt</h4>'
            .'<p style="font-size:13px;color:var(--pn-muted);margin:0 0 10px;">In SimplyFatt: Clienti, Esporta, file di testo. I clienti gi&agrave; importati vengono aggiornati, non duplicati (li riconosce dal codice SimplyFatt). '
            .'Non vengono creati accessi n&eacute; inviate email.</p>';

        $pending = $request->session()->get(SimplyFattController::PENDING);
        if (is_array($pending)) {
            return $html.'<p style="font-size:13px;margin:0 0 6px;"><strong>Anteprima di '.e($pending['name']).'</strong>'.($pending['with_pec'] ? ' con il file PEC' : '').'</p>'
                .$this->report($pending['report'])
                .'<div style="display:flex;gap:8px;margin-top:10px;">'
                .'<form method="POST" action="'.route('admin.simplyfatt.clients.import').'" style="margin:0;">'.$token
                .'<button type="submit" class="btn btn-primary btn-sm" style="font-size:13px;padding:6px 14px;font-weight:600;">Importa questi clienti</button></form>'
                .'<form method="POST" action="'.route('admin.simplyfatt.clients.cancel').'" style="margin:0;">'.$token
                .'<button type="submit" class="btn btn-default btn-sm" style="font-size:13px;padding:6px 14px;">Annulla</button></form>'
                .'</div>';
        }

        $done = $request->session()->get(SimplyFattController::DONE);
        if (is_array($done)) {
            $html .= $this->report($done);
        }

        return $html.'<form method="POST" enctype="multipart/form-data" action="'.route('admin.simplyfatt.clients.preview').'" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;margin:10px 0 0;">'.$token
            .'<label style="font-size:13px;">Esportazione clienti (.txt)<br><input type="file" name="clients" accept=".txt,.tsv,.csv" required class="form-control"></label>'
            .'<label style="font-size:13px;">PEC per codice cliente (facoltativo)<br><input type="file" name="pec" accept=".txt,.tsv" class="form-control"></label>'
            .'<button type="submit" class="btn btn-secondary btn-sm" style="font-size:13px;padding:6px 14px;">Anteprima</button>'
            .'</form>';
    }

    /** @param list<string> $lines */
    private function report(array $lines): string
    {
        return '<pre style="font-size:12px;max-height:420px;overflow:auto;background:var(--pn-surface-2, #f6f6f6);padding:10px;border-radius:6px;white-space:pre-wrap;">'
            .e(implode("\n", $lines)).'</pre>';
    }
}
