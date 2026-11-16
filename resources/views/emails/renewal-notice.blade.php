<!DOCTYPE html>
<html><head><meta charset="utf-8"></head>
<body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;">
<h2 style="color:#405189;">{{ $companyName }}</h2>

<p>{{ __('email.common.greeting', ['name' => $client->first_name ?: __('email.common.customer')]) }}</p>

<p>{{ __('email.renewal_notice.intro', ['date' => $billingDate]) }}</p>

<table style="width:100%;border-collapse:collapse;margin:20px 0;">
<tr>
<th style="padding:8px;border-bottom:2px solid #ddd;text-align:left;">{{ __('email.renewal_notice.col_item') }}</th>
<th style="padding:8px;border-bottom:2px solid #ddd;text-align:left;white-space:nowrap;">{{ __('email.renewal_notice.col_due') }}</th>
<th style="padding:8px;border-bottom:2px solid #ddd;text-align:right;">{{ __('email.renewal_notice.col_amount') }}</th>
</tr>
@foreach($lines as $line)
<tr>
<td style="padding:8px;border-bottom:1px solid #eee;">{{ empty($line['domain']) ? $line['description'] : __('email.renewal_notice.domain', ['domain' => $line['description']]) }}</td>
<td style="padding:8px;border-bottom:1px solid #eee;white-space:nowrap;">{{ $line['due'] }}</td>
<td style="padding:8px;border-bottom:1px solid #eee;text-align:right;white-space:nowrap;">{{ money_fmt($line['amount']) }}</td>
</tr>
@endforeach
<tr>
<td colspan="2" style="padding:8px;"><strong>{{ __('email.renewal_notice.total') }}</strong></td>
<td style="padding:8px;text-align:right;white-space:nowrap;"><strong>{{ money_fmt($total) }}</strong></td>
</tr>
</table>

@if($beforeTax)
<p style="color:#666;font-size:13px;">{{ __('email.renewal_notice.before_tax') }}</p>
@endif

<p>{{ __('email.renewal_notice.cancel', ['deadline' => $deadline]) }}</p>

<p>{{ __('email.renewal_notice.keep') }}</p>

@include('emails.partials.action', ['url' => route('client.services.index'), 'label' => __('email.renewal_notice.button')])

<p style="color:#888;font-size:12px;margin-top:30px;">{{ $companyName }}</p>
</body></html>
