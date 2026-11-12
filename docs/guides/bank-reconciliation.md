# Bank Reconciliation (Qonto)

The **Bank Reconciliation** addon reads the incoming transfers on a Qonto business account and pays them onto the invoices they name. Customers who pay by bank transfer no longer need you to mark each invoice paid by hand.

## How a transfer is matched

Every 15 minutes, with the cron runner active, or when you press **Read the account now**, PNLCS reads the completed incoming transfers on the account. In each transfer's text it looks for:

1. the number of an open invoice, however the customer spaced it (`ESA 27 0001` matches `ESA270001`);
2. the payment reference printed in the bank transfer instructions (`payment_ref()`: the prefix set in **PaymentReferencePrefix** followed by the invoice id, e.g. `INV1234`).

| What the transfer carries | What happens |
|---|---|
| One or more open invoices, and exactly the amount they owe together | Paid: each invoice gets its balance, as a Bank Transfer payment, and whatever waited on it (a renewal, provisioning) runs |
| Open invoices, but a different amount (a partial payment, or more) | Waits for review, with those invoices proposed |
| No invoice number or reference | Waits for review, with the open invoices that owe exactly that amount proposed |
| Invoices that are no longer open | Waits for review |

With **Pay matched transfers automatically** off, the default, even a certain match waits for you. Watch the proposals for a while, then turn it on.

A customer paying several invoices from the client area (Mass Pay) gets one payment invoice; its number in the transfer pays all of them.

## Review

The addon page lists the transfers waiting: date, sender, amount, transfer text, why it waits, and the proposed invoices. Tick the invoices it pays, or type another invoice number, and press **Record payment**. The amount goes to the invoices in order, each up to what it owes; anything left over becomes the customer's credit. **Set aside** keeps a transfer that pays no invoice (a refund, a transfer between your own accounts) out of the list.

Every transfer is recorded once, by its Qonto id: reading the account again never pays anything twice.

## Set it up

1. In the Qonto web app: **Integrations and partnerships → API key → Generate**. It gives a login and a secret key. The key can only read; it cannot move money.
2. Activate **Bank Reconciliation** under **Extensions** and fill in the Qonto login, the secret key and the IBAN of the account the transfers arrive on.
3. Press **Read the account now** and check the list.

To try it first, create a sandbox organization on the [Qonto developer portal](https://developers.qonto.com/), turn on **Qonto sandbox** and enter the portal's staging token.

**Days to read on the first run** (30 by default) sets how far back the first reading goes; later readings continue from the last transfer read.
