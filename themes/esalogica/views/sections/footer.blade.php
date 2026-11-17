{{-- ===== FOOTER (Esalogica) =====
     The company's name, address and VAT number come from the general
     settings, the same ones the About page and the invoices use. Italian law
     wants the VAT number on the company's website. The payment marks are the
     methods Esalogica takes: bank transfer, card and PayPal. --}}
@php
    $esa = (require base_path('themes/esalogica/strings.php'))[app()->getLocale() === 'it' ? 'it' : 'en'];
    $setting = fn (string $key) => trim((string) \App\Models\Setting::get($key, ''));
    $companyName = $setting('CompanyLegalName') ?: ($setting('CompanyName') ?: ($brandName ?? 'Esalogica'));
    // The address setting may already hold the postcode and town on its own
    // lines; they are added only when it does not.
    $street = collect(preg_split('/\R/', $setting('Address')))->map(fn ($line) => trim($line))->filter()->implode(', ');
    $town = trim($setting('Postcode').' '.($setting('CompanyCity') ?: $setting('City')).($setting('State') ? ' ('.$setting('State').')' : ''));
    $address = collect([$street, $setting('Postcode') !== '' && str_contains($street, $setting('Postcode')) ? '' : $town])->filter()->implode(', ');
    $vatNumber = $setting('TaxID');
    $phone = $setting('PhoneNumber');
    $email = $brandEmail ?? $setting('Email');
@endphp
<footer class="footer">
    <div class="container">
        <div class="footer__grid">
            <div>
                <div class="footer__brand">
                    @if(!empty($customLogo))
                        <img src="{{ $customLogo }}" alt="{{ $brandName ?? 'Esalogica' }}" style="height: 28px;">
                    @else
                        {{ $brandName ?? 'Esalogica' }}<span class="footer__brand-dot"></span>
                    @endif
                </div>
                <p class="footer__desc">{{ $esa['footer']['description'] }}</p>
                @if($email)
                <div class="footer__contact-item"><i class="ri-mail-line"></i> {{ $email }}</div>
                @endif
                @if($phone)
                <div class="footer__contact-item"><i class="ri-phone-line"></i> {{ $phone }}</div>
                @endif
                @if($address)
                <div class="footer__contact-item"><i class="ri-map-pin-line"></i> {{ $address }}</div>
                @endif
                @include('partials.social-links')
            </div>
            <div>
                <div class="footer__col-title">{{ $esa['footer']['col_domains'] }}</div>
                <a href="{{ url('/client/domain-search') }}" class="footer__link">{{ $esa['nav']['domain_register'] }}</a>
                <a href="{{ url('/client/domain-search') }}" class="footer__link">{{ $esa['nav']['domain_transfer'] }}</a>
            </div>
            <div>
                <div class="footer__col-title">{{ $esa['footer']['col_services'] }}</div>
                <a href="{{ url('/client/store') }}" class="footer__link">{{ $esa['nav']['shared'] }}</a>
                <a href="{{ url('/client/store') }}" class="footer__link">{{ $esa['nav']['reseller'] }}</a>
                <a href="{{ url('/client/store') }}" class="footer__link">{{ $esa['nav']['pec'] }}</a>
                <a href="{{ route('pages.mail-setup') }}" class="footer__link">{{ $esa['nav']['mail_setup'] }}</a>
                <a href="{{ route('pages.ssl') }}" class="footer__link">{{ $esa['footer']['ssl'] }}</a>
            </div>
            <div>
                <div class="footer__col-title">{{ $esa['footer']['col_support'] }}</div>
                @if(kb_enabled())
                <a href="{{ url('/client/knowledgebase') }}" class="footer__link">{{ $esa['nav']['guides'] }}</a>
                @endif
                <a href="{{ url('/client/tickets/create') }}" class="footer__link">{{ $esa['topbar']['ticket'] }}</a>
                <a href="{{ url('/client/contact') }}" class="footer__link">{{ $esa['nav']['contact'] }}</a>
                <a href="{{ url('/client/announcements') }}" class="footer__link">{{ $esa['footer']['announcements'] }}</a>
                <a href="{{ route('pages.about') }}" class="footer__link">{{ $esa['footer']['about'] }}</a>
                <a href="{{ route('legal.index') }}" class="footer__link">{{ $esa['footer']['legal'] }}</a>
            </div>
        </div>
        <div class="footer__bottom">
            <span>&copy; {{ date('Y') }} {{ $companyName }}@if($vatNumber) · {{ $esa['footer']['vat'] }} {{ $vatNumber }}@endif. {{ $esa['footer']['rights'] }}</span>
            <div class="footer__payments">
                <div class="footer__payment-icon">{{ $esa['footer']['bank_transfer'] }}</div>
                <div class="footer__payment-icon">VISA</div>
                <div class="footer__payment-icon">MC</div>
                <div class="footer__payment-icon">PayPal</div>
            </div>
        </div>
    </div>
</footer>
