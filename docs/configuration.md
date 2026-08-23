---
title: Configuration
slug: configuration
order: 20
summary: Every setting — connection, what gets recorded, the chart of accounts, invoice mode and logging.
---

## Connection

| Setting | Notes |
|---|---|
| **Access token** | Sent as `Authorization: Bearer …`. Env-parseable, and it should be an env var — a full access token reaches every business on your Wave account |
| **Business** | The Wave business id. **Test connection** lists them |
| **Timeout** | Seconds to wait on Wave. Default 20 |

**Check business** reports the business name and currency, and warns about two conditions that
would otherwise surface as a failed sync:

- **Classic accounting.** Wave's `moneyTransactionCreate` is documented as requiring
  `isClassicAccounting: false`. Transaction mode cannot work on a classic-accounting business at
  all; invoice mode is unaffected.
- **Email sending off.** Wave will not email invoices for that business.

## What gets recorded

| Setting | Notes |
|---|---|
| **Record each order as** | `A money transaction` (default) or `An invoice` (Pro) |
| **Record orders automatically** | Off leaves everything to the order screen and the console |
| **Record when** | `The order is completed`, `The order reaches a status` (Pro), or `Never — only on request` |
| **Statuses that trigger a recording** | Only used with the status trigger |
| **Only record orders paid in full** | On by default |
| **Description** | An object template rendered against the order. This is what you read in Wave's transaction list. Default: `Order {{ object.reference ?? object.shortNumber }}` |
| **Rounding tolerance** | The largest residual Waver will absorb. Default `0.05` |

### Only record orders paid in full

A money transaction asserts that cash arrived. With this on, an order that has not been captured in
full is recorded as **skipped**, with the shortfall named, rather than posted as income you have not
received.

The check counts successful `purchase` and `capture` transactions. It deliberately does **not** use
Commerce's `getOutstandingBalance()`, which nets refunds off — a fully refunded order would read as
never paid, and its sale could then never be recorded at all.

Turn it off if you invoice on terms and want the sale on the books at completion.

### Rounding tolerance

Commerce keeps money in floats; Wave takes two decimals. A multi-line order can therefore land a
cent away from its own total once every line is rounded. A residual up to this size is posted to
the rounding account so the entry balances exactly.

Anything larger is **not** rounding — it is a modelling mistake — and the entry is refused with the
drift reported rather than quietly adjusted.

## The chart of accounts

Waver never invents an account, and only asks for the ones a given order actually needs.

| Setting | What lands there | Suggested Wave subtype |
|---|---|---|
| **Payment account** | The anchor — where the money arrived | Cash & Bank, or Credit Card |
| **Sales** | The goods, with any included tax taken back out | Income |
| **Shipping** | Shipping charged to the customer. Falls back to Sales | Income |
| **Sales tax** | Tax collected on someone else's behalf | Sales Tax |
| **Discounts** | Booked as an *increase* on a contra-income account | Discount |
| **Fees** | Negative custom adjustments — a fee absorbed out of the same payment | Expense |
| **Other income** | Positive custom adjustments — a surcharge. Falls back to Sales | Other Income |
| **Rounding** | Sub-cent residuals. Falls back to Sales | Income |
| **Refunds** | The income account a refund is taken back out of. Falls back to Sales | Income |

### Why a discount is an "increase"

Wave's `INCREASE` is relative to an account's normal balance, and it **inverts on contra accounts**.
Recording *more discount* on a `Discount`-subtype account is an increase in discount, which is a
debit — less income. Waver books it that way, which is why the preview shows a discount in the
debit column.

### Per-gateway payment accounts (Pro)

Map a Commerce gateway handle to a Wave account so card money and cheques do not land in the same
place. Anything unmapped uses the default payment account.

### Businesses by store (Pro)

For a Commerce install with more than one store: map a store id to a Wave business id. Unmapped
stores use the default business.

## Customers

Wave can only be asked for a customer **by email address** — there is no lookup by name or by
external id. An order with no email is recorded without a customer rather than creating a fresh
one on every order.

| Setting | Notes |
|---|---|
| **Create Wave customers** | Create one when no existing customer matches the order's email |
| **Attach the customer to the income line** | So Wave's customer reports see the sale |

Matches are cached locally so the same email is not looked up twice. **Clear customer and product
maps** in Maintenance forgets that cache; it never deletes anything from Wave.

## Invoice mode (Pro)

Instead of a ledger entry, create a Wave invoice.

| Setting | Notes |
|---|---|
| **Create invoices as** | `Saved` or `Draft`. A draft stays out of Wave's reports until approved |
| **Approve the invoice** | |
| **Mark the invoice paid** | Records a manual payment for the amount Commerce captured |
| **Payment method** | `Work it out from the gateway`, or a fixed Wave payment method |
| **Email the invoice** | Wave sends it. Needs email sending enabled on the business |
| **Invoice number** | The order reference, number, short number, id — or let Wave number it |
| **Invoice title / Memo / Footer** | Blank uses the business defaults. Memo is an object template |
| **Create Wave products** | Create a product for a SKU Wave has never seen |
| **Income account for new products** | Wave requires `Income`, `Discount` or `Other Income` |
| **Sales tax map** | Commerce tax rate name → the Wave sales tax it means |

Read [Usage → Invoice mode](usage#invoice-mode-pro) before switching this on. Wave invoices cannot
represent everything a Commerce order can.

## Refunds (Pro)

**Record refunds** turns a successful Commerce refund into its own withdrawal in Wave, taken back
out of the refunds account. The sale and the refund stay two entries; Waver never edits or deletes
the original.

## Logging

| Setting | Notes |
|---|---|
| **Log the connection** | |
| **Keep payloads** (Pro) | Store the GraphQL body and Wave's reply on each row |
| **Keep log entries for** | Days. `0` keeps everything. Lite is capped at 7 days |

## Permissions

| Permission | Allows |
|---|---|
| View Wave records | The Records screens and the order panel |
| ↳ Send orders to Wave | **Send to Wave** and **Ask Wave** |
| ↳ Force a resend, or mark a record as recorded by hand | The two actions that can double or falsify an entry |
| View the connection log | The log screens (Pro) |

The last one is deliberately separate. Forcing a resend can post the same money twice, and marking
a record as recorded asserts something about Wave that Craft cannot verify.
