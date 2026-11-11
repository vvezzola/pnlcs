# Ricerca aziende: partite IVA UE (VIES)

L'addon Company Lookup compila i dati aziendali di un cliente partendo dal numero fiscale. Oltre ai registri polacchi, risponde per le partite IVA degli altri Paesi UE tramite il servizio **VIES** della Commissione europea, gratuito e senza chiave.

## Come si usa

Nel campo di ricerca azienda puoi:

- scrivere il numero con il prefisso del Paese, ad esempio `IT01234567890`; oppure
- scriverlo senza prefisso su un cliente il cui Paese è nell'UE e non è la Polonia.

Nome e indirizzo vengono compilati dove lo Stato membro li rende disponibili. Gli indirizzi italiani vengono divisi in via, CAP, città e provincia; per gli altri Paesi, via e, quando riconoscibili, CAP e città.

I numeri polacchi continuano a usare i registri polacchi. La Grecia in VIES è `EL`; viene accettato anche `GR`.

## Cosa non copre VIES

VIES elenca solo le partite IVA **iscritte per gli scambi intracomunitari**, e in Italia l'iscrizione è facoltativa. Un'azienda italiana regolare che non opera nell'UE risulta "non trovata": non significa che il numero sia sbagliato.

Se il registro di uno Stato membro non è disponibile, PNLCS avvisa che VIES non ha risposto: riprova dopo un minuto. Le risposte positive vengono memorizzate in cache.
