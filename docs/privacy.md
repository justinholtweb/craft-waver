---
title: Privacy and data
slug: privacy
order: 45
summary: What customer data Waver keeps in Craft, how long it keeps it, and what it sends to Wave.
---

# Privacy and data

Waver keeps three kinds of personal data in your Craft database, and sends customer details to
Wave when it records an order.

## What Waver stores

| Table | Personal data | Kept for |
|---|---|---|
| `waver_customers` | Customer email and name, against a Wave customer id | Until the map is cleared |
| `waver_records` | The exact payload sent to Wave, per order | As long as the record exists |
| `waver_log` | GraphQL requests and Wave's replies, when **Keep payloads** is on (Pro only) | **Keep log entries for**, then deleted |

**Customer emails are kept after the customer is gone.** Wave can only find a customer by email,
so the customer map is how Waver avoids creating a second copy of someone in Wave. A row in
`waver_customers` is not deleted when the Craft user or Commerce customer is deleted, and it stays
until you clear the map under **Settings → Maintenance**. Clearing it removes every customer *and*
product mapping, and Waver looks each one up in Wave again the next time it needs it. A product
that can't be matched by exact name will be created again in Wave. If you get an erasure request,
delete that customer's row from `waver_customers` directly.

The connection log is pruned during Craft's garbage collection. Entries are deleted after the
retention window: 7 days on Lite, or **Keep log entries for** on Pro, where `0` means keep
everything. Turn off **Keep payloads** and the log records only the action, status and timing.

## What goes to Wave

When Waver records an order, Wave receives the amounts and the description (by default
`Order {reference}`). To match a customer it sends their email, and when **Create Wave customers** is
on and no match exists it creates one with the customer's name, email and billing address.
Wave keeps its own copy under its own privacy policy. Nothing Waver deletes in Craft is deleted
from Wave, and erasing someone from your books in Wave has to be done in Wave.
