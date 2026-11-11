# PEC (Italian certified email)

The **PEC** addon sends certified messages to clients from your PEC mailbox and keeps the provider's acceptance and delivery receipts, which are the legal proof of sending.

## Requirements

- A PEC mailbox with SMTP and IMAP access.
- Run `php artisan migrate` after installing: the addon uses the `pec_messages` table.
- The Laravel scheduler running, to read receipts automatically.

## Configure PNLCS

Activate the addon on the **Extensions** page and fill in:

| Field | Notes |
|---|---|
| PEC address | The mailbox messages are sent from; it also receives the receipts |
| Mailbox password | Stored as a secret |
| SMTP server and port | Default `smtps.pec.aruba.it`, `465` |
| IMAP server and port | Default `imaps.pec.aruba.it`, `993` |
| Client custom field holding the PEC address | Links each message to its client. Left empty, the client's email is matched |

The defaults are Aruba's servers. Change them for another provider. IMAP is always over TLS.

## Sending

On the addon page, enter the recipients (one per line, or separated by commas or semicolons), the subject, the message and up to 5 attachments of 20 MB each. The message is plain text.

Each recipient gets a separate message, so each has its own delivery receipt. A refused recipient does not stop the others; refusals are shown after sending. Sending requires the `edit_clients` permission.

## Receipts

`pnlcs:pec-receipts` runs every 10 minutes while the addon is active, or press **Check receipts now**. It searches the mailbox for receipts of messages sent in the last 30 days and moves each message along:

| Status | Meaning |
|---|---|
| Sent | Handed to the provider, no receipt yet |
| Accepted | Acceptance receipt received |
| Delivered | Delivery receipt received |
| Failed | Non-acceptance, delivery error or virus detected |

Receipts are stored unchanged as `.eml` files under `storage/app/pec/` and can be downloaded from the list. Keep them in your backups.

## Limits

- The page shows the latest 50 messages.
- Sending to many recipients happens during the request, one at a time. Keep batches small.
- **Not yet tested against a real PEC mailbox.** Send a first message to a PEC address of your own and check that its receipts arrive.
