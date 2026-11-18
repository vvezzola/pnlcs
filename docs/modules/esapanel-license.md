# Esapanel licences

The **Esapanel License** module sells Esapanel licences from your PNLCS. It creates, renews and ends them on Esalogica's License Server (`license.esalogica.com`), through the reseller account Esalogica gave you. The customer gets the activation code and the install command in their client area.

## What you need

- The module in the `modules/Servers/EsapanelLicense` folder of your PNLCS. PNLCS finds it on its own: there is nothing to register.
- The **API key** of your reseller account on the License Server, from Esalogica.
- A product group for the licences, for example "Esapanel" (Products/Services, New group).

## 1. The License Server

In **Setup, Servers**, add a server:

- type: **Esapanel License**
- hostname: `license.esalogica.com`
- port: `9943`
- API key: the key of your reseller account

"Test connection" must answer that the connection works.

## 2. The products: the Esapanel catalogue

Open **Esapanel licences** in the top menu. On the page:

1. pick the License Server and the product group;
2. write your prices, per month and per year, without VAT, for each module and level. A module left empty at a level is not sold there;
3. press **Preview** to see what would be made or changed;
4. press **Create or update**.

The catalogue makes:

- **one product per level**: Esapanel Company, Integrator 30, Integrator 60, Integrator 100 and Provider, each on the License Server plan of the same name. The level by itself costs 0: the price is the sum of the modules chosen;
- **the modules as options** of the product: CSP, CSPH, UCP, DRP, WFP and WFPH. On the Company each module is a drop-down with "None" and the two sizes (for example 25 or 100 devices, 2 TB or unlimited). On the other levels it is a checkbox;
- **the free trial** "Esapanel Trial", once the License Server offers the "LSP Trial" plan. It lasts 15 days, includes every module, and is one per server.

Without the **On sale** tick, new products are made hidden: you show them when you want, from the same page or from the product itself.

The page can be used again at any time: it adds what is missing and changes the prices that differ, and deletes nothing. Products, options and prices stay editable one by one, from Products/Services and Configurable options.

## How selling works

- **Order paid**: the module creates the customer on the License Server (or finds the one already there, by VAT number or email) and the licence with the level and modules chosen. It runs until the service's next due date.
- **Renewal paid**: the licence's last day moves to the end of the new period.
- **Change of modules, size or level** (an options or product upgrade): the licence is brought in step. What is missing is added first, then the rest is removed. If the License Server refuses, for example removing a module whose use is above its limit, the reason shows on the service page.
- **Suspension and reactivation** follow the service's. **Termination** revokes the licence and deletes nothing. If the service is created again, the same licence comes back with the same code.
- **The customer moves server**: the service has a button that frees the licence from the server it is bound to.

## Good to know

- Each reseller sees only its own customers on the License Server. The references PNLCS writes carry your site's name, so they never clash with another partner's.
- If the same customer already bought from another partner with the same email, the owner is made with an alias of that email (`name+pnlcs-…@domain`). The owner never signs in to the License Server, so the alias gets no mail.
- A customer can order a level with no module: the licence is then made with no module, at 0.
