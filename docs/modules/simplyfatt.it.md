# SimplyFatt (SF-XML)

L'addon **SimplyFatt** esporta le fatture IVA di un periodo in un unico file SF-XML, da usare con **Importa documenti SF-XML** di SimplyFatt. PNLCS fattura; SimplyFatt importa i documenti e li trasmette allo SDI come sempre. L'addon è specifico per Esalogica e non fa parte di PNLCS ufficiale.

## Configurazione

Attiva l'addon nella pagina **Estensioni**:

| Campo | Predefinito |
|---|---|
| Campo cliente con il codice SimplyFatt | `Codice SimplyFatt` |
| Campo cliente con codice SDI o PEC | `Codice destinatario SDI / PEC` |
| Nome del pagamento in SimplyFatt | `Bonifico 30gg FM` |
| Banca, coordinate bancarie | Vuoti |
| Codice IVA SimplyFatt per le righe senza IVA | `N2.2` |
| Note a piè di pagina | Vuote |

Il nome del pagamento deve corrispondere a uno esistente in SimplyFatt.

## Esportazione

Scegli le date nella pagina dell'addon e premi **Scarica SF-XML**. Proforma, bozze e fatture annullate sono escluse. Un totale negativo viene scritto come `Nota di credito`.

## Prima della prima importazione reale

Il tracciato segue un file esportato da SimplyFatt 2.9, ma l'importazione non è ancora stata provata in SimplyFatt.

1. Fai un backup di SimplyFatt, oppure usa un'azienda di prova.
2. Importa un file di prova e controlla cliente, numero e serie, importi e IVA.
3. **Non inviare allo SDI la fattura di prova.** Poi cancellala.
4. Conferma due valori ipotizzati: il tipo `Nota di credito` e il codice IVA `N2.2`.
