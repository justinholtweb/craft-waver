---
title: Usage
slug: usage
order: 30
summary: Previewing an entry, the Records screen, records that are in doubt, backfill, invoices and Twig.
---

## What an entry looks like

A completed order becomes one Wave money transaction: a deposit into the account the money arrived
in, split across the accounts it belongs to.

```
Order #A1B2C3                                      DEPOSIT  $178.45  ->  Business Checking
  Items                          Sales                                       credit   150.00
  Shipping                       Shipping Income                             credit     9.95
  Sales tax                      Sales Tax                                   credit    30.50
  Discounts                      Discounts                     debit  12.00
```

The line items balance the deposit exactly. Wave rejects an entry where they do not — and, worse,
accepts one that balances for the wrong reason, so Waver proves the arithmetic before it sends.

## Preview before you send

Every order screen has a **Preview entry** button. It builds the entry through the same code path
the real sync uses, so what you see is byte-identical to what Wave receives — including the
blockers, if any.

```sh
php craft waver/sync/order 1234 --dry-run
```

## The Records screen

**Waver → Records** lists everything Waver has recorded or tried to record, filterable by status.

| Status | Means |
|---|---|
| **synced** | It is in Wave. The Wave id is on the record |
| **failed** | Wave refused it, or Waver refused to send it. The reason is on the record |
| **skipped** | Waver decided not to record this order — not paid in full, zero total, not complete |
| **pending** | Sent, and nothing came back. See below |

Open a record to see the exact payload that was sent.

## Records that are in doubt

**Wave's API cannot read a money transaction back.** There is no transactions query, and Wave's
`Transaction` type exposes exactly one field: an id. Nothing can be looked up by external id, date
or amount.

So when Waver sends a transaction and never hears back, nothing — no code, no retry, no clever
reconciliation — can determine whether it landed.

Waver does not guess. The record sits as **pending** with the attempt counted, and it is **never
re-sent automatically**: not by the queue, not by `waver/sync/retry`, not by a later completion
event. Auto-retrying is the one thing that would double a merchant's revenue in exactly the case
nobody can detect afterwards.

The record screen gives you three ways out:

- **Ask Wave** — works for invoices, which *can* be found again by number. For a money transaction
  it tells you plainly that Wave cannot be asked, and what to search for instead.
- **Mark as recorded** — you looked in Wave and it is there. Optionally paste Wave's id.
- **Force resend** — you looked in Wave and it is not.

The last two need the *Force a resend, or mark a record as recorded by hand* permission.

## When Waver refuses

By design it would rather do nothing than do something wrong:

- **The order is not paid in full** — recorded as skipped, with the shortfall.
- **An account is not mapped** — named per account, before anything is sent.
- **The entry does not balance** beyond the rounding tolerance — refused, with the drift.
- **The business is on Wave's classic accounting** — money transactions do not work there.

## Backfill

Bring in orders that completed before Waver arrived. Newest first, and it will show you everything
before writing anything:

```sh
php craft waver/sync/backfill --dry-run                    # what it would do
php craft waver/sync/backfill --limit=200                  # do it
php craft waver/sync/backfill --since=2026-01-01           # only this year
```

Backfill only picks up orders Waver has never considered. An order with a record in any state —
even skipped — is left alone.

## Retrying failures

```sh
php craft waver/sync/retry --limit=50
```

Only records that are actually **failed**. A doubtful `pending` record is deliberately left alone,
for the reason above.

## Refunds (Pro)

A successful Commerce refund becomes its own withdrawal in Wave, queued the moment the refund
transaction saves. The sale and the refund stay two entries — Waver never edits or deletes the
original, because a ledger records what happened rather than the current state.

## Invoice mode (Pro)

Instead of a ledger entry, create a Wave invoice you can send, optionally approved, marked paid and
emailed by Wave.

It is the right choice less often than it looks, and Waver is direct about why:

- **Every Wave invoice line must name a product.** `productId` is required — Wave has no free-text
  line — so invoice mode needs a Wave product per SKU. Waver creates and maps them, but Wave
  products carry no SKU of their own, so the local map is the only durable join. Clearing it will
  duplicate your catalogue.
- **Wave has no order-level charge**, so shipping cannot appear on an invoice at all. Waver refuses
  the order and says so rather than quietly dropping the shipping from the total.
- **Wave computes the tax itself** from the sales taxes on each line, so the invoice total can
  differ from Commerce's by a cent. Map your tax rates so it computes the right thing.
- **Wave allows one invoice discount**, so several Commerce discounts collapse into a single fixed
  line.

Transaction mode has none of these limits. Use invoice mode when you want the document.

Invoice creation is deliberately not all-or-nothing: Wave has no transaction wrapper, so if the
invoice is created and the payment call then fails, the invoice exists. Waver keeps its id and
reports the partial state, because treating it as a clean failure would have the next attempt
create a second invoice.

## Console commands

```sh
php craft waver/sync/order <id> [--dry-run] [--force]
php craft waver/sync/backfill [--limit=50] [--since=2026-01-01] [--dry-run]
php craft waver/sync/retry [--limit=50]
php craft waver/sync/status <orderId>

php craft waver/wave/check
php craft waver/wave/accounts [--anchors] [--flush]
php craft waver/wave/taxes [--flush]

php craft waver/log/tail [limit] [--level=error]
php craft waver/log/prune [--days=30]
php craft waver/log/clear
```

`--force` on `sync/order` sends an order Waver already has a record for. Because Wave cannot be
asked whether the first attempt landed, this can genuinely double an entry. Use it after looking at
Wave, not instead of.

## Twig

`craft.waver` is read-only. A template render is not a place from which books should change.

```twig
{% if craft.waver.isRecorded(order) %}
    Recorded in Wave.
{% endif %}

{% set record = craft.waver.recordForOrder(order) %}
{{ record.status }} {{ record.waveId }}

{% for row in craft.waver.previewEntry(order).toRows() %}
    {{ row.account }} {{ row.debit }} {{ row.credit }}
{% endfor %}
```

| Method | Returns |
|---|---|
| `recordsForOrder(order)` | Every record, including refunds |
| `recordForOrder(order)` | The sale record, or `null` |
| `isRecorded(order)` | Whether the sale reached Wave |
| `previewEntry(order)` | The entry the order would produce, unsent |
| `statusCounts()` | A count per status |
