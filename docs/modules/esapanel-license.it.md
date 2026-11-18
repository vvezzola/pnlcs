# Licenze Esapanel

Il modulo **Esapanel License** vende le licenze Esapanel dal tuo PNLCS. Le crea, le rinnova e le cessa sul License Server di Esalogica (`license.esalogica.com`), con l'account di rivenditore che Esalogica ti ha dato. Il cliente riceve il codice di attivazione e il comando di installazione nella sua area clienti.

## Cosa serve

- Il modulo nella cartella `modules/Servers/EsapanelLicense` del tuo PNLCS. PNLCS lo trova da solo: non c'è niente da registrare.
- La **chiave API** del tuo account rivenditore sul License Server, da Esalogica.
- Un gruppo prodotti in cui mettere le licenze, per esempio "Esapanel" (Prodotti/Servizi, Nuovo gruppo).

## 1. Il License Server

In **Setup, Server**, aggiungi un server:

- tipo: **Esapanel License**
- nome host: `license.esalogica.com`
- porta: `9943`
- chiave API: la chiave del tuo account rivenditore

"Prova connessione" deve rispondere che la connessione funziona.

## 2. I prodotti: il catalogo Esapanel

Apri **Estensioni, Licenze Esapanel**, nel menu in alto. Nella pagina:

1. scegli il License Server e il gruppo prodotti;
2. scrivi i tuoi prezzi, al mese e all'anno, IVA esclusa, per ogni modulo e livello. Un modulo lasciato vuoto in un livello non viene venduto lì;
3. premi **Anteprima** per vedere cosa verrebbe creato o cambiato;
4. premi **Crea o aggiorna**.

Il catalogo crea:

- **un prodotto per livello**: Esapanel Company, Integrator 30, Integrator 60, Integrator 100 e Provider, ciascuno sul piano del License Server con lo stesso nome. Il livello da solo costa 0: il prezzo è la somma dei moduli scelti;
- **i moduli come opzioni** del prodotto: CSP, CSPH, UCP, DRP, WFP e WFPH. Nel Company ogni modulo è un menu con "Nessuno" e le due taglie (per esempio 25 o 100 device, 2 TB o illimitato). Negli altri livelli è una casella;
- **la prova gratuita** "Esapanel Trial", appena il License Server offre il piano "LSP Trial". Dura 15 giorni, comprende tutti i moduli ed è una sola per server.

Senza la spunta **In vendita**, i prodotti nuovi nascono nascosti: li rendi visibili quando vuoi, dalla stessa pagina o dal singolo prodotto.

La pagina si può usare di nuovo quando vuoi: aggiunge quello che manca e cambia i prezzi diversi, senza cancellare nulla. Prodotti, opzioni e prezzi restano modificabili anche uno per uno, da Prodotti/Servizi e da Opzioni configurabili.

## Come funziona la vendita

- **Ordine pagato**: il modulo crea il cliente sul License Server (o ritrova quello che c'è già, per partita IVA o email) e la licenza con il livello e i moduli scelti. La scadenza è la prossima data di rinnovo del servizio.
- **Rinnovo pagato**: la scadenza della licenza si sposta alla fine del nuovo periodo.
- **Cambio di moduli, taglia o livello** (upgrade delle opzioni o del prodotto): la licenza viene allineata. Prima si aggiunge quello che manca, poi si toglie il resto. Se il License Server rifiuta, per esempio togliere un modulo con l'uso oltre il limite, il motivo compare nella pagina del servizio.
- **Sospensione e riattivazione** seguono quelle del servizio. **Cessazione**: la licenza viene revocata, non cancellata. Se il servizio viene creato di nuovo, torna la stessa licenza con lo stesso codice.
- **Cambio di server del cliente**: nel servizio c'è il pulsante per liberare la licenza dal server a cui è legata.

## Da sapere

- Ogni rivenditore vede solo i propri clienti sul License Server. I riferimenti che PNLCS scrive portano il nome del tuo sito, quindi non si confondono con quelli di altri partner.
- I clienti finali non entrano mai nel License Server: il codice lo trovano nella loro area clienti. Il titolare che il modulo crea per ogni cliente è quindi solo tecnico, con un indirizzo come `lsp-…-5@tuosito.it` che non riceve posta. L'email vera dell'azienda resta nella scheda del cliente.
- Un cliente può ordinare un livello senza nessun modulo: la licenza nasce senza moduli e a 0 €.
