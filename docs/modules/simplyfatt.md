# SimplyFatt (SF-XML)

The **SimplyFatt** addon exports the VAT invoices of a period as one SF-XML file, for SimplyFatt's **Importa documenti SF-XML**. PNLCS bills; SimplyFatt imports the documents and transmits them to the SDI as before. This addon is specific to Esalogica and is not part of upstream PNLCS.

## Configure PNLCS

Activate the addon on the **Extensions** page:

| Field | Default |
|---|---|
| Client field with the SimplyFatt code | `Codice SimplyFatt` |
| Client field with SDI code or PEC | `Codice destinatario SDI / PEC` |
| Payment name in SimplyFatt | `Bonifico 30gg FM` |
| Bank, bank coordinates | Empty |
| SimplyFatt VAT code for lines without VAT | `N2.2` |
| Footer notes | Empty |

The payment name must match one that exists in SimplyFatt.

## Exporting

Choose the dates on the addon page and press **Scarica SF-XML**. Proformas, drafts and cancelled invoices are excluded. A negative total is written as `Nota di credito`.

## Before the first real import

The layout follows a file exported by SimplyFatt 2.9, but the import has not yet been tried in SimplyFatt.

1. Back up SimplyFatt, or use a test company.
2. Import a test file and check customer, number and series, amounts and VAT.
3. **Do not send the test invoice to the SDI.** Delete it afterwards.
4. Confirm two guessed values: the credit note type `Nota di credito` and the VAT code `N2.2`.
