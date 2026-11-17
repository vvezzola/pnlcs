{{-- Esalogica: informativa privacy (art. 13 GDPR) for customers and visitors of my.esalogica.com. --}}
<p>Questa informativa spiega come Esalogica tratta i dati personali di chi visita my.esalogica.com, si registra, ordina e usa i nostri servizi, ai sensi dell'art. 13 del Regolamento (UE) 2016/679 («GDPR») e del D.Lgs. 196/2003.</p>
<p>I dati che conservi nei servizi (i contenuti dei siti, le caselle email, i database, i VPS) sono tuoi: li trattiamo solo per tuo conto, come responsabile, secondo l'<a href="{{ route('legal.show', 'dpa') }}">accordo sul trattamento dei dati</a>.</p>

<h2>1. Titolare del trattamento</h2>
<p>{{ $esaCompany['legal_name'] }}, {{ $esaCompany['form'] }}, {{ $esaCompany['address'] }}, P.IVA {{ $esaCompany['vat'] }}. Email <a href="mailto:{{ $esaCompany['email'] }}">{{ $esaCompany['email'] }}</a>, PEC <a href="mailto:{{ $esaCompany['pec'] }}">{{ $esaCompany['pec'] }}</a>.</p>

<h2>2. Quali dati trattiamo</h2>
<ul>
    <li><strong>Dati anagrafici e di contatto</strong>: nome, ragione sociale, indirizzo, email, telefono, contatti aggiuntivi che inserisci nell'area clienti.</li>
    <li><strong>Dati fiscali</strong>: codice fiscale, partita IVA, codice destinatario o PEC per la fattura elettronica.</li>
    <li><strong>Dati dei servizi e degli ordini</strong>: servizi acquistati, fatture, pagamenti ricevuti, richieste di assistenza e messaggi che ci scrivi.</li>
    <li><strong>Dati di pagamento</strong>: esito e riferimenti dei pagamenti. I numeri delle carte sono inseriti direttamente presso Stripe o PayPal e noi non li vediamo.</li>
    <li><strong>Dati dei nomi a dominio</strong>: i dati dell'assegnatario che il registro richiede per registrare il dominio.</li>
    <li><strong>Dati tecnici</strong>: indirizzo IP, data e ora degli accessi all'area clienti e delle operazioni compiute, raccolti per la sicurezza dell'account.</li>
</ul>

<h2>3. Perché li trattiamo e su quale base</h2>
<ul>
    <li><strong>Fornirti i servizi</strong>: creare l'account, attivare, rinnovare e gestire i servizi, registrare i domini, darti assistenza e mandarti le comunicazioni di servizio (avvisi di rinnovo, scadenze, spazio quasi esaurito). Base: esecuzione del contratto (art. 6.1.b GDPR).</li>
    <li><strong>Fatturazione e obblighi di legge</strong>: emettere e conservare le fatture elettroniche, tenere la contabilità, rispondere alle richieste delle autorità. Base: obbligo di legge (art. 6.1.c).</li>
    <li><strong>Sicurezza e prevenzione degli abusi</strong>: proteggere gli account e i server, prevenire frodi e usi vietati, difendere i nostri diritti. Base: legittimo interesse (art. 6.1.f).</li>
    <li><strong>Comunicazioni commerciali</strong>: solo se ci dai il consenso, che puoi revocare in ogni momento dall'area clienti o con il link presente in ogni email. Base: consenso (art. 6.1.a).</li>
</ul>
<p>I dati necessari al contratto e alla fatturazione sono obbligatori: senza di essi non possiamo fornire i servizi. Non prendiamo decisioni basate unicamente su trattamenti automatizzati e non vendiamo i tuoi dati.</p>

<h2>4. A chi li comunichiamo</h2>
<p>Solo a chi ne ha bisogno per le finalità sopra indicate:</p>
<ul>
    <li><strong>Data center</strong> che ospitano i nostri server e questo sito: Leaseweb Deutschland GmbH (Germania), Leaseweb Netherlands B.V. e WorldStream B.V. (Paesi Bassi), come responsabili del trattamento.</li>
    <li><strong>Pagamenti</strong>: Stripe e PayPal, come titolari autonomi per i pagamenti con carta e PayPal; la nostra banca per i bonifici.</li>
    <li><strong>Fatturazione elettronica</strong>: Invoicetronic, intermediario che trasmette le fatture al Sistema di Interscambio dell'Agenzia delle Entrate, e il nostro consulente fiscale.</li>
    <li><strong>Nomi a dominio</strong>: i registrar con cui registriamo i domini (Internet.bs, Openprovider, Aruba) e i registri delle estensioni, come il Registro .it. Alcuni dati dell'assegnatario possono comparire nel database pubblico WHOIS secondo le regole del registro.</li>
    <li><strong>Certificati SSL e PEC</strong>: le autorità di certificazione che emettono i certificati e Aruba PEC S.p.A. per le caselle PEC, ciascuna per i dati necessari al servizio.</li>
    <li><strong>Autorità</strong>, quando la legge lo richiede.</li>
</ul>

<h2>5. Trasferimenti fuori dall'Unione europea</h2>
<p>I nostri server sono nell'Unione europea. Alcuni dati possono uscirne solo quando serve al servizio che hai chiesto: per i domini con estensioni generiche (.com, .net e simili) registrati tramite Internet.bs, con sede alle Bahamas, il trasferimento è necessario per eseguire il contratto (art. 49.1.b GDPR); Stripe e PayPal possono trasferire dati negli Stati Uniti con le garanzie previste dal GDPR (decisione di adeguatezza o clausole contrattuali tipo).</p>

<h2>6. Per quanto tempo li conserviamo</h2>
<ul>
    <li>Dati dell'account e dei servizi: per tutta la durata del rapporto e poi per 10 anni, il termine di prescrizione ordinaria.</li>
    <li>Fatture e documenti contabili: 10 anni, come richiesto dall'art. 2220 del codice civile.</li>
    <li>Dati tecnici di accesso: per il tempo necessario alla sicurezza degli account, di norma non oltre 12 mesi, salvo che servano ad accertare un abuso.</li>
    <li>Dati usati per comunicazioni commerciali: fino alla revoca del consenso.</li>
</ul>

<h2>7. I tuoi diritti</h2>
<p>Puoi chiedere in ogni momento di accedere ai tuoi dati, di correggerli, di cancellarli, di limitarne il trattamento, di riceverli in un formato leggibile da una macchina, e puoi opporti ai trattamenti basati sul legittimo interesse (artt. 15-22 GDPR). Scrivi a <a href="mailto:{{ $esaCompany['email'] }}">{{ $esaCompany['email'] }}</a> o alla PEC <a href="mailto:{{ $esaCompany['pec'] }}">{{ $esaCompany['pec'] }}</a>: rispondiamo entro un mese. Molti dati puoi correggerli da solo dall'area clienti.</p>
<p>Se ritieni che il trattamento violi il GDPR puoi proporre reclamo al Garante per la protezione dei dati personali (<a href="https://www.garanteprivacy.it" target="_blank" rel="noopener">www.garanteprivacy.it</a>).</p>

<h2>8. Cookie</h2>
<p>Il sito usa solo cookie tecnici, descritti nella <a href="{{ route('legal.show', 'cookies') }}">cookie policy</a>.</p>
