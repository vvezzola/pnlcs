# PEC (posta elettronica certificata)

L'addon **PEC** invia messaggi certificati ai clienti dalla tua casella PEC e conserva le ricevute di accettazione e consegna del gestore, che sono la prova legale dell'invio.

## Requisiti

- Una casella PEC con accesso SMTP e IMAP.
- Esegui `php artisan migrate` dopo l'installazione: l'addon usa la tabella `pec_messages`.
- Lo scheduler di Laravel attivo, per leggere le ricevute in automatico.

## Configurazione

Attiva l'addon nella pagina **Estensioni** e compila:

| Campo | Note |
|---|---|
| Indirizzo PEC | La casella da cui partono i messaggi; riceve anche le ricevute |
| Password della casella | Salvata come dato riservato |
| Server e porta SMTP | Predefiniti `smtps.pec.aruba.it`, `465` |
| Server e porta IMAP | Predefiniti `imaps.pec.aruba.it`, `993` |
| Campo cliente con l'indirizzo PEC | Collega ogni messaggio al suo cliente. Se vuoto, viene confrontata l'email del cliente |

I valori predefiniti sono i server di Aruba: per un altro gestore vanno cambiati. L'IMAP usa sempre TLS.

## Invio

Nella pagina dell'addon inserisci i destinatari (uno per riga, oppure separati da virgole o punti e virgola), l'oggetto, il messaggio e fino a 5 allegati da 20 MB ciascuno. Il messaggio è in solo testo.

Ogni destinatario riceve un messaggio separato, così ha la sua ricevuta di consegna. Un destinatario rifiutato non blocca gli altri; i rifiuti vengono mostrati dopo l'invio. Per inviare serve il permesso `edit_clients`.

## Ricevute

`pnlcs:pec-receipts` viene eseguito ogni 10 minuti mentre l'addon è attivo; in alternativa premi il pulsante per controllare subito le ricevute. Cerca nella casella le ricevute dei messaggi inviati negli ultimi 30 giorni e aggiorna lo stato di ognuno:

| Stato | Significato |
|---|---|
| Inviato | Consegnato al gestore, nessuna ricevuta per ora |
| Accettato | Ricevuta di accettazione arrivata |
| Consegnato | Ricevuta di avvenuta consegna arrivata |
| Fallito | Mancata accettazione, errore di consegna o virus rilevato |

Le ricevute vengono salvate senza modifiche come file `.eml` in `storage/app/pec/` e si scaricano dall'elenco. Includile nei backup.

## Limiti

- La pagina mostra gli ultimi 50 messaggi.
- L'invio a molti destinatari avviene durante la richiesta, uno alla volta: meglio lotti piccoli.
- **Non ancora provato su una casella PEC reale.** Fai un primo invio verso una tua PEC e controlla che arrivino le ricevute.
