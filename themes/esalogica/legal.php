<?php

/*
 * Esalogica's legal documents: which ones are listed, under which title, and
 * from when each text applies. The texts are in views/legal/en/<slug> (the
 * controller looks for an "en" body for every language but Turkish; these
 * are in Italian, the language of Esalogica's contracts).
 *
 * Changing a document means changing its text and its date here in the same
 * commit: a customer is bound by the text in force on the day they ordered.
 */

return [
    'company' => [
        'legal_name' => 'Esalogica di Vittorio Vezzola',
        'form' => 'ditta individuale',
        'address' => 'Via Giuseppe Verdi 12, 25080 Nuvolento (BS)',
        'vat' => '03268930983',
        'rea' => 'BS-525783',
        'pec' => 'esalogica@gigapec.it',
        'email' => 'info@esalogica.com',
    ],
    // slug => [icon, effective date, [Italian title, summary], [English title, summary]]
    'documents' => [
        'terms' => ['ri-file-text-line', '2026-11-17',
            'it' => ['Condizioni generali di servizio', 'Gli obblighi tuoi e nostri quando usi i nostri servizi.'],
            'en' => ['Terms of Service', 'Your obligations and ours when you use our services.']],
        'privacy' => ['ri-lock-line', '2026-11-17',
            'it' => ['Informativa privacy', 'Quali dati personali trattiamo, perché e per quanto tempo.'],
            'en' => ['Privacy notice', 'What personal data we process, why, and for how long.']],
        'cookies' => ['ri-cake-2-line', '2026-11-17',
            'it' => ['Cookie policy', 'I cookie che usa questo sito.'],
            'en' => ['Cookie policy', 'The cookies this site uses.']],
        'dpa' => ['ri-database-2-line', '2026-11-17',
            'it' => ['Accordo sul trattamento dei dati', 'Come trattiamo, per tuo conto, i dati che conservi nei servizi (art. 28 GDPR).'],
            'en' => ['Data processing agreement', 'How we process, on your behalf, the data you keep in the services (art. 28 GDPR).']],
    ],
    // The other documents of the original set: their subject is covered by
    // an article of the terms, and their page says which.
    'covered' => [
        'aup' => ['terms', 'art. 8', 'it' => 'Uso accettabile', 'en' => 'Acceptable use'],
        'refund' => ['terms', 'art. 7', 'it' => 'Recesso e rimborsi', 'en' => 'Withdrawal and refunds'],
        'sla' => ['terms', 'art. 12', 'it' => 'Disponibilità del servizio', 'en' => 'Service availability'],
        'abuse' => ['terms', ['it' => 'artt. 8 e 14', 'en' => 'arts. 8 and 14'], 'it' => 'Segnalazione di abusi', 'en' => 'Reporting abuse'],
        'domain' => ['terms', 'art. 10', 'it' => 'Nomi a dominio', 'en' => 'Domain names'],
    ],
    // The wording around the documents.
    'ui' => [
        'it' => [
            'hub' => 'Documenti legali',
            'hub_description' => 'Condizioni generali di servizio, informativa privacy, cookie policy e accordo sul trattamento dei dati di Esalogica.',
            'hub_intro' => 'I contratti e le informative che si applicano quando usi i nostri servizi. Ogni documento riporta la data da cui è in vigore: a un ordine si applica il testo in vigore il giorno in cui è stato fatto.',
            'questions' => 'Domande',
            'questions_text' => 'per qualsiasi domanda su questi documenti scrivi a :email o alla PEC :pec.',
            'side' => 'DOCUMENTI',
            'effective' => 'In vigore dal',
            'covered' => 'Questo argomento è regolato dalle :terms, :article.',
            'terms_link' => 'Condizioni generali di servizio',
        ],
        'en' => [
            'hub' => 'Legal documents',
            'hub_description' => 'Esalogica terms of service, privacy notice, cookie policy and data processing agreement.',
            'hub_intro' => 'The contracts and notices that apply when you use our services. Each document carries the date from which it is in force: an order is governed by the text in force on the day it was placed. The contract is the Italian text; the English one is a courtesy translation.',
            'questions' => 'Questions',
            'questions_text' => 'for any question about these documents write to :email or to the PEC address :pec.',
            'side' => 'DOCUMENTS',
            'effective' => 'In force from',
            'covered' => 'This subject is governed by the :terms, :article.',
            'terms_link' => 'Terms of Service',
        ],
    ],
];
