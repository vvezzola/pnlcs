# Plesk: collegare le subscription esistenti

Se hai già clienti su Plesk, puoi fatturarli da PNLCS senza ricreare nulla, collegando ogni servizio alla sua subscription Plesk esistente.

## Collegare un servizio

1. Collega il server Plesk in **Setup → Server**.
2. Nella pagina del cliente aggiungi un servizio per un prodotto Plesk e seleziona il server.
3. In **Collega a un account esistente** scegli la subscription. L'elenco mostra il dominio e il suo proprietario.

Il collegamento registra solo la subscription e il proprietario in PNLCS. **Su Plesk non cambia nulla.** Il nome utente del servizio diventa il login del proprietario; il dominio, se vuoto, diventa il nome della subscription. Una subscription già gestita da un altro servizio attivo non si può collegare due volte.

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
