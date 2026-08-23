# Waver

Wave accounting for Craft Commerce. Every order lands in Wave once, balanced, with the tax split
out and the discounts booked where an accountant would put them.

- **Lite** — free. One balanced money transaction per completed order.
- **Pro** — $99 one-off, $49/year renewal. Invoices, refunds, per-gateway and per-store routing,
  the connection log, backfill.

Requires Craft CMS 5.3+, Craft Commerce 5.0+ and PHP 8.2+.

---

## What it does

Waver takes a completed Commerce order and writes it into [Wave](https://www.waveapps.com) as a
**money transaction**: money into the account it actually landed in, split across the accounts it
belongs to.

```
Order #A1B2C3                                      DEPOSIT  $178.45  ->  Business Checking
  Items                          Sales                                       credit   150.00
  Shipping                       Shipping Income                             credit     9.95
  Sales tax                      Sales Tax                                   credit    30.50
  Discounts                      Discounts                     debit  12.00
```

The line items balance the deposit exactly, because Wave rejects an entry where they do not — and,
much worse, accepts one that balances for the wrong reason.

## Why not just use Zapier

Zapier can move an order across. What it cannot do is *account* for one:

| | Waver |
|---|---|
| Tax **included** in the price | taken back out of income and booked as a liability |
| Discounts | booked as an increase on a contra-income account, which is how Wave records them |
| Sub-cent rounding | absorbed onto a rounding account, or refused if it is more than rounding |
| A retried sync | the same order, once — the external id is derived from the order, never random |
| An unconfirmed send | surfaced for a person, never silently re-sent |
| An unmapped account | a named blocker before anything is sent, not a rejected mutation afterwards |

## Getting started

1. **Create a Wave application** in the [developer portal](https://developer.waveapps.com) and
   generate a **full access token**.
2. Put it in an environment variable — the token reaches every business on your Wave account:

   ```
   WAVE_ACCESS_TOKEN="..."
   ```

3. In **Settings → Waver**, set the access token to `$WAVE_ACCESS_TOKEN` and press
   **Test connection**. It lists the businesses the token can reach, with their ids.
4. Paste the business id in, press **Check business**, then **Refresh from Wave** to load the chart
   of accounts.
5. Map the accounts. At minimum: **Payment**, **Sales**, and **Sales tax** if you charge any.
6. Open any completed order and press **Preview entry**.

Or do the same from the command line:

```sh
php craft waver/wave/check                 # token, businesses, and whether the business can be posted to
php craft waver/wave/accounts --anchors    # the accounts Wave accepts as a payment account
php craft waver/sync/order 1234 --dry-run  # the exact entry, without sending it
```

## Accounts

| Setting | What lands there |
|---|---|
| **Payment** | The anchor — the bank, clearing or card account the money arrived in |
| **Sales** | The goods, with any included tax taken back out |
| **Shipping** | Shipping charged to the customer. Falls back to Sales |
| **Sales tax** | Tax collected on someone else's behalf. Use a `Sales Tax` subtype |
| **Discounts** | A contra-income account, subtype `Discount` |
| **Fees** | Negative custom adjustments — a fee absorbed out of the same payment |
| **Other income** | Positive custom adjustments — a surcharge |
| **Rounding** | Sub-cent residuals. Falls back to Sales |
| **Refunds** | The income account a refund is taken back out of. Falls back to Sales |

## Invoice mode (Pro)

Instead of a ledger entry, create a Wave invoice — a document you can send — optionally approved,
marked paid, and emailed by Wave.

It is the right choice less often than it looks, and Waver is direct about why:

- **Every Wave invoice line must name a product.** `productId` is required; Wave has no free-text
  line. So invoice mode needs a Wave product per SKU. Waver creates and maps them, but Wave
  products carry no SKU of their own — the map is the only durable join, and clearing it will
  duplicate your catalogue.
- **Wave has no order-level charge**, so shipping cannot be represented on an invoice at all.
  Waver refuses the order and says so rather than quietly dropping the shipping from the total.
- **Wave computes the tax itself**, from the sales taxes on each line, so the invoice total can
  differ from Commerce's by a cent.
- Wave allows **one** invoice discount, so several Commerce discounts collapse into one line.

Transaction mode has none of these limits. Use invoice mode when you want the document.

## Refunds (Pro)

A successful Commerce refund becomes its own withdrawal in Wave, taken back out of the refunds
account. The sale and the refund are two facts and stay two entries — Waver never edits or deletes
the original.

## When Waver refuses

By design, it would rather do nothing than do something wrong:

- **The order is not paid in full** — a money transaction says cash arrived. Recorded as *skipped*
  with the shortfall. (Turn off **Only record orders paid in full** if you invoice on terms.)
- **An account is not mapped** — named, per account, before anything is sent.
- **The entry does not balance** beyond the rounding tolerance — refused, with the drift.
- **The business is on Wave's classic accounting** — `moneyTransactionCreate` does not work there,
  and the settings screen says so up front.

## Records that are in doubt

Wave's API **cannot read a money transaction back**. There is no transactions query, and Wave's
`Transaction` type exposes exactly one field: an id. So if Waver sends a transaction and never
hears back, nothing can determine whether it landed.

Waver does not guess. The record sits as `pending` with the attempt counted, and it is **never
re-sent automatically** — not by the queue, not by `waver/sync/retry`. The record screen offers:

- **Ask Wave** — works for invoices, which *can* be found again by number. For a transaction it
  tells you plainly that it cannot.
- **Mark as recorded** — you looked in Wave and it is there.
- **Force resend** — you looked in Wave and it is not.

Both of the latter need the separate *Force a resend, or mark a record as recorded by hand*
permission, because both can put a merchant's books wrong.

## Console commands

```sh
php craft waver/sync/order <id> [--dry-run] [--force]
php craft waver/sync/backfill [--limit=50] [--since=2026-01-01] [--dry-run]
php craft waver/sync/retry [--limit=50]      # failed records only, never doubtful ones
php craft waver/sync/status <orderId>

php craft waver/wave/check
php craft waver/wave/accounts [--anchors] [--flush]
php craft waver/wave/taxes [--flush]

php craft waver/log/tail [limit] [--level=error]
php craft waver/log/prune [--days=30]
php craft waver/log/clear
```

## Twig

`craft.waver` is read-only — a template render is not a place from which books should change.

```twig
{% if craft.waver.isRecorded(order) %}
    Recorded in Wave.
{% endif %}

{% set record = craft.waver.recordForOrder(order) %}
{{ record.status }} {{ record.waveId }}

{% set entry = craft.waver.previewEntry(order) %}
{% for row in entry.toRows() %}
    {{ row.account }} {{ row.debit }} {{ row.credit }}
{% endfor %}
```

## Permissions

| Permission | Allows |
|---|---|
| View Wave records | The Records screens and the order panel |
| ↳ Send orders to Wave | The **Send to Wave** button and **Ask Wave** |
| ↳ Force a resend, or mark a record as recorded by hand | The two actions that can double or falsify an entry |
| View the connection log | The log screens (Pro) |

## Lite and Pro

| | Lite | Pro |
|---|---|---|
| **Price** | **Free** | **$99**, $49/year renewal |
| Money transactions, balanced, deduplicated | ✓ | ✓ |
| Automatic on order completion | ✓ | ✓ |
| Account mapping, customer matching | ✓ | ✓ |
| Records screen, preview, manual send | ✓ | ✓ |
| Connection log | 7 days, no payloads | full, with payloads |
| Invoice mode | | ✓ |
| Refunds | | ✓ |
| Trigger on an order status | | ✓ |
| Per-gateway payment accounts | | ✓ |
| Per-store Wave businesses | | ✓ |
| Sales tax map | | ✓ |

## The Wave API

Everything Waver does was verified against Wave's own schema rather than inferred.
`docs/wave-api.md` records the contract, including the `curl` that reads the developer portal when
its website turns bots away.

## License

Proprietary. See `LICENSE.md`.
