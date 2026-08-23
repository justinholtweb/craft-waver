# Waver — build plan

Wave accounting for Craft Commerce. `justinholtweb/craft-waver`, handle `waver`, namespace
`justinholtweb\waver`. Lite (free) + Pro ($99 one-off, $49/year renewal).

Sibling to `craft-freshh` (FreshBooks) and `craft-shipper` (ShipStation) and follows their
conventions; the differences below are forced by Wave's API, not by preference.

## The problem

Wave is free accounting software with a real GraphQL API, and a very large number of small Craft
Commerce stores keep their books in it by hand. Moving an order across is easy. *Accounting* for
one is not: tax that is included in the price has to come back out of income, discounts belong on
a contra account, the entry has to balance to the cent, and the same order must never land twice.

## What Wave's API allows, and what it does not

Verified against Wave's own schema — `docs/wave-api.md` records the contract and how to fetch it.

**Allows:** `moneyTransactionCreate` (BETA, needs non-classic accounting), the full invoice suite
(`invoiceCreate`, `invoiceApprove`, `invoicePaymentCreateManual`, `invoiceSend`),
`customerCreate`/`Patch`, `productCreate`, `salesTaxCreate`; reads of businesses, accounts,
customers, products, sales taxes and invoices.

**Does not allow, and this shapes the design:**

- **No way to read a money transaction back.** No `transactions` field on `Business`; the
  `Transaction` type exposes only `id`. Nothing can be found by `externalId`, date or amount.
- **No free-text invoice line.** `InvoiceCreateItemInput.productId` is `ID!`.
- **No order-level invoice charge.** So shipping cannot appear on an invoice.
- **No idempotency key**, only an `externalId` that is stored and never queryable.
- **No documented rate limit**, so no documented headroom either.

## Decisions

### Two modes, and transaction is the default

`moneyTransactionCreate` is one call, needs no Wave catalogue, and books the money exactly. That is
the right thing for a storefront that has already been paid. Invoice mode exists for merchants who
want the document, and the settings screen says out loud what invoices cannot represent.

### The entry is built once, and proved before it is sent

`services\Ledger::buildEntry()` is the only place an order becomes a payload. Commerce's totals
decompose exactly —

    totalPrice = itemSubtotal + Σ adjustments where included = false
    sales      = itemSubtotal − totalTaxIncluded
    tax        = totalTaxIncluded + excluded tax adjustments

— so the entry balances by construction, and `Entry::drift()` proves it rather than assuming it. An
entry that does not balance is refused; sub-cent residuals go to a rounding account up to a
configurable tolerance.

`models\EntryLine::ROLES` holds the accounting knowledge: each role maps to a Wave `BalanceType`
**and** a sign. Both are needed, because Wave's `INCREASE` inverts on contra accounts — a discount
is `INCREASE` on a `DISCOUNTS` account *and* a debit.

### Idempotency without an idempotency key

`externalId` is derived from the order (`waver-{installKey}-o{orderId}`), never a UUID, and
`{{%waver_records}}` is unique on it. The row is written **before** the mutation, so a crash leaves
a question rather than nothing.

Because Wave cannot be asked whether a transaction landed, a `pending` row that has already been
attempted is **never re-sent automatically**. That is the one failure mode that cannot be detected
afterwards, and auto-retrying it doubles a merchant's revenue. It is surfaced for a person, with
**Ask Wave** (which self-heals invoices and admits it cannot do the same for transactions),
**Mark as recorded**, and **Force resend** behind their own permission.

This is where Waver diverges from Freshh's claim → reconcile → create: FreshBooks can be searched
for an invoice number, so reconciliation is possible there. Here it is not, so honesty replaces it.

### Tax is booked, not attached

`MoneyTransactionCreateSalesTaxInput.amount` is required (the invoice equivalent is deprecated),
and nothing documents whether that amount is extracted from the line or added on top. The two
readings balance differently. Rather than guess, transaction mode books tax to its own sales-tax
account — arithmetic it can prove. The Wave sales-tax map is used by invoice mode, where Wave
computes the tax itself and the ambiguity does not arise.

### Fail loudly, never half-way

Missing account mappings are named per account before anything is sent. Orders that are not paid in
full are skipped with the shortfall. A business on classic accounting is reported by the settings
screen rather than by a rejected mutation on the first sale. Queue jobs never throw, because a
Craft retry is exactly the thing that must not happen.

## Data model

| Table | Key | Why |
|---|---|---|
| `{{%waver_records}}` | unique `externalId` | The idempotency guarantee, and the only evidence a transaction exists |
| `{{%waver_customers}}` | unique `(businessId, email)` | Wave has no customer lookup but email |
| `{{%waver_products}}` | unique `(businessId, sku)` | Wave products carry no SKU; this map is the only durable join |
| `{{%waver_log}}` | — | Every request and Wave's answer |

## Editions

**Lite** is fully useful on its own: balanced money transactions, automatic on completion, account
mapping, customer matching, the Records screen, manual send, a 7-day log.

**Pro ($99, $49/year renewal)** adds invoice mode, refunds, order-status triggers, per-gateway accounts, per-store
businesses, the sales-tax map, and the full log with payloads.

*(Freshh went single-edition at $79. Waver keeps the family's Lite/Pro split because its free tier
is genuinely complete; worth revisiting before release if that is not the intent.)*

## Status

All built and verified. 92 integration checks green across six consecutive runs, including one
real round trip to `gql.waveapps.com`. CP screens, order panel and console commands smoke-tested
against the plugin-testing harness.

Not done: committed to git, tagged, Packagist, marketing site, and a run against a real Wave
business (no credentials available here — every mutation path is built from the schema and proved
structurally, but has not been observed landing in a live ledger).
