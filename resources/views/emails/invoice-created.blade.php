<!DOCTYPE html>
<html><head><meta charset="utf-8"></head>
<body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;">
<h2 style="color:#405189;">{{ $companyName }}</h2>

<p>{{ __('email.common.greeting', ['name' => $invoice->client?->first_name ?? __('email.common.customer')]) }}</p>

<p>{{ __('email.invoice_created.generated') }}</p>

<table style="width:100%;border-collapse:collapse;margin:20px 0;">
<tr><td style="padding:8px;border-bottom:1px solid #eee;"><strong>{{ __('email.common.invoice_number_label') }}</strong></td><td style="padding:8px;border-bottom:1px solid #eee;">{{ $invoice->invoice_num ?? $invoice->id }}</td></tr>
<tr><td style="padding:8px;border-bottom:1px solid #eee;"><strong>{{ __('email.invoice_created.amount') }}</strong></td><td style="padding:8px;border-bottom:1px solid #eee;">{{ dual_money_fmt($invoice->total, $invoice) }}</td></tr>
<tr><td style="padding:8px;border-bottom:1px solid #eee;"><strong>{{ __('email.common.due_date_label') }}</strong></td><td style="padding:8px;border-bottom:1px solid #eee;">{{ $invoice->due_date?->format(date_fmt()) ?? 'N/A' }}</td></tr>
<tr><td style="padding:8px;border-bottom:1px solid #eee;"><strong>{{ __('email.common.status_label') }}</strong></td><td style="padding:8px;border-bottom:1px solid #eee;">{{ invoice_status_label($invoice->status) }}</td></tr>
</table>

@php($transfer = app(\App\Services\InvoicePdfService::class)->transferDetails($invoice))
@if($transfer)
<p><strong>{{ __('messages.banktransfer.details_title') }}</strong></p>
<table style="width:100%;border-collapse:collapse;margin:0 0 20px;">
<tr><td style="padding:6px 8px;border-bottom:1px solid #eee;">{{ __('messages.banktransfer.account_name') }}</td><td style="padding:6px 8px;border-bottom:1px solid #eee;">{{ $transfer['holder'] }}</td></tr>
@foreach($transfer['accounts'] as $account)
@if(($account['iban'] ?? '') !== '')
<tr><td style="padding:6px 8px;border-bottom:1px solid #eee;">{{ __('messages.banktransfer.iban') }}</td><td style="padding:6px 8px;border-bottom:1px solid #eee;"><strong>{{ $account['iban'] }}</strong>@if(($account['name'] ?? '') !== '') ({{ $account['name'] }})@endif</td></tr>
@elseif(($account['account_number'] ?? '') !== '')
<tr><td style="padding:6px 8px;border-bottom:1px solid #eee;">{{ __('messages.banktransfer.account_number') }}</td><td style="padding:6px 8px;border-bottom:1px solid #eee;"><strong>{{ $account['account_number'] }}</strong>@if(($account['name'] ?? '') !== '') ({{ $account['name'] }})@endif</td></tr>
@endif
@endforeach
<tr><td style="padding:6px 8px;border-bottom:1px solid #eee;">{{ __('messages.banktransfer.reference') }}</td><td style="padding:6px 8px;border-bottom:1px solid #eee;"><strong>{{ $transfer['reference'] }}</strong></td></tr>
</table>
@endif

<p>{{ __('email.common.login_link') }}</p>

@include('emails.partials.action', ['url' => route('client.invoices.show', $invoice->id), 'label' => __('email.common.view_invoice')])

<p style="color:#888;font-size:12px;margin-top:30px;">{{ $companyName }}</p>
</body></html>