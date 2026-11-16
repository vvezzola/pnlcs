<?php

return [
    'title' => 'Aruba Business',
    'autorenew' => [
        'on' => 'attivo',
        'off' => 'spento',
    ],
    'field' => [
        'account' => 'Casella',
        'domain' => 'Dominio certificato',
        'article' => 'Tipo',
        'state' => 'Stato su Aruba',
        'activated' => 'Attiva dal',
        'expires' => 'Scadenza su Aruba',
        'autorenew' => 'Rinnovo automatico',
        'renewal_price' => 'Prezzo di rinnovo su Aruba',
        'service_id' => 'Servizio Aruba',
        'checked_at' => 'Riletta il',
        'webmail' => 'Webmail',
        'manage' => 'Impostazioni della casella',
    ],
    'action' => [
        'check_now' => 'Rileggi da Aruba',
    ],
    'client' => [
        'webmail_link' => 'Apri la webmail PEC',
        'manage_link' => 'Password e impostazioni',
        'not_linked' => 'Questo servizio è in preparazione.',
    ],
    'admin' => [
        'not_linked' => 'Non ancora collegato a un servizio dell\'account Aruba Business. Collegalo dalla pagina di sincronizzazione del server, o con "collega account esistente".',
    ],
    'warning' => [
        'state' => 'Aruba indica questo servizio come ":state".',
        'autorenew_off' => 'Il rinnovo automatico è spento su Aruba: il servizio finisce il :date se non viene rinnovato dal pannello Aruba.',
        'expires_first' => 'Aruba chiude questo servizio il :date, prima della prossima scadenza qui.',
    ],
    'todo' => [
        'autorenew_off' => 'Spegni il rinnovo automatico di :account nel pannello Aruba Business: qui il servizio è cessato, e Aruba lo rinnova il :date.',
    ],
    'result' => [
        'linked' => 'Collegato al servizio Aruba Business.',
        'billing_only' => 'Solo fatturazione: su Aruba non cambia niente.',
        'nothing_linked' => 'Nessun servizio Aruba collegato: niente da fare.',
        'ended' => 'Servizio cessato; Aruba non lo rinnova più.',
        'refreshed' => 'Riletto da Aruba.',
        'renews_itself' => 'Aruba rinnova da sola. Scade il :date; il rinnovo automatico è :autorenew.',
    ],
    'error' => [
        'no_server' => 'Nessun account Aruba Business configurato (Configurazione, Server).',
        'no_credentials' => 'Il server Aruba Business ha bisogno di nome utente, password e della Authorization-Key nel campo chiave API.',
        'create_by_hand' => 'La casella si crea dal pannello Aruba Business, poi si collega qui.',
        'password_by_hand' => 'La password si cambia dalle impostazioni della casella su Aruba.',
        'package_by_hand' => 'Il tipo si cambia dal pannello Aruba Business.',
        'not_linked' => 'Il servizio non è collegato a un servizio Aruba.',
        'not_found' => 'L\'account Aruba Business non ha il servizio :id fra i suoi servizi PEC.',
        'unreachable' => 'Aruba Business non è raggiungibile.',
        'refused' => 'Aruba Business ha rifiutato la richiesta (:status).',
        'sign_in' => 'Aruba Business ha rifiutato l\'accesso (:status): controlla nome utente, password e Authorization-Key.',
    ],
];
