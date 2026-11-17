<!DOCTYPE html>
<html><head><meta charset="utf-8"></head>
<body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;">
<h2 style="color:#405189;">{{ $companyName }}</h2>

<p>{{ __('email.common.greeting', ['name' => $client->first_name ?: __('email.common.customer')]) }}</p>

<p>{{ __('email.disk_usage_warning.intro', ['service' => trim(($service->product->name ?? '').($service->domain ? ' ('.$service->domain.')' : '')), 'used' => $diskUsed, 'limit' => $diskLimit, 'percent' => $usagePercent]) }}</p>

<p>{{ __('email.disk_usage_warning.mail_counts') }}</p>

<p>{{ __('email.disk_usage_warning.when_full') }}</p>

<p>{{ __('email.disk_usage_warning.what_to_do') }}</p>

@include('emails.partials.action', ['url' => $upgradeUrl, 'label' => __('email.disk_usage_warning.button')])

<p style="color:#888;font-size:12px;margin-top:30px;">{{ $companyName }}</p>
</body></html>
