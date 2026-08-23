# The Wave API, as verified

Everything here was read out of Wave's own developer portal, cross-checked against three
independent client implementations that agree with it. Nothing in this file is inferred.

The portal 403s ordinary bots, but it runs on Zendesk and the help-centre API is open:

```sh
curl -s 'https://developer.waveapps.com/api/v2/help_center/en-us/articles.json?per_page=100'
curl -s 'https://developer.waveapps.com/api/v2/help_center/en-us/articles/360019968212.json'  # schema reference
```

Corroborating implementations: `jeffgreco13/laravel-wave` (PHP), `amritms/waveapps-client-php`
(PHP), Pipedream's `components/wave` (JS).

## Transport

- One endpoint, `POST https://gql.waveapps.com/graphql/public`.
- `Authorization: Bearer <token>`, `Content-Type: application/json`.
- Body is `{"query": "...", "variables": {...}}`. `query` is required for mutations too.
- A **full access token** is user-level and reaches every business on the account. Wave restricts
  it to "development purposes or personal applications"; published multi-tenant apps must use
  OAuth 2. For a single merchant wiring up their own store — Waver's case — it is the right tool.
- **Wave publishes no rate limit.** Waver therefore paces itself rather than assuming headroom.

## Errors

GraphQL semantics, not HTTP: a 200 can still carry `errors`. Two different failure shapes:

1. **Transport/validation** — top-level `errors[]`, each with `message` and
   `extensions.code` ∈ `GRAPHQL_VALIDATION_FAILED`, `NOT_FOUND`, `UNAUTHENTICATED`,
   `INTERNAL_SERVER_ERROR`, `VARIABLE_VALUE`. `extensions.id` is a support reference.
2. **Business rules** — the mutation returns `didSucceed: false` with `inputErrors[]`
   (`path`, `message`, `code`). **HTTP is still 200 and `errors` is absent.** Anything that only
   checks the status code will record a success that never happened.

## Reading

Objects that vary per business hang off `business`, not off the root:

```
business(id: ID) {
  id name isArchived emailSendEnabled currency { code }
  accounts(page, pageSize, types: [AccountTypeValue!], subtypes: [AccountSubtypeValue!],
           excludedSubtypes, isArchived) { pageInfo { currentPage totalPages totalCount } edges { node { … } } }
  customers(page, pageSize, sort: [CustomerSort!]!, email, modifiedAtAfter, modifiedAtBefore) { … }
  products(page, pageSize, sort: [ProductSort!]!, isSold, isBought, isArchived) { … }
  salesTaxes(page, pageSize, isArchived, modifiedAtAfter, modifiedAtBefore) { … }
  invoices(page, pageSize, sort: [InvoiceSort!]!, status, customerId, invoiceNumber, …) { … }
}
businesses(page, pageSize, isArchived) { … }
```

`sort` is **non-null on customers, products and invoices** (`[CustomerSort!]!`) — omitting it is a
validation error, not a default. Pagination is 1-based `page`/`pageSize` with
`pageInfo { currentPage totalPages totalCount }`.

## Writing — the mutations Waver uses

```
customerCreate(input: CustomerCreateInput!)          → { customer, didSucceed, inputErrors }
customerPatch(input: CustomerPatchInput!)            → { customer, didSucceed, inputErrors }
productCreate(input: ProductCreateInput!)            → { product, didSucceed, inputErrors }
invoiceCreate(input: InvoiceCreateInput!)            → { invoice, didSucceed, inputErrors }
invoiceApprove(input: InvoiceApproveInput!)          → { invoice, didSucceed, inputErrors }
invoiceSend(input: InvoiceSendInput!)                → { didSucceed, inputErrors }
invoiceDelete(input: InvoiceDeleteInput!)            → { didSucceed, inputErrors }
invoicePaymentCreateManual(input: InvoicePaymentCreateManualInput!)
moneyTransactionCreate(input: MoneyTransactionCreateInput!) → { transaction { id }, didSucceed, inputErrors }
```

