# Waver — Craft CMS 5 Plugin

## Project Overview

Waver records Craft Commerce orders in [Wave](https://www.waveapps.com) accounting, so a merchant
stops re-keying their storefront into their books. Distributed as `justinholtweb/craft-waver`.
**Lite (free) + Pro ($99 one-off, $49/year renewal).**

## Why it exists

Wave is free accounting software with a real API, and a very large number of small Craft Commerce
stores keep their books in it by hand. Zapier can push an order across; what it cannot do is
*account* for one — split the tax out of an included-tax price, book a discount to a contra
account, balance the entry, and refuse to post when it does not balance.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, **Craft Commerce 5.0+**, Yii2, Twig
- No build step: no asset bundles, no JS beyond inline `{% js %}` blocks
- No runtime dependencies beyond Craft's own Guzzle

## Architecture

### Namespace & package

- Namespace: `justinholtweb\waver`
- Package: `justinholtweb/craft-waver`
- Handle: `waver`

### The two invariants

1. **`services\Ledger::buildEntry()` is the only place an order becomes a Wave payload.** The CP
   preview, the console `--dry-run`, the queue job and every retry go through it, so a preview is
   byte-identical to what Wave receives. A preview that is merely *representative* is worse than
   none — it is the thing people trust before signing off on a quarter.
2. **`services\Records::record()` is the only place a record row is created, and the only place an
   order is sent.** The unique index on `externalId` is what makes a duplicate impossible rather
   than unlikely.

### The fact that shapes everything

**Wave's API cannot read a money transaction back.** There is no `transactions` field on
`Business`; the `Transaction` type exposes exactly one field, `id`. Nothing can be looked up by
`externalId`, by date or by amount. Consequences, all deliberate:

- The record row is written **before** the mutation. A process that dies mid-call leaves a
  `pending` row with `attempts = 1` — a question — rather than nothing at all.
- A `pending` row that has already been attempted is **never retried automatically**, by the queue,
  by `waver/sync/retry`, or by anything else. It might be a failure, or it might be a posted
  transaction whose response was lost, and nothing can tell the two apart. Auto-retrying doubles a
  merchant's revenue in the one case that cannot be detected afterwards.
- `externalId` is derived from the order (`waver-{installKey}-o{orderId}`), never a UUID, so a
  retry produces the same id and Waver's row and Wave's stored id describe the same fact.

Invoices are the exception — they *can* be found again by number — so `Records::resolveDoubtful()`
self-heals an invoice record and says plainly that it cannot do the same for a transaction.

### The accounting

Commerce's totals decompose exactly, and that is what makes the entry balance without fudging:

    totalPrice = itemSubtotal + Σ adjustments where included = false

`itemSubtotal` already contains any tax that is *included* in the price — Commerce says included
tax "does not affect total price", meaning it sits inside the line items rather than beside them.
So `sales = itemSubtotal − totalTaxIncluded` and `tax = totalTaxIncluded + excluded tax
adjustments`. Substituting back gives `totalPrice`, which is what the anchor deposits.

`models\EntryLine::ROLES` is the whole of the accounting knowledge: each role maps to a Wave
`BalanceType` **and** a sign, because `balance` alone cannot say whether a line adds to or
subtracts from what the anchor received. Wave's `INCREASE` inverts on contra accounts, so a
discount is `INCREASE` on a `DISCOUNTS` account *and* a debit.

Sub-cent residuals are absorbed onto a rounding account up to `roundingTolerance`; beyond it the
entry is refused, because at that point it is not rounding.

### Data model

- `{{%waver_records}}` — unique on `externalId`; that index *is* the idempotency guarantee.
- `{{%waver_customers}}` — email → Wave customer id. Email is the join key because Wave has no
  other customer lookup.
- `{{%waver_products}}` — SKU → Wave product id. **Wave products carry no SKU**, so this map is the
  only durable join; clearing it duplicates the catalogue.
- `{{%waver_log}}` — the connection log.

## Protocol notes (verified, not guessed)

Read out of Wave's own developer portal and cross-checked against three independent clients
(`jeffgreco13/laravel-wave`, `amritms/waveapps-client-php`, Pipedream's `components/wave`). The
portal 403s bots, but it runs on Zendesk and the help-centre API is open — see `docs/wave-api.md`,
which records the full contract and the `curl` that fetches it.

- **A 200 is not a success.** Business-rule failures come back as `didSucceed: false` plus
  `inputErrors[]` with **no top-level `errors` key and an HTTP 200**. Anything that checks the
  status code records a sale that never happened.
- **`sort` is non-null** on `customers`, `products` and `invoices` (`[CustomerSort!]!`). Omitting
  it is a validation error, not a default.
- **The line items must balance the anchor**, all amounts positive, ≤ 2 dp, direction carrying the
  sign. Anchor accounts are `CASH_AND_BANK`, `CREDIT_CARD`, `LOANS`; they must not appear as line
  items.
- **`moneyTransactionCreate` is BETA and requires `isClassicAccounting: false`.** The settings
  screen's "Check business" says so up front rather than letting the first sale fail.
- **`InvoiceCreateItemInput.productId` is `ID!`** — Wave has no free-text invoice line. That is why
  invoice mode needs a product map and transaction mode does not. There is also no order-level
  charge, so shipping cannot be represented on an invoice at all; Waver says so instead of quietly
  dropping it from the total.
- **`MoneyTransactionCreateSalesTaxInput.amount` is required**, while the invoice equivalent is
  deprecated — and nothing documents whether that amount is extracted from the line or added on
  top. The two readings balance differently, so Waver does not attach sales taxes to transaction
  lines at all; it books tax to its own account, which is arithmetic it can prove.
- **Wave's `invoiceNumber` filter is a *contains* match** — its own docs warn that `12` finds `112`
  and `120`. `resolveDoubtful()` only accepts an exact hit.
- **`AddressInput.provinceCode` is ISO 3166-2** (`US-NC`), while Craft stores `NC`.
- Wave publishes **no rate limit**, so Waver paces itself rather than assuming headroom: 429 and
  5xx retry with a widening gap, everything else is final.

## Traps found while building this

- **`Order::getOutstandingBalance()` nets refunds off**, because it is built on `getTotalPaid()`.
  Using it for the "paid in full" gate made a fully refunded order read as never paid, so its sale
  could never be recorded. `Ledger::amountCaptured()` counts successful purchase and capture
  transactions only.
- **A DB column with no matching model property is a fatal**, not a warning: rows are hydrated
  straight into `models\Record`, so a missing `dateUpdated` property threw
  `Setting unknown property` on every read. Found by the suite, not in production.
- **A private property is not a Yii attribute**, so a setting backed by a getter/setter pair is
  never persisted unless `attributes()` names it. Needed for the three maps, which post as
  editable-table rows and are stored as maps.
- **Commerce recalculates adjustments on every save** in the default mode, throwing away anything
  set by hand. A fixture has to complete the order first and then save with
  `RECALCULATION_MODE_NONE`.
- **`Transactions::createTransaction()` reads `$order->getGateway()->id` with no null check**, so
  an order with no gateway fatals there.
- **Commerce refuses an address element it does not own** — pass an array of attributes and let
  Commerce build the owned element.
- **Craft plugin console commands are not reachable via `craft help <handle>`** — they are listed
  under `craft help` and run as `waver/sync/order`.
- **`plugin/switch-edition` is not a Craft console command.** Switching editions from a script
  means `Plugins::switchEdition()` plus `ProjectConfig::saveModifiedConfigData()`.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps, and
`[[project_craft_shipper]]` and `[[project_craft_freeride]]` for the sibling Commerce plugins whose
conventions this follows.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-waver/tests/integration/checks.php   # 92 checks
ddev exec bash -c 'find /var/www/craft-waver/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

The suite switches to Pro for the bulk of the run, exercises Lite behaviour in its own section, and
restores the original edition, settings and every fixture in a `finally`.

There are no Wave credentials here and no sandbox a script can create, so the suite proves the two
things that can be proved without one — **the arithmetic**, exhaustively, and **the payload**, field
by field against `docs/wave-api.md`. It also makes one real round trip to `gql.waveapps.com` with a
deliberately bad token, which exercises the endpoint, the auth header and the error decoding for
free; that check reports as *skipped* rather than failed when the container has no outbound
network.

**Harness notes.** Two things in this shared harness are not Waver's doing and will waste an hour
if you meet them cold:

- `ddev exec` re-checks that the project is running, and the web container takes longer to pass its
  health check than ddev waits (≈100 bind mounts plus a `chown -R` over the global cache). That
  turns into a start → timeout → recreate loop. Run
  `docker exec -w /var/www/html ddev-plugin-testing-web php …` instead.
- `craft-my`'s `_order-panel.twig` has a Twig syntax error, which 500s **every** Commerce order
  edit screen in the harness. Waver's own panel was verified by rendering it directly.

**Harness note:** `craft-penny` registers an `Elements::EVENT_BEFORE_SAVE_ELEMENT` handler typed
`ModelEvent` while Craft passes an `ElementEvent`, so **every element save in the harness fatals**
while it is enabled. `checks.php` detaches that handler in-process (never persisted). That is a real
bug in Penny, not in Waver.

## Coding conventions

- `Craft::t('waver', '…')` for user-facing strings; `src/translations/en/waver.php` lists them
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- Never mark plugin settings `required`
- Queue jobs never throw: a Craft retry is exactly the thing that must not happen here
