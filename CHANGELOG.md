# Changelog

## 5.0.0 — 2026-08-20

Initial release.

### Added

- Records a completed Craft Commerce order in Wave as one balanced money transaction: sales,
  shipping, tax, discounts, fees and rounding, each on its own mapped account.
- Handles tax that is *included* in the price by taking it back out of income, so a VAT-inclusive
  store does not book tax as revenue.
- Idempotency built on an `externalId` derived from the order and a unique index on it, rather than
  on hope. A retried queue job cannot post twice.
- Never re-sends an unconfirmed transaction on its own, because Wave's API cannot be asked whether
  one landed. Surfaces it with **Ask Wave**, **Mark as recorded** and **Force resend** instead.
  A mutation that times out or gets a 5xx is not retried either: it stays in doubt, not failed.
- Refuses to send an entry that does not balance, or that is missing an account mapping, and names
  the reason per account.
- Invoice mode (Pro): Wave invoices with product and sales-tax mapping, approval, manual payment
  and Wave-sent email — and a plain explanation of what invoices cannot represent.
- Refund recording (Pro): a successful Commerce refund becomes its own withdrawal.
- Per-gateway payment accounts and per-store Wave businesses (Pro).
- Connection log with full GraphQL payloads (Pro). Retention is enforced on Craft's garbage
  collection, because payloads carry customer names and addresses.
- CP: settings with live connection and business checks, a Records index and detail screen, and a
  panel with a preview on Commerce's own order screen.
- Console: `waver/sync/{order,backfill,retry,status}`, `waver/wave/{check,accounts,taxes}`,
  `waver/log/{tail,prune,clear}`.
- `craft.waver` Twig variable, read-only.