### `MoneyTransactionCreateInput`

| Field | Type | Note |
|---|---|---|
| `businessId` | `ID!` | |
| `externalId` | `String!` | "ID of the transaction in an external system. If you don't have one, generate a UUID." |
| `date` | `Date!` | `Y-m-d` |
| `description` | `String!` | |
| `notes` | `String` | |
| `anchor` | `MoneyTransactionCreateAnchorInput!` | `{ accountId: ID!, amount: Decimal!, direction: TransactionDirection! }` |
| `lineItems` | `[MoneyTransactionCreateLineItemInput!]!` | `{ accountId: ID!, amount: Decimal!, balance: BalanceType!, customerId: ID, description: String, taxes: [{ salesTaxId: ID!, amount: Decimal! }] }` |

`TransactionDirection` ∈ `DEPOSIT | WITHDRAWAL`. `BalanceType` ∈ `INCREASE | DECREASE | DEBIT | CREDIT`.

Rules Wave states outright, all of which Waver enforces before it sends anything:

- **The line items must balance the anchor.** Not "should" — an unbalanced entry is rejected.
- **All amounts are positive, ≤ 2 decimal places.** Direction carries the sign, not the number.
- Anchor accounts are Asset/`CASH_AND_BANK` and Liability/`CREDIT_CARD`, `LOANS`. Anchor-type
  accounts must not appear as line items — Wave handles transfers separately and the API cannot.
- `INCREASE`/`DECREASE` are relative to the account's normal balance, **and they invert for contra
  accounts**: `INCREASE` on a `DISCOUNTS` subtype records *more discount*, which is a debit. That
  is why Waver books a discount as `INCREASE` and still counts it against income.
- `moneyTransactionCreate` is marked **BETA** and requires `isClassicAccounting: false`.

### `InvoiceCreateInput`

`businessId: ID!`, `customerId: ID!`, plus `status: InvoiceCreateStatus` (`DRAFT | SAVED`),
`currency`, `title`, `subhead`, `invoiceNumber`, `poNumber`, `invoiceDate: Date`, `dueDate: Date`,
`exchangeRate`, `memo`, `footer`, `discounts: [InvoiceDiscountInput!]` (**max 1**), and:

```
items: [InvoiceCreateItemInput!]
  productId   ID!       required — Wave has no free-text line, every line is a product
  description String    overrides the product's
  quantity    Decimal
  unitPrice   Decimal   overrides the product's
  taxes       [InvoiceCreateItemTaxInput!]   { salesTaxId: ID! }  — `amount` is DEPRECATED, Wave computes it
```

`productId` being required is the reason invoice mode needs a product map and transaction mode
does not.

`InvoicePaymentCreateManualInput`: `invoiceId: ID!`, `paymentAccountId: ID!`, `amount: Decimal!`,
`paymentDate: Date!`, `paymentMethod: InvoicePaymentMethod!`, `exchangeRate: Decimal!`, `memo`.
`InvoicePaymentMethod` ∈ `BANK_TRANSFER | CASH | CHEQUE | CREDIT_CARD | OTHER | PAYPAL | UNSPECIFIED`.

`invoiceSend` requires `Business.emailSendEnabled` to be true; `to: [String!]!` and
`attachPDF: Boolean!` are both non-null.

### `ProductCreateInput`

`businessId: ID!`, `name: String!`, `unitPrice: Decimal!`, `description`, `defaultSalesTaxIds`,
`incomeAccountId` (must be subtype `INCOME`, `DISCOUNTS` or `OTHER_INCOME`), `expenseAccountId`.

### `CustomerCreateInput`

`businessId: ID!`, `name: String!`, then `firstName`, `lastName`, `email`, `phone`, `mobile`,
`address: AddressInput`, `currency: CurrencyCode`, `displayId`, `internalNotes`,
`shippingDetails`. `customerPatch` takes `id: ID!` instead of `businessId`.
