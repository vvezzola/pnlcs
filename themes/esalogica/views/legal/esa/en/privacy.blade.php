{{-- Esalogica: privacy notice, English courtesy translation of legal/esa/it/privacy. Keep the two in step. --}}
<div class="legal-note"><p style="margin:0">This is a courtesy translation. The <a href="{{ \App\Support\LocaleUrl::switchTo('it') }}">Italian text</a> prevails in case of any difference.</p></div>

<p>This notice explains how Esalogica processes the personal data of those who visit my.esalogica.com, sign up, order and use our services, under art. 13 of Regulation (EU) 2016/679 ("GDPR") and Italian Legislative Decree 196/2003.</p>
<p>The data you keep in the services (website content, mailboxes, databases, VPS) is yours: we process it only on your behalf, as processor, under the <a href="{{ route('legal.show', 'dpa') }}">data processing agreement</a>.</p>

<h2>1. Controller</h2>
<p>{{ $esaCompany['legal_name'] }}, sole proprietorship, {{ $esaCompany['address'] }}, Italy, VAT no. IT{{ $esaCompany['vat'] }}. Email <a href="mailto:{{ $esaCompany['email'] }}">{{ $esaCompany['email'] }}</a>, PEC <a href="mailto:{{ $esaCompany['pec'] }}">{{ $esaCompany['pec'] }}</a>.</p>

<h2>2. The data we process</h2>
<ul>
    <li><strong>Identity and contact details</strong>: name, company name, address, email, telephone, additional contacts you enter in the client area.</li>
    <li><strong>Tax details</strong>: tax code, VAT number, recipient code or PEC address for electronic invoices.</li>
    <li><strong>Service and order data</strong>: services bought, invoices, payments received, support requests and messages you send us.</li>
    <li><strong>Payment data</strong>: outcome and references of payments. Card numbers are entered directly with Stripe or PayPal and we never see them.</li>
    <li><strong>Domain name data</strong>: the registrant details the registry requires to register the domain.</li>
    <li><strong>Technical data</strong>: IP address, date and time of logins to the client area and of the operations carried out, collected for account security.</li>
</ul>

<h2>3. Why we process it and on what basis</h2>
<ul>
    <li><strong>Providing the services</strong>: creating the account, activating, renewing and managing the services, registering domains, giving support and sending service messages (renewal notices, due dates, disk space running out). Basis: performance of the contract (art. 6(1)(b) GDPR).</li>
    <li><strong>Invoicing and legal obligations</strong>: issuing and keeping electronic invoices, bookkeeping, answering requests from the authorities. Basis: legal obligation (art. 6(1)(c)).</li>
    <li><strong>Security and abuse prevention</strong>: protecting accounts and servers, preventing fraud and prohibited use, defending our rights. Basis: legitimate interest (art. 6(1)(f)).</li>
    <li><strong>Marketing messages</strong>: only if you give us your consent, which you can withdraw at any time from the client area or with the link in every such email. Basis: consent (art. 6(1)(a)).</li>
</ul>
<p>The data needed for the contract and for invoicing is mandatory: without it we cannot provide the services. We make no decisions based solely on automated processing and we do not sell your data.</p>

<h2>4. Who we share it with</h2>
<p>Only with those who need it for the purposes above:</p>
<ul>
    <li><strong>Data centres</strong> hosting our servers and this site: Leaseweb Deutschland GmbH (Germany), Leaseweb Netherlands B.V. and WorldStream B.V. (the Netherlands), as processors.</li>
    <li><strong>Payments</strong>: Stripe and PayPal, as independent controllers for card and PayPal payments; our bank for bank transfers.</li>
    <li><strong>Electronic invoicing</strong>: Invoicetronic, the intermediary that sends invoices to the Exchange System (SDI) of the Italian Revenue Agency, and our tax adviser.</li>
    <li><strong>Domain names</strong>: the registrars we register domains with (Internet.bs, Openprovider, Aruba) and the registries of the extensions, such as the .it Registry. Some registrant details may appear in the public WHOIS database under the registry's rules.</li>
    <li><strong>SSL certificates and PEC</strong>: the certificate authorities that issue certificates and Aruba PEC S.p.A. for PEC mailboxes, each for the data the service needs.</li>
    <li><strong>Authorities</strong>, where the law requires it.</li>
</ul>

<h2>5. Transfers outside the European Union</h2>
<p>Our servers are in the European Union. Some data may leave it only when needed for the service you asked for: for domains with generic extensions (.com, .net and similar) registered through Internet.bs, based in the Bahamas, the transfer is necessary to perform the contract (art. 49(1)(b) GDPR); Stripe and PayPal may transfer data to the United States with the safeguards provided by the GDPR (adequacy decision or standard contractual clauses).</p>

<h2>6. How long we keep it</h2>
<ul>
    <li>Account and service data: for the whole relationship and then for 10 years, the ordinary limitation period.</li>
    <li>Invoices and accounting records: 10 years, as required by art. 2220 of the Italian Civil Code.</li>
    <li>Technical login data: as long as needed for account security, normally no more than 12 months, unless needed to establish an abuse.</li>
    <li>Data used for marketing messages: until consent is withdrawn.</li>
</ul>

<h2>7. Your rights</h2>
<p>You may at any time ask to access your data, correct it, erase it, restrict its processing, receive it in a machine-readable format, and object to processing based on legitimate interest (arts. 15-22 GDPR). Write to <a href="mailto:{{ $esaCompany['email'] }}">{{ $esaCompany['email'] }}</a> or to the PEC address <a href="mailto:{{ $esaCompany['pec'] }}">{{ $esaCompany['pec'] }}</a>: we answer within one month. You can correct much of your data yourself from the client area.</p>
<p>If you believe the processing breaches the GDPR you may lodge a complaint with the Italian data protection authority, the Garante per la protezione dei dati personali (<a href="https://www.garanteprivacy.it" target="_blank" rel="noopener">www.garanteprivacy.it</a>), or with the authority of the EU country where you live.</p>

<h2>8. Cookies</h2>
<p>The site uses only technical cookies, described in the <a href="{{ route('legal.show', 'cookies') }}">cookie policy</a>.</p>
