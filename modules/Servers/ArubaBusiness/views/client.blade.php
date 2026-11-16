{{-- Aruba Business section of the customer's service page
     (Modules\Servers\ArubaBusiness, drawn through ModuleScreens). The customer
     sees their mailbox and where to use it; what Aruba charges is staff's. --}}
@php
    $t = fn (string $key, array $with = []) => __('aruba-business::messages.'.$key, $with);
@endphp
<div class="sv-panel" id="aruba-business" style="margin-bottom:22px">
    <div class="sv-ph"><span><i class="ri-mail-lock-line"></i> PEC</span></div>
    @if(! $linked)
    <div style="padding:18px 20px"><p style="margin:0;color:var(--muted);font-size:13.5px;">{{ $t('client.not_linked') }}</p></div>
    @else
    <ul class="sv-dl">
        <li><span class="k">{{ $t($isMailbox ? 'field.account' : 'field.domain') }}</span><span class="v"><span class="sv-code">{{ $data['aruba_account'] ?? '—' }}</span></span></li>
        <li><span class="k">{{ $t('field.article') }}</span><span class="v">{{ $data['aruba_article'] ?? '—' }}</span></li>
        @if($isMailbox)
        <li><span class="k">{{ $t('field.webmail') }}</span><span class="v"><a href="https://webmail.pec.it" target="_blank" rel="noopener">{{ $t('client.webmail_link') }}</a></span></li>
        <li><span class="k">{{ $t('field.manage') }}</span><span class="v"><a href="https://gestionemail.pec.it" target="_blank" rel="noopener">{{ $t('client.manage_link') }}</a></span></li>
        @endif
    </ul>
    @endif
</div>
