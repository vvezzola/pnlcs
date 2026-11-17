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
    'documents' => [
        'terms' => ['Condizioni generali di servizio', 'Gli obblighi tuoi e nostri quando usi i nostri servizi.', 'ri-file-text-line', '2026-11-17'],
        'privacy' => ['Informativa privacy', 'Quali dati personali trattiamo, perché e per quanto tempo.', 'ri-lock-line', '2026-11-17'],
        'cookies' => ['Cookie policy', 'I cookie che usa questo sito.', 'ri-cake-2-line', '2026-11-17'],
        'dpa' => ['Accordo sul trattamento dei dati', 'Come trattiamo, per tuo conto, i dati che conservi nei servizi (art. 28 GDPR).', 'ri-database-2-line', '2026-11-17'],
    ],
    // The other documents of the original set: their subject is covered by
    // an article of the terms, and their page says which.
    'covered' => [
        'aup' => ['Uso accettabile', 'terms', 'art. 8'],
        'refund' => ['Recesso e rimborsi', 'terms', 'art. 7'],
        'sla' => ['Disponibilità del servizio', 'terms', 'art. 12'],
        'abuse' => ['Segnalazione di abusi', 'terms', 'artt. 8 e 14'],
        'domain' => ['Nomi a dominio', 'terms', 'art. 10'],
    ],
];
