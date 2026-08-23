---
title: Installation
slug: installation
order: 10
summary: Requirements, install, getting a Wave token, and mapping your first accounts.
---

## Requirements

- Craft CMS 5.3 or later
- Craft Commerce 5.0 or later
- PHP 8.2 or later
- A Wave account, and a business that is **not** on Wave's classic accounting

## Install

```sh
composer require justinholtweb/craft-waver
php craft plugin/install waver
```

Or find **Waver** in the Craft Plugin Store and install it from there.

## Nothing is sent until you say so

Waver installs with no token, no business and no accounts mapped, and an order with nothing mapped
is refused rather than guessed at. Until you finish the steps below, your books are untouched.

## Get a Wave access token

1. Sign in to the [Wave developer portal](https://developer.waveapps.com) and create an
   application.
2. Open **Manage Applications**, select it, and click **Create token** to generate a
   **full access token**.
3. Put it in an environment variable rather than in the database — the token reaches **every**
   business on your Wave account, not just the one you are about to connect:

   ```
   WAVE_ACCESS_TOKEN="..."
   ```

Wave restricts full access tokens to "development purposes or personal applications". For a single
merchant connecting their own store — which is what Waver does — that is the intended use. OAuth 2
is for applications published for other people's Wave accounts.

## Connect

1. Go to **Settings → Plugins → Waver**.
2. Set **Access token** to `$WAVE_ACCESS_TOKEN`.
3. Press **Test connection**. It lists every business the token can reach, with its id.
4. Paste the business id into **Business**, then press **Check business**.

   This is worth doing before anything else. It tells you the business name and currency, and warns
   you about the two things that silently break a sync later: a business still on Wave's **classic
   accounting** (where money transactions do not work at all), and a business with **email sending
   off** (where Wave cannot email invoices).

5. Save, then press **Refresh from Wave** to load the chart of accounts.

## Map your accounts

Waver will not invent an account. At minimum you need:

| Setting | What lands there |
|---|---|
| **Payment account** | The bank, clearing or card account the money arrived in |
| **Sales account** | The goods themselves |
| **Sales tax account** | Tax you collected — if you charge any |

Add **Shipping**, **Discounts**, **Fees** and **Other income** if your orders carry those
adjustments. Waver only asks for the accounts a given order actually needs, so a store with no
shipping is never nagged about a shipping account.

The **Payment account** menu is filtered to the account subtypes Wave accepts as a transaction
anchor — Cash & Bank, Credit Card, Loans. The rest of the menus show your whole chart.

## Check it before you trust it

Open any completed order and press **Preview entry**. You get the exact rows Waver would send,
which accounts they land on, and whether the entry balances — without anything being sent.

The same thing from the command line:

```sh
php craft waver/wave/check                 # token, businesses, and whether the business can be posted to
php craft waver/wave/accounts --anchors    # accounts Wave will accept as a payment account
php craft waver/sync/order 1234 --dry-run  # the exact entry, without sending it
```

When the preview looks right, leave **Record orders automatically** on and the next completed order
goes to Wave on its own.

## Editions

Lite is free and is not a trial: it records balanced money transactions, automatically, for as many
orders as you have. Pro is a one-off $99 with a $49/year renewal.

| | Lite | Pro |
|---|---|---|
| **Price** | **Free** | **$99**, $49/year renewal |
| Balanced money transaction per order | ✅ | ✅ |
| Automatic on order completion | ✅ | ✅ |
| Account mapping, included-tax handling, rounding | ✅ | ✅ |
| Wave customer matching and creation | ✅ | ✅ |
| Records screen, entry preview, manual send | ✅ | ✅ |
| Backfill and retry from the console | ✅ | ✅ |
| Connection log | 7 days, no payloads | full, with payloads |
| **Invoice mode** — Wave invoices, approved, paid, emailed | — | ✅ |
| **Refunds** recorded as their own entry | — | ✅ |
| **Trigger on an order status** rather than completion | — | ✅ |
| **Per-gateway payment accounts** | — | ✅ |
| **Per-store Wave businesses** | — | ✅ |
| **Sales tax map** for invoice mode | — | ✅ |

## Existing orders

Installing Waver does not touch orders that completed before it arrived. To bring them in, use
[backfill](usage#backfill) — which will show you everything first.
