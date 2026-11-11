# Registrar Internet.bs

Il modulo registrar `internetbs` registra, trasferisce, rinnova e gestisce i domini tramite un account rivenditore [Internet.bs](https://internetbs.net).

## Requisiti

- Un account Internet.bs con accesso API. La **password API** è diversa da quella di accesso al sito e si imposta nel pannello Internet.bs.
- L'indirizzo IP del server PNLCS abilitato all'accesso API, se l'account lo limita.

## Configurazione

In **Setup → Registrar di domini** compila **API Key** e **API Password**, poi usa il test di connessione, che legge il saldo dell'account.

L'**ambiente di test** è attivo per impostazione predefinita e usa `testapi.internet.bs`. In modalità reale, registrazioni, trasferimenti e rinnovi costano denaro vero.

## Operazioni supportate

Registrazione, trasferimento, rinnovo, nameserver, codice EPP, blocco del registrar, privacy WHOIS, verifica di disponibilità e sincronizzazione di date, stato, blocco, nameserver e privacy (`pnlcs:domain-sync`).

Dettagli utili:

- Il rinnovo invia la data di scadenza registrata in PNLCS. Internet.bs lo rifiuta se non coincide con la sua, così un rinnovo ripetuto non può aggiungere un secondo anno. Se un rinnovo viene rifiutato, sincronizza prima il dominio.
- Per i registri in cui la data di scadenza può restare indietro rispetto al pagato, tra cui il `.it`, la sincronizzazione privilegia la data "pagato fino al".
- Registrazioni e trasferimenti `.it` inviano i campi aggiuntivi del registro ricavati dalla scheda cliente: tipo di soggetto, nazionalità, provincia e codice fiscale. Tieni corretti paese, provincia e dati fiscali del cliente.
