# Certificati SSL2BUY

Il modulo SSL `ssl2buy` vende certificati tramite un account rivenditore [SSL2BUY](https://www.ssl2buy.com). PNLCS raccoglie CSR, contatto e metodo di validazione, invia l'ordine e scarica il certificato appena viene emesso.

## Requisiti

- Un account partner SSL2BUY con accesso API e credito disponibile: ogni ordine reale viene addebitato sul saldo dell'account.
- L'email di accesso e la chiave API dell'account.

## Configurazione

1. Apri **Setup → Moduli SSL** e cerca il riquadro SSL2BUY.
2. Inserisci email di accesso e chiave API. La chiave viene salvata cifrata.
3. Mentre fai le prove, lascia attiva la **Modalità di prova**. Usa `demo-api.ssl2buy.com`, dove non viene addebitato nulla e i dati sono fittizi.
4. Salva e premi **Prova connessione**, che legge il saldo dell'account.
5. Scegli il **Certificato predefinito** dal menu a tendina.
6. Facoltativo: compila **Certificato per prodotto** se vuoi vendere certificati diversi da prodotti diversi, con coppie `ID prodotto=codice`, ad esempio `12=101, 13=102`.
7. Crea un prodotto di tipo SSL e scegli SSL2BUY come modulo SSL.

Quando le credenziali funzionano, il riquadro mostra anche il **listino**: nome, codice prodotto, prezzo per un anno, prezzo del dominio aggiuntivo e possibilità di ordinarlo dal pannello. I prezzi sono quelli del tuo account, nella sua valuta, e si aggiornano una volta al giorno.

SSL2BUY non ha una chiamata che elenca i prodotti, ma solo una che restituisce il prezzo di un codice, quindi il modulo interroga i codici che conosce. Un prodotto aggiunto in futuro da SSL2BUY non compare finché il suo codice non viene inserito in `SSL2BuyModule::CATALOGUE`.

## Cosa si può ordinare

| Codice | Certificato | Validazione |
|---|---|---|
| 101 | AlphaSSL | Email, DNS, HTTP |
| 102 | AlphaSSL Wildcard | Email, DNS |
| 351 / 301 | Sectigo / Comodo PositiveSSL | Email, DNS, HTTP |
| 352 / 302 | Sectigo / Comodo PositiveSSL Wildcard | Email, DNS |
| 362 / 314 | Sectigo / Comodo EssentialSSL | Email, DNS, HTTP |
| 363 / 315 | Sectigo / Comodo EssentialSSL Wildcard | Email, DNS |

Gli altri prodotti del listino, tra cui i marchi PrimeSSL e DigiCert, non si possono ordinare dal pannello. Se ne possiedi già, stato, scadenza e download funzionano comunque.

## Validazione del dominio

Con la validazione **DNS** o **HTTP**, la pagina dell'ordine mostra il record o il file da pubblicare, sia allo staff sia al cliente. Il riquadro scompare quando il certificato è emesso.

## Importare i certificati esistenti

In **Servizi → Certificati SSL** usa **Importa i certificati esistenti · SSL2BUY**. Un ordine viene collegato al servizio attivo con lo stesso dominio; un wildcard `*.example.com` si collega a un servizio chiamato `example.com` e mantiene il nome con l'asterisco. Gli ordini senza un servizio corrispondente vengono saltati e conteggiati. Ai clienti non parte nessuna email.

## Operazioni supportate

Ordine, rinnovo (un nuovo ordine per lo stesso certificato), controllo dello stato, download, importazione, reinvio dell'email di approvazione. La riemissione e il cambio del metodo di validazione funzionano solo sugli ordini Sectigo e Comodo.

## Limiti

- **Revoca e annullamento** non sono previsti dall'API di SSL2BUY: si fanno dal suo pannello.
- **Abbonamenti pluriennali**: la configurazione dell'anno successivo passa dal link di configurazione di SSL2BUY.
- **Non ancora confermati su un ordine reale**: i valori di validazione DNS e HTTP. L'ambiente di prova restituisce valori fittizi, quindi controllali al primo ordine a pagamento, oppure usa la validazione via email.
