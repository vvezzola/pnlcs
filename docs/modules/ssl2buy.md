# SSL2BUY certificates

The `ssl2buy` SSL module sells certificates through an [SSL2BUY](https://www.ssl2buy.com) reseller account. PNLCS collects the CSR, contact and validation method, places the order, and downloads the certificate once it is issued.

## Requirements

- An SSL2BUY partner account with API access, and credit on it: every live order is charged to the account balance.
- The account's login email and API key.

## Configure PNLCS

1. Open **Setup → SSL Modules** and find the SSL2BUY card.
2. Enter the login email and API key. The key is stored encrypted.
3. Keep **Test mode** on while you try things out. It uses `demo-api.ssl2buy.com`, where nothing is charged and the data is fake.
4. Save, then use **Test Connection**. It reads the account balance.
5. Choose the **Default certificate** from the drop-down.
6. Optional: set **Certificate per product** to sell different certificates from different products, as `product ID=code` pairs, for example `12=101, 13=102`.
7. Create a product of type SSL and choose SSL2BUY as its SSL module.

Once the credentials work, the card also shows the **price list**: name, product code, one-year price, extra-domain price, and whether the panel can order it. Prices come from your account, in its currency, and are refreshed once a day.

SSL2BUY has no call that lists its products, only one that prices a code, so the module asks for the codes it knows. A product SSL2BUY adds later does not appear until its code is added to `SSL2BuyModule::CATALOGUE`.

## What can be ordered

| Code | Certificate | Validation |
|---|---|---|
| 101 | AlphaSSL | Email, DNS, HTTP |
| 102 | AlphaSSL Wildcard | Email, DNS |
| 351 / 301 | Sectigo / Comodo PositiveSSL | Email, DNS, HTTP |
| 352 / 302 | Sectigo / Comodo PositiveSSL Wildcard | Email, DNS |
| 362 / 314 | Sectigo / Comodo EssentialSSL | Email, DNS, HTTP |
| 363 / 315 | Sectigo / Comodo EssentialSSL Wildcard | Email, DNS |

Other products in the price list, PrimeSSL and DigiCert brands among them, cannot be ordered from the panel. If you already hold them, status, expiry and download still work.

## Domain validation

With **DNS** or **HTTP** validation, the order page shows the record or file to publish, to both staff and the customer. The box disappears once the certificate is issued.

## Importing existing certificates

On **Clients → SSL Certificates**, use **Import existing certificates · SSL2BUY**. An order is attached to the active service with the same domain; a wildcard `*.example.com` matches a service named `example.com` and keeps its wildcard name. Orders with no matching service are skipped and counted. No customer is emailed.

## Supported operations

Order, renew (a new order for the same certificate), status polling, download, import, resend the approval email. Reissue and change of validation method work on Sectigo and Comodo orders only.

## Limits

- **Revocation and cancellation** are not in the SSL2BUY API. Do them on SSL2BUY's panel.
- **Multi-year subscriptions**: configuring the next year goes through SSL2BUY's own configuration link.
- **Not yet confirmed on a real order**: the DNS and HTTP validation values. The demo environment returns placeholders, so check them on your first paid order, or use email validation.
