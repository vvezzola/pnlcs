<?php

namespace Modules\Addons\Pec;

use App\Contracts\AddonModuleInterface;
use Illuminate\Http\Request;

/**
 * Certified email (PEC, Italy): send messages with legal value to clients
 * from the operator's PEC mailbox, and keep the provider's acceptance and
 * delivery receipts as proof.
 */
class PecModule implements AddonModuleInterface
{
    public function getName(): string
    {
        return 'pec';
    }

    public function getDisplayName(): string
    {
        return __('messages.pec.title');
    }

    public function getDescription(): string
    {
        return __('messages.pec.description');
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
        return [['version' => '1.0.0', 'date' => '2026-11-10', 'changes' => ['Send certified messages to one or many recipients; acceptance and delivery receipts read from the mailbox and kept.']]];
    }

    /** @return array{success: bool, message: string} */
    public function activate(): array
    {
        return ['success' => true, 'message' => __('messages.pec.activated')];
    }

    /** @return array{success: bool, message: string} */
    public function deactivate(): array
    {
        return ['success' => true, 'message' => __('messages.pec.deactivated')];
    }

    /** @return array<int, array<string, mixed>> */
    public function config(): array
    {
        return PecSettings::fields();
    }

    /** @return array<int, array<string, mixed>> */
    public function sidebar(): array
    {
        return [];
    }

    /** @return array{success: bool, message: string} */
    public function upgrade(string $fromVersion): array
    {
        return ['success' => true, 'message' => 'PEC upgraded from '.e($fromVersion)];
    }

    public function output(Request $request): string
    {
        if (! PecSettings::configured()) {
            return '<p style="font-size:13px;color:var(--pn-muted);margin:0;">'.e(__('messages.pec.not_configured')).'</p>';
        }

        $token = '<input type="hidden" name="_token" value="'.csrf_token().'">';
        $html = '<form method="POST" action="'.route('admin.pec.send').'" enctype="multipart/form-data" style="display:grid;gap:8px;max-width:720px;">'.$token
            .'<label style="font-size:13px;">'.e(__('messages.pec.recipients')).'<textarea name="recipients" rows="3" class="form-control" required placeholder="nome@pec.example.it"></textarea></label>'
            .'<label style="font-size:13px;">'.e(__('messages.pec.subject')).'<input type="text" name="subject" class="form-control" required maxlength="255"></label>'
            .'<label style="font-size:13px;">'.e(__('messages.pec.body')).'<textarea name="body" rows="8" class="form-control" required></textarea></label>'
            .'<label style="font-size:13px;">'.e(__('messages.pec.attachments')).'<input type="file" name="attachments[]" multiple class="form-control"></label>'
            .'<div><button type="submit" class="btn btn-primary btn-sm" style="font-size:13px;padding:6px 14px;font-weight:600;">'.e(__('messages.pec.send')).'</button></div>'
            .'</form>';

        $html .= '<form method="POST" action="'.route('admin.pec.refresh').'" style="margin:18px 0 8px;">'.$token
            .'<button type="submit" class="btn btn-secondary btn-sm" style="font-size:13px;">'.e(__('messages.pec.refresh')).'</button></form>';

        $rows = '';
        foreach (PecMessage::with('client')->latest('id')->limit(50)->get() as $message) {
            $links = [];
            foreach ($message->receipts ?? [] as $index => $receipt) {
                $links[] = '<a href="'.route('admin.pec.receipt', [$message, $index]).'">'.e($receipt['type']).'</a>';
            }
            $client = $message->client ? ($message->client->company_name ?: trim($message->client->first_name.' '.$message->client->last_name)) : '';
            $rows .= '<tr><td>'.e($message->created_at?->format('d/m/Y H:i')).'</td><td>'.e($message->recipient).'<br><small>'.e($client).'</small></td>'
                .'<td>'.e($message->subject).'</td><td>'.e($this->statusLabel($message->status)).($message->error ? '<br><small>'.e($message->error).'</small>' : '').'</td>'
                .'<td>'.implode(' · ', $links).'</td></tr>';
        }

        return $html.'<table class="table" style="font-size:13px;"><thead><tr><th>'.e(__('messages.pec.date')).'</th><th>'.e(__('messages.pec.recipient')).'</th><th>'
            .e(__('messages.pec.subject')).'</th><th>'.e(__('messages.pec.status')).'</th><th>'.e(__('messages.pec.receipts')).'</th></tr></thead><tbody>'
            .($rows !== '' ? $rows : '<tr><td colspan="5">'.e(__('messages.pec.none_sent')).'</td></tr>').'</tbody></table>';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            PecMessage::ACCEPTED => __('messages.pec.status_accepted'),
            PecMessage::DELIVERED => __('messages.pec.status_delivered'),
            PecMessage::FAILED => __('messages.pec.status_failed'),
            default => __('messages.pec.status_sent'),
        };
    }
}
