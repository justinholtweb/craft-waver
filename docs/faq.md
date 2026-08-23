---
title: FAQ
slug: faq
order: 50
summary: Common questions about recording Craft Commerce orders in Wave accounting.
---

## Is Waver free?

Lite is, and it is not a trial. It records every completed order as a balanced money transaction,
automatically, with full account mapping, included-tax handling, customer matching and a records
screen. Pro is a one-off $99 with a $49/year renewal, and adds invoice mode, refunds, order-status
triggers, per-gateway accounts, per-store businesses, the sales tax map, and the full connection log.

## Why not just use Zapier?

Zapier can move an order across. What it cannot do is *account* for one — take tax back out of an
income figure when it was included in the price, book a discount to a contra account so it reduces
income rather than adding an expense, balance the entry to the cent, and make sure a retry does not
post the same money twice.

## Will it change anything in Craft?

No. Waver reads orders and writes to Wave. It adds its own tables and a panel on the order screen,
and it never modifies an order, its status, or its totals.

## Do I need a paid Wave plan?

No. Waver uses a full access token, which any Wave account can generate from the developer portal.
Wave's OAuth flow — the one that requires a Pro subscription — is for applications published for
other people's accounts, which is not what a single merchant connecting their own store is doing.

## What happens if Wave is down when an order completes?

The order completes normally. Waver records off the request in a queue job, so a customer is never
waiting on Wave, and nothing in checkout can be blocked by an outage. The record is marked failed
with Wave's reason, and `waver/sync/retry` picks it up later.

## Can it post the same order twice?

Not on its own. Every record carries an external id derived from the order rather than a random one,
with a unique index on it, so a retried queue job or two workers racing the same order cannot both
insert. Sending twice takes a deliberate **Force resend** by someone with the permission for it.

## What if Waver sends a transaction and never hears back?

It stops, and tells you. **Wave's API cannot read a money transaction back** — there is no
transactions query, and the `Transaction` type exposes only an id — so nothing can determine whether
that send landed. Waver leaves the record pending and never re-sends it automatically, because
auto-retrying is what would double your revenue in exactly the case nobody can detect afterwards.
You resolve it with **Mark as recorded** or **Force resend** after looking in Wave.

Invoices are different: they can be found again by number, so **Ask Wave** resolves those for you.

## Does it handle tax that is included in the price?

Yes, and this is the thing most integrations get wrong. Commerce keeps included tax *inside* the
item subtotal, so booking that subtotal straight to sales records tax as revenue. Waver subtracts it
and books it to the sales tax account as the liability it is.

## What about refunds?

Pro records a successful Commerce refund as its own withdrawal, taken back out of the refunds
account. The sale and the refund stay two entries — Waver never edits or deletes the original,
because a ledger records what happened rather than the current state.

## Should I use transaction mode or invoice mode?

Transaction mode, unless you specifically want a document to send. It is one API call, needs no
Wave catalogue, and books the money exactly. Invoice mode needs a Wave product per SKU, cannot
represent shipping at all, and lets Wave compute its own tax — all of which Waver tells you about
before it sends anything.

## Does it work with multiple stores or gateways?

Pro maps Commerce store ids to separate Wave businesses, and gateway handles to separate payment
accounts, so card money and cheques do not land in the same place.

## What if my books do not balance?

They will, or Waver will not send. Every entry is checked against the order total before it goes;
sub-cent rounding is absorbed onto a rounding account, and anything larger is refused with the drift
reported. Wave rejects an unbalanced entry anyway — the point of checking first is that a
*wrongly* balanced one would be accepted.

## Which versions are supported?

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+, and a Wave business that is not on Wave's classic
accounting.
