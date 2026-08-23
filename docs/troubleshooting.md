---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: What each refusal and failure means, and the fastest way to find out what Wave actually said.
---

## Start with the log

Waver logs every request it sends and everything Wave says back. **Waver → Log** (Pro) shows the
GraphQL body and the reply for each call.

```sh
php craft waver/log/tail 20 --level=error
php craft waver/wave/check
```

`waver/wave/check` is the fastest way to separate "the connection is wrong" from "this order is
wrong".

## Connection

### "Wave rejected the access token"

The token has been revoked or mistyped. Regenerate it in the Wave developer portal under **Manage
Applications** and update the environment variable. Remember that Craft caches nothing here — but
your PHP process may still hold the old env value until it restarts.

### "Wave does not recognise that business id"

The id is wrong, or the token belongs to a different Wave account. **Test connection** lists the
ids this token can actually reach.

### "This business is on Wave's classic accounting"

`moneyTransactionCreate` requires a non-classic business — that is Wave's rule, not Waver's.
Transaction mode cannot work until the business is migrated in Wave. Invoice mode is unaffected, so
that is the workaround if migrating is not an option.

### Nothing happens at all when an order completes

Check, in order:

1. **Record orders automatically** is on.
2. **Record when** matches what you expect — if it is set to a status, the order has to reach one
   of the listed statuses.
3. Your queue is running. Waver records off the request, so the job has to actually be picked up.
4. **Waver → Records** — the order may be there as *skipped* with a reason.

## Orders that are refused

### "Only $X of $Y has been captured"

The order has not been paid in full, and **Only record orders paid in full** is on. Either the
payment genuinely has not landed, or you invoice on terms — in which case turn that setting off.

### "No Wave account is mapped for …"

That order needs an account you have not mapped. Waver names only the accounts the order actually
uses, so this is specific: an order with a discount needs a discounts account, one without does
not.

### "The line items are $X away from the order total"

The residual is larger than the rounding tolerance, so Waver refused rather than fudging it. This
usually means a custom adjuster is producing an adjustment shape Waver has bucketed as something
else. Run the preview:

```sh
php craft waver/sync/order <id> --dry-run
```

and compare the rows against the order. Raising the tolerance will make it send, but it will send
something wrong — find the adjustment first.

### "The order total is zero"

Nothing to record. A zero-total order is skipped by design.

## Records stuck on "pending"

A record that is pending with at least one attempt means Waver sent it and never got an answer.

**This is not something Waver can resolve for you, and that is a property of Wave's API, not a
missing feature.** There is no transactions query in Wave's schema, and the `Transaction` type
exposes only an id — so a posted money transaction cannot be found again by external id, date or
amount.

What to do:

1. Open Wave and search your payment account around the order's date for its total.
2. If it is there — **Mark as recorded**, pasting Wave's id if you have it.
3. If it is not — **Force resend**.

**Ask Wave** does resolve this automatically for *invoice* records, because invoices can be looked
up by number.

## Wave accepted it but the numbers look wrong

### Tax is showing as income

Your tax is *included* in the item price and the sales tax account is not mapped, so the tax had
nowhere to go. Map **Sales tax account** and re-check the preview: Waver takes included tax back out
of the sales figure.

### A discount is on the wrong side

The discounts account should be a `Discount`-subtype contra-income account in Wave. Waver books a
discount as an *increase* on it, which Wave interprets as a debit — less income. On an ordinary
income account, the same posting increases income.

### The invoice total differs from the order by a cent

Expected in invoice mode: Wave computes tax itself from the sales taxes on each line, rather than
taking Commerce's figure. Map your tax rates so it computes the same thing, or use transaction mode,
which books Commerce's number exactly.

## Invoice mode

### "The order has X of shipping, which invoice mode cannot represent"

Wave has no order-level charge and every invoice line needs a product. The options are a "Shipping"
product in Wave plus a matching Commerce line item, or transaction mode.

### "No Wave product is mapped for …"

**Create Wave products** is off and the SKU is unknown to Waver. Turn it on, or create the product
in Wave with exactly the line item's description — the fallback lookup is an exact name match, on
purpose, because a fuzzy match would attach a sale to the wrong income account.

### The catalogue filled up with duplicates

Something cleared the product map. Wave products carry no SKU field, so that map is the only durable
link between a Commerce purchasable and a Wave product — with it gone, the next order creates a new
product for any name that is not an exact match. Archive the duplicates in Wave.

### "The invoice was created but not marked paid"

The invoice exists. Waver reports this as a partial state rather than a failure precisely so a retry
does not create a second one. Record the payment in Wave by hand, then **Mark as recorded**.

## Getting a clean slate

**Settings → Waver → Maintenance → Clear customer and product maps** makes Waver look everything up
in Wave again. It never deletes anything from Wave — but where a customer or product cannot be
matched by email or exact name, Wave will gain a duplicate.

Deleting a record from the Records screen removes only Waver's row. Nothing is removed from Wave,
and the order becomes eligible to be recorded again.
