# FatturaPA (fatture elettroniche)

L'addon **FatturaPA** esporta le fatture IVA emesse in file XML FatturaPA (formato FPR12, per aziende e privati), ciascuno controllato con lo schema ufficiale 1.2.2. I file vanno poi consegnati a chi trasmette allo SDI: un servizio di fatturazione elettronica, il commercialista o un gestionale.

**PNLCS non trasmette allo SDI** e non firma i file.

## Configurazione

Attiva l'addon nella pagina **Estensioni** e compila i dati del cedente:

- Partita IVA (11 cifre, obbligatoria), codice fiscale, denominazione oppure nome e cognome, regime fiscale, indirizzo, telefono ed email.
- **Codice fiscale del trasmittente**: se vuoto, la tua partita IVA. Chi trasmette di solito lo sostituisce con il proprio.
- **Campo cliente con codice SDI o PEC**: il nome del campo personalizzato del cliente che lo contiene. Predefinito `Codice destinatario SDI / PEC`. Il campo va creato tra i campi personalizzati dei clienti.
- **Natura e riferimento normativo per le righe senza IVA**: predefinito `N2.2`.
- Esigibilità IVA, modalità di pagamento, IBAN, banca e beneficiario.

## Dove viene recapitata la fattura

| Campo del cliente | Nel file |
|---|---|
| Codice destinatario di 7 caratteri | Quel codice |
| Indirizzo PEC | `0000000` e l'indirizzo PEC |
| Vuoto | `0000000`: il cliente la trova nel proprio cassetto fiscale |
| Cliente estero | `XXXXXXX` |

Un cliente italiano deve avere partita IVA o codice fiscale, altrimenti la fattura non si può generare.

## Esportazione

Nella pagina dell'addon:

- **ZIP per periodo**: tutte le fatture IVA del periodo. Una fattura con errori resta fuori e il motivo è indicato in `ERRORI.txt`, così un dato sbagliato non blocca tutto il mese.
- **Un XML per numero di fattura**.

Proforma, bozze e fatture annullate sono escluse. Una fattura con totale negativo diventa nota di credito (`TD04`); tutte le altre sono `TD01`.

## Limiti

- Non vengono prodotte fatture verso la Pubblica Amministrazione (FPA12).
- Imposta di bollo, ritenute e altri tipi di documento non sono gestiti.
- Superare il controllo sullo schema non garantisce che lo SDI accetti il file: applica ulteriori verifiche.
- Le schermate dell'addon sono solo in italiano.
