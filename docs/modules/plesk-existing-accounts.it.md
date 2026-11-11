# Plesk: collegare le subscription esistenti

Se hai già clienti su Plesk, puoi fatturarli da PNLCS senza ricreare nulla, collegando ogni servizio alla sua subscription Plesk esistente.

## Collegare un servizio

1. Collega il server Plesk in **Setup → Server**.
2. Nella pagina del cliente aggiungi un servizio per un prodotto Plesk e seleziona il server.
3. In **Collega a un account esistente** scegli la subscription. L'elenco mostra il dominio e il suo proprietario.

Il collegamento registra solo la subscription e il proprietario in PNLCS. **Su Plesk non cambia nulla.** Il nome utente del servizio diventa il login del proprietario; il dominio, se vuoto, diventa il nome della subscription. Una subscription già gestita da un altro servizio attivo non si può collegare due volte.

## Importare tutte le subscription in una volta

**Setup → Server → Sincronizza account** elenca tutte le subscription del server. Ogni riga mostra dominio, proprietario, piano, data di creazione e stato, e il servizio che la fattura se esiste. Le subscription senza servizio si importano a gruppi di massimo 100:

1. Spunta le subscription da importare. **Solo account non collegati** nasconde quelle già fatturate.
2. Controlla cliente (per id: il campo suggerisce i nomi mentre scrivi), prodotto, ciclo, importo e prossima scadenza.
3. Premi **Importa selezionati**. Ogni subscription diventa un servizio di quel cliente, collegato come sopra. **Su Plesk non cambia nulla.**

Ogni riga parte da una proposta che puoi cambiare:

| Campo | Da dove arriva |
|---|---|
| Cliente | Il listino; altrimenti un dominio o un servizio già in PNLCS; altrimenti l'email del proprietario |
| Prodotto | Il prodotto il cui piano Plesk corrisponde a quello della subscription; l'unico prodotto Plesk, se ce n'è uno solo |
| Importo | Il listino; altrimenti il prezzo del prodotto per il ciclo |
| Prossima scadenza | Il listino; altrimenti il prossimo anniversario della creazione della subscription |
| Stato | Attivo, o sospeso se la subscription è sospesa su Plesk |

### Listino

Per compilare molte righe in una volta, carica un CSV con riga di intestazione e queste colonne: `domain`, `client_id` (o `client_email`), `amount`, `billing_cycle` (`Monthly`, `Quarterly`, `Semi-Annually`, `Annually`, `Biennially`, `Triennially`) e `next_due_date` (`AAAA-MM-GG`). Vanno bene virgole o punti e virgola, e ogni colonna può restare vuota. Il listino resta valido per la sessione; le righe compilate da lì sono segnalate.

```csv
domain,client_id,amount,billing_cycle,next_due_date
rossi.it,12,60.00,Annually,2027-03-15
```

!!! note
    Le subscription di proprietà dell'amministratore Plesk non hanno un'email del proprietario: il cliente può arrivare solo dal listino o dai dati già in PNLCS.

Per importare servono i permessi **modifica clienti** e **gestione servizi**; chi ha solo **gestione server** può vedere la pagina.

## Account reseller

Un prodotto può vendere un account **reseller** Plesk invece di una singola subscription. Nelle impostazioni del modulo del prodotto, l'elenco dei pacchetti mostra i piani reseller del server dopo i piani di servizio, come **Reseller: <piano>**. Scegline uno e un ordine per quel prodotto crea un reseller su quel piano, con il suo login, invece di un cliente con una subscription. Un servizio reseller non ha bisogno di un dominio.

| Azione | Effetto su Plesk |
|---|---|
| Sospendi, riattiva | Il reseller e, con lui, tutti i clienti e i siti che ospita |
| Termina | **Cancella il reseller con tutti i suoi clienti e siti** |
| Cambia password | La password di accesso del reseller |
| Cambia pacchetto | Sposta il reseller su un altro piano reseller; un reseller non può diventare una subscription né il contrario |

I reseller esistenti compaiono nella pagina di sincronizzazione con l'etichetta **Reseller** e il loro piano, e si importano e collegano come le subscription. Spazio e traffico dei reseller vengono letti come per i clienti.

## Cosa fanno le azioni

| Azione | Effetto su Plesk |
|---|---|
| Sospendi, riattiva | Solo quella subscription; le altre del cliente restano attive |
| Termina | **Cancella la subscription e il suo contenuto.** Viene cancellato anche il cliente Plesk quando non gli restano altre subscription |
| Cambia password | Cambia la password del **cliente Plesk**, valida per tutte le sue subscription |

!!! danger
    La terminazione non si può annullare. Su un servizio fatturato per un sito esistente, usala solo se vuoi davvero rimuovere il sito da Plesk.

Un servizio registrato prima che venisse salvato l'id della subscription mantiene il vecchio comportamento: sospensione e terminazione agiscono sull'intero cliente Plesk.

## Limiti

Le statistiche di utilizzo vengono lette per cliente, non per subscription.
