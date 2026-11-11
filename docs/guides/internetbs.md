# Internet.bs registrar

The `internetbs` registrar module registers, transfers, renews and manages domains through an [Internet.bs](https://internetbs.net) reseller account.

## Requirements

- An Internet.bs account with API access. The **API password** is separate from the website login and is set in the Internet.bs panel.
- The PNLCS server's IP address allowed for API access, if the account restricts it.

## Configure PNLCS

In **Setup → Domain Registrars**, fill in **API Key** and **API Password**, then use the connection test, which reads the account balance.

**Test environment** is on by default and uses `testapi.internet.bs`. In live mode, registrations, transfers and renewals spend real money.

## Supported operations

Register, transfer, renew, nameservers, EPP code, registrar lock, WHOIS privacy, availability check, and synchronisation of dates, status, lock, nameservers and privacy (`pnlcs:domain-sync`).

Details worth knowing:

- A renewal sends the expiry date PNLCS holds. Internet.bs refuses it if that date differs from its own, so a retried renewal cannot add a second year. If a renewal is refused, sync the domain first.
- For registries where the expiry date can lag behind what has been paid, `.it` among them, the sync prefers the paid-until date.
- `.it` registrations and transfers send the extra registry fields built from the client's record: entity type, nationality, province and tax code. Keep the client's country, province and tax details correct.
