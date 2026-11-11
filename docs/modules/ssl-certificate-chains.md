# SSL certificate chains

Providers return the intermediate chain in many shapes: missing a certificate, out of order, with the root included, or not at all. PNLCS rebuilds it for every SSL module, so what you download or paste installs correctly.

## How the chain is rebuilt

Starting from the domain's certificate, PNLCS looks for each issuer among the certificates the provider sent. If one is missing, it downloads it from the **CA Issuers** address written in the certificate (PEM, DER or PKCS#7; downloads are kept for a week). It stops at the self-signed root, which is left out, as web servers expect. Each link is checked by signature.

The rebuild runs when a certificate is polled, imported, downloaded, or when its page is opened. If no complete path to a root can be found, the provider's data is kept as it was and the event is logged.

The server running PNLCS must be able to reach the certificate authorities' `http` addresses for missing intermediates to be fetched.

## Installing a certificate

The certificate's page, for staff and for the customer, has an **Install on the server** box with three fields, in the order a control panel asks for them, each with a **Copy** button:

1. **Private key (\*.key)**
2. **Certificate (\*.crt)**
3. **CA certificate (\*-ca.crt)**

In Plesk, paste them under **SSL/TLS Certificates → Upload the certificate as text**.

- If the CSR was generated outside PNLCS, the panel has no private key and the field is not shown. The key stays where the CSR was created: in Plesk, open the existing certificate request and paste only the certificate and the CA certificate.
- Staff without the `manage_services` permission do not see the private key.

The download ZIP holds the same data as `.crt`, `.ca-bundle`, `.fullchain.crt` and, when the panel has it, `.key`. Wildcard certificates use `wildcard.example.com` in the file names, because Windows refuses a `*`.

## Checking a site

```
echo | openssl s_client -connect www.example.com:443 -servername www.example.com 2>/dev/null | grep "Verify return"
```

`Verify return code: 0 (ok)` means the server sends a complete chain. Code 21, `unable to verify the first certificate`, means the CA certificate was not installed.
