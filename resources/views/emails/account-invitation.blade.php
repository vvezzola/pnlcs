<!DOCTYPE html>
<html><head><meta charset="utf-8"></head>
<body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;">
<h2 style="color:#405189;">{{ $companyName }}</h2>

<p>{{ __('email.common.greeting', ['name' => $client->first_name ?: __('email.common.customer')]) }}</p>

<p>{{ __('email.account_invitation.intro', ['company' => $companyName]) }}</p>

<p>{{ __('email.account_invitation.set_password') }}</p>

@include('emails.partials.action', ['url' => $inviteUrl, 'label' => __('email.account_invitation.button')])

<p style="color:#666;font-size:13px;">{{ __('email.account_invitation.valid', ['days' => $validDays]) }}</p>

<p>{{ __('email.account_invitation.check_details') }}</p>

@if($noticeDay)
<p>{{ __('email.account_invitation.notice', ['notice_day' => $noticeDay, 'billing_day' => $billingDay]) }}</p>
@endif

<p>{{ __('email.account_invitation.questions') }}</p>

<p style="color:#888;font-size:12px;margin-top:30px;">{{ $companyName }}</p>
</body></html>
