# Esapanel services

The **Esapanel** module sells an Esapanel server's services from your PNLCS as subscriptions, for example EsaBackup with the WFP module. It creates the customer and the subscription on Esapanel, suspends, resumes and ends it, and changes its plan and add-ons, as it would for a Plesk server. The customer manages the service both from their client area and in Esapanel.

Esapanel licences are sold with another module, **Esapanel License**.

## What you need

- The module in the `modules/Servers/Esapanel` folder of your PNLCS. PNLCS finds it on its own: there is nothing to register.
- An **Esapanel server** with at least one customer module active, for example WFP, and its plans for customers.
- An **API key** of that server. Make it in Esapanel, under **Organization, API accounts**: choose a role that may manage customers, subscriptions, plans and the module's pages (for WFP, its accounts), then copy the key, which is shown only once.
  - If you are an Esapanel **reseller**, the key is your reseller account's: PNLCS sells your plans to your customers, within the limits of your reseller plan.
  - If you are the server's **admin**, the key is the admin's: besides the customer plans, PNLCS can also sell reseller plans.

## 1. The Esapanel server

In **Setup, Servers**, add a server:

- type: **Esapanel**;
- hostname: the name your customers sign in to Esapanel on. If you are a reseller with a panel name of your own, use that name: your customers can sign in only there;
- port: `9943`, unless the server uses another;
- API key: the key made in the step above.

"Test connection" must say the connection works.

## 2. The products

Make one product for each plan you want to sell. In the product's module settings choose the Esapanel server and, as the package, the plan. Plans are listed with their module in front, for example `WFP · WFP Professional`. Only the modules the server has active, and that your key may work on, are listed. With the admin's key the reseller plans are listed too, for example `WFP · Reseller · WFP Reseller`.

You set the price in the product, as for any other service.

### A free trial with an end

A free product with `"trial_days": 30` in its module configuration is a trial: that many days after it starts, the module suspends the subscription on Esapanel. The copies stay, and the customer sees that the trial is over. If you move the service to a paid product, the subscription is active again with its copies. A free product can be ordered once per customer.

### Add-ons as options

Esapanel's add-ons, for example more space or more accounts, are sold with the product's **configurable options**:

- the option's name starts with `addon|`, for example `addon|Extra space`;
- each choice has the add-on's exact Esapanel name before the bar, and the words the customer sees after it: `WFP Space +100 GB|100 GB more`, `WFP Space +1 TB|1 TB more`;
- the "no add-on" choice is `none|None`.

Each choice's price is the option's. A product with no `addon|` option leaves the subscription's add-ons alone: those set by hand in Esapanel stay.

## How selling works

- **Order paid**: the module finds the customer on Esapanel, by PNLCS's reference, VAT number or email, or creates them. It then creates the subscription on the product's plan, with the chosen add-ons. A new customer gets an email from Esapanel with a link to choose their password.
- **Product or option change** (upgrade and downgrade): the subscription moves to the new plan and the add-ons are brought in line. If Esapanel refuses, for example because the customer uses more space than the new plan gives, the reason shows on the service page.
- **Suspend and resume** follow the service's.
- **Termination**: the subscription is **disabled, not deleted**. The customer's data stays on the server until you remove it in Esapanel. If the service is created again, the same subscription comes back.
- Every hour the module reads the subscriptions back, a few at a time: status, plan, add-ons and, for WFP, space used, accounts and latest copies.

### Reseller plans

A product on a reseller plan, sold with the admin's key, creates a **reseller** on Esapanel instead of a customer, with its reseller subscription. The reseller then makes their own plans and customers in Esapanel, under their brand. They can also sell from their own PNLCS with this same module and an API key of their account.

If the client is already a customer on Esapanel, for example because they already have EsaBackup, the module **converts them into a reseller**: the owner's email is unique on Esapanel, so a reseller cannot be made beside the customer. Their customer subscriptions stay theirs and keep working.

## What the customer sees

On the service page, in the client area:

- status, plan, add-ons and the **username** to sign in to Esapanel, with the **Open Esapanel** button. Esapanel has no sign-in from another system: the customer signs in with the password they chose from the link, or with "Forgot password";
- for **WFP** (EsaBackup):
  - the space used out of the space available, with a notice when it is full;
  - the server and ports to connect with rsync, SFTP, FTPS and WebDAV;
  - the accounts with their latest copy and a notice when the expected copy has not arrived;
  - the actions on the accounts: **new account**, **new password**, **suspend** and **resume**. The password is shown only once and PNLCS does not keep it;
- for a **reseller plan**: the plan's limits and how much of them their customers use.

The other modules (UCP, DRP, CSP) are already sold and managed. Their client-area screens come with the next versions: until then the customer manages them in Esapanel.

A customer's sub-user can use the actions on the accounts only with the permission to manage products.

## Good to know

- A reseller key sees only that reseller's customers. The references and usernames PNLCS writes carry your site's name, for example `c-1a2b3c-5`, so they never mix with another partner's.
- The owner of a customer the module creates is the customer themselves, with their email. On Esapanel that email must be free: if someone else already uses it, creation stops with Esapanel's message.
- The module never deletes anything on Esapanel.
