# FatturaPA (Italian e-invoices)

The **FatturaPA** addon exports issued VAT invoices as FatturaPA XML files (format FPR12, for businesses and private buyers), each checked against the official schema 1.2.2. You then hand the files to whoever transmits to the SDI: an e-invoicing service, an accountant or an accounting program.

**PNLCS does not transmit to the SDI** and does not sign the files.

## Configure PNLCS

Activate the addon on the **Extensions** page and fill in the seller's details:

- VAT number (11 digits, required), fiscal code, company name or first and last name, tax regime, address, phone and email.
- **Transmitter fiscal code**: empty means your VAT number. Whoever transmits usually replaces it with their own.
- **Client field with SDI code or PEC**: the name of the client custom field holding it. Default `Codice destinatario SDI / PEC`. Create that field under custom client fields.
- **Nature and legal reference for lines without VAT**: default `N2.2`.
- VAT chargeability, payment method, IBAN, bank and beneficiary.

## Where the invoice is delivered

| Client's field | File contains |
|---|---|
| 7-character recipient code | That code |
| PEC address | `0000000` and the PEC address |
| Empty | `0000000`: the buyer finds it in their tax drawer |
| Foreign buyer | `XXXXXXX` |

An Italian buyer needs a VAT number or a fiscal code; without one the invoice cannot be built.

## Exporting

On the addon page:

- **ZIP for a date range**: every VAT invoice in the period. An invoice that fails stays out, with its reason in `ERRORI.txt`, so one bad record does not hold back the month.
- **One XML by invoice number**.

Proformas, drafts and cancelled invoices are excluded. An invoice with a negative total becomes a credit note (`TD04`); all others are `TD01`.

## Limits

- Public administration invoices (FPA12) are not produced.
- Stamp duty, withholding tax and other document types are not handled.
- Passing the schema does not guarantee the SDI accepts the file: it applies further checks.
- The addon's own screens are in Italian only.
