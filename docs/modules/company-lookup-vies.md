# Company lookup: EU VAT numbers (VIES)

The Company Lookup addon fills in a client's company details from a tax number. Besides the Polish registries, it answers for VAT numbers of other EU countries through the European Commission's **VIES** service, which is free and needs no key.

## How it is used

In the company lookup field, either:

- type the number with its country prefix, for example `IT01234567890`; or
- type it without prefix on a client whose country is an EU country other than Poland.

Name and address are filled in where the member state shares them. Italian addresses are split into street, postal code, city and province; for other countries, street and, when recognisable, postal code and city.

Polish numbers keep using the Polish registries. Greece is `EL` in VIES; `GR` is accepted.

## What VIES does not cover

VIES lists only VAT numbers **registered for intra-EU trade**, and in Italy that registration is optional. A valid Italian company that does not trade within the EU comes back as "not found". That does not mean the number is wrong.

If a member state's register is down, PNLCS says VIES did not answer: try again in a minute. Successful answers are cached.
