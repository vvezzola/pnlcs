# Servizi Esapanel

Il modulo **Esapanel** vende dal tuo PNLCS i servizi di un server Esapanel come subscription, per esempio EsaBackup con il modulo WFP. Crea il cliente e la subscription su Esapanel, la sospende, la riattiva, la cessa e ne cambia piano e addon, come per un server Plesk. Il cliente gestisce il servizio sia dalla sua area clienti sia in Esapanel.

Le licenze Esapanel si vendono con un altro modulo, **Esapanel License**.

## Cosa serve

- Il modulo nella cartella `modules/Servers/Esapanel` del tuo PNLCS. PNLCS lo trova da solo: non c'è niente da registrare.
- Un **server Esapanel** con almeno un modulo per clienti attivo, per esempio WFP, e i suoi piani per i clienti.
- Una **chiave API** di quel server. La crei in Esapanel, in **Organizzazione, Account API**: scegli un ruolo che possa gestire clienti, subscription, piani e le pagine del modulo (per WFP, i suoi account), poi copia la chiave, che viene mostrata una sola volta.
  - Se sei un **reseller** di Esapanel, la chiave è quella del tuo account reseller: PNLCS vende i tuoi piani ai tuoi clienti, entro i limiti del tuo piano reseller.
  - Se sei l'**admin** del server, la chiave è quella dell'admin: oltre ai piani per i clienti, PNLCS può vendere anche i piani reseller.

## 1. Il server Esapanel

In **Setup, Server**, aggiungi un server:

- tipo: **Esapanel**;
- nome host: il nome con cui i tuoi clienti entrano in Esapanel. Se sei un reseller con un tuo nome di pannello, usa quel nome: i tuoi clienti possono entrare solo da lì;
- porta: `9943`, se il server non ne usa un'altra;
- chiave API: la chiave creata al passo precedente.

"Prova connessione" deve rispondere che la connessione funziona.

## 2. I prodotti

Crea un prodotto per ogni piano che vuoi vendere. Nel modulo del prodotto scegli il server Esapanel e, come pacchetto, il piano. I piani sono elencati con il loro modulo davanti, per esempio `WFP · WFP Professional`. Compaiono solo i moduli che il server ha attivi e su cui la tua chiave può lavorare. Con la chiave dell'admin compaiono anche i piani reseller, per esempio `WFP · Reseller · WFP Reseller`.

Il prezzo lo decidi tu, nel prodotto, come per qualunque altro servizio.

### Una prova gratuita a tempo

Un prodotto gratuito con `"trial_days": 30` nella configurazione del modulo è una prova: dopo quei giorni dall'attivazione il modulo sospende la subscription su Esapanel. Le copie restano, e il cliente vede che la prova è finita. Se sposti il servizio su un prodotto a pagamento, la subscription torna attiva con le sue copie. Un prodotto gratuito si ordina una volta sola per cliente.

### Gli addon come opzioni

Gli addon di Esapanel, per esempio altro spazio o altri account, si vendono con le **opzioni configurabili** del prodotto:

- il nome dell'opzione comincia con `addon|`, per esempio `addon|Spazio aggiuntivo`;
- ogni scelta ha davanti alla barra il nome esatto dell'addon in Esapanel, e dopo la barra il testo che vede il cliente: `WFP Space +100 GB|100 GB in più`, `WFP Space +1 TB|1 TB in più`;
- la scelta "nessun addon" è `none|Nessuno`.

Il prezzo di ogni scelta è quello dell'opzione. Un prodotto senza opzioni `addon|` non tocca gli addon della subscription: quelli messi a mano in Esapanel restano.

## Come funziona la vendita

- **Ordine pagato**: il modulo ritrova il cliente su Esapanel, dal riferimento di PNLCS, dalla partita IVA o dall'email, oppure lo crea. Poi crea la subscription sul piano del prodotto, con gli addon scelti. Un cliente nuovo riceve da Esapanel un'email con il link per scegliere la password.
- **Cambio di prodotto o di opzioni** (upgrade e downgrade): la subscription passa al nuovo piano e gli addon vengono allineati. Se Esapanel rifiuta, per esempio perché il cliente usa più spazio di quanto dia il nuovo piano, il motivo compare nella pagina del servizio.
- **Sospensione e riattivazione** seguono quelle del servizio.
- **Cessazione**: la subscription viene **disattivata, non cancellata**. I dati del cliente restano sul server finché non li togli tu in Esapanel. Se il servizio viene creato di nuovo, torna la stessa subscription.
- Ogni ora il modulo rilegge le subscription, alcune per volta: stato, piano, addon e, per WFP, spazio usato, account e ultime copie.

### Piani reseller

Un prodotto su un piano reseller, venduto con la chiave dell'admin, crea su Esapanel un **reseller** al posto di un cliente, con la sua subscription reseller. Il reseller crea poi i suoi piani e i suoi clienti in Esapanel, con il suo marchio. Può anche vendere dal suo PNLCS con questo stesso modulo e una chiave API del suo account.

Se il cliente è già cliente su Esapanel, per esempio perché ha già EsaBackup, il modulo lo **trasforma in reseller**: in Esapanel l'email del titolare è unica, quindi non si può creare un reseller accanto al cliente. Le sue subscription da cliente restano sue e continuano a funzionare.

## Cosa vede il cliente

Nella pagina del servizio, nell'area clienti:

- stato, piano, addon e il **nome utente** per entrare in Esapanel, con il pulsante **Apri Esapanel**. Esapanel non ha l'accesso automatico da un altro sistema: il cliente entra con la password che ha scelto dal link, o con "Password dimenticata";
- per **WFP** (EsaBackup):
  - lo spazio usato su quello disponibile, con l'avviso quando è pieno;
  - il server e le porte per collegarsi con rsync, SFTP, FTPS e WebDAV;
  - gli account con l'ultima copia arrivata e l'avviso se la copia attesa non è arrivata;
  - le azioni sugli account: **nuovo account**, **nuova password**, **sospendi** e **riattiva**. La password viene mostrata una sola volta e PNLCS non la conserva;
- per un **piano reseller**: i limiti del piano e quanto ne usano i suoi clienti.

Gli altri moduli (UCP, DRP, CSP) si vendono e si gestiscono già. Le loro schermate nell'area clienti arriveranno con le prossime versioni: fino ad allora il cliente li gestisce in Esapanel.

Un sotto-utente del cliente può usare le azioni sugli account solo con il permesso di gestire i prodotti.

## Da sapere

- Una chiave reseller vede solo i clienti di quel reseller. I riferimenti e i nomi utente che PNLCS scrive portano il nome del tuo sito, per esempio `c-1a2b3c-5`, quindi non si confondono con quelli di altri partner.
- Il titolare di un cliente creato dal modulo è il cliente stesso, con la sua email. In Esapanel quell'email deve essere libera: se un'altra persona la usa già, la creazione si ferma con il messaggio di Esapanel.
- Il modulo non cancella mai niente su Esapanel.
