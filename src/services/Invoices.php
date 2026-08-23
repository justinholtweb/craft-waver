<?php

namespace justinholtweb\waver\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use DateTime;
use justinholtweb\waver\helpers\Money;
use justinholtweb\waver\Plugin;

/**
 * Invoice mode (Pro): an order becomes a Wave invoice document rather than a ledger entry.
 *
 * The trade-off is worth stating, because it is the whole reason there are two modes:
 *
 * - A **money transaction** is one call, needs no catalogue, and books the money exactly. It is
 *   the right thing for a storefront that has already been paid.
 * - An **invoice** is a document the customer can be sent, and Wave computes its tax from the
 *   sales taxes on each line. It costs a Wave product per SKU, up to four API calls per order, and
 *   Wave's own arithmetic may not agree with Commerce's to the cent — so Waver reports what Wave
 *   made of it rather than pretending the two are the same number.
 *
 * Invoice creation is deliberately **not** all-or-nothing. Wave has no transaction wrapper: if the
 * invoice is created and the payment call then fails, the invoice exists. Waver keeps its id and
 * reports the partial state, because the alternative — treating it as a clean failure — has a
 * retry creating a second invoice for the same order.
 */
class Invoices extends Component
{
    /**
     * Assemble the `InvoiceCreateInput` for an order.
     *
     * @return array{input: array<string, mixed>, problems: string[], customerId: string|null}
     */
    public function build(Order $order): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $businessId = $settings->getBusinessIdForStore($order->storeId ?? null);
        $problems = [];

        if ($businessId === '') {
            $problems[] = Craft::t('waver', 'No Wave business is selected.');
        }

        $customer = $plugin->getCustomers()->resolveForOrder($order, $businessId);

        if ($customer['id'] === null) {
            // `customerId` is `ID!` on InvoiceCreateInput — an invoice cannot exist without one.
            $problems[] = $customer['message'] ?? Craft::t('waver', 'The order has no email address, so it cannot be matched to a Wave customer.');
        }

        $items = [];

        foreach ($order->getLineItems() as $lineItem) {
            $product = $plugin->getProducts()->resolveForLineItem($lineItem, $businessId, $order->id);

            if ($product['id'] === null) {
                $problems[] = $product['message'] ?? Craft::t('waver', 'No Wave product for “{name}”.', ['name' => $plugin->getProducts()->nameFor($lineItem)]);
                continue;
            }

            $item = [
                'productId' => $product['id'],
                'description' => mb_substr((string)$lineItem->getDescription(), 0, 500),
                'quantity' => (string)$lineItem->qty,
                // Wave applies its own sales taxes to the line, so the unit price it is given must
                // be the pre-tax one. `getSalePrice()` is exactly that.
                'unitPrice' => Money::format((float)$lineItem->getSalePrice()),
            ];

            $taxIds = $this->salesTaxIdsFor($order, $lineItem->id);

            if ($taxIds !== []) {
                $item['taxes'] = array_map(static fn(string $id) => ['salesTaxId' => $id], $taxIds);
            }

            $items[] = $item;
        }

        if ($items === []) {
            $problems[] = Craft::t('waver', 'The order has no line items Wave can invoice.');
        }

        $input = array_filter([
            'businessId' => $businessId,
            'customerId' => $customer['id'],
            'status' => $settings->invoiceStatus,
            'currency' => $order->currency ?: null,
            'invoiceDate' => $this->dateFor($order),
            'invoiceNumber' => $this->invoiceNumberFor($order),
            'poNumber' => $order->reference ?: null,
            'title' => $settings->invoiceTitle ?: null,
            'memo' => $this->renderMemo($order),
            'footer' => $settings->invoiceFooter ?: null,
            'items' => $items,
        ], static fn($value) => $value !== null && $value !== '' && $value !== []);

        // Shipping has no product, so it cannot be an invoice line the way it can be a ledger
        // line. Wave's only order-level lever is a discount, and a discount cannot be negative.
        $shipping = Money::round((float)$order->getTotalShippingCost());

        if (!Money::isZero($shipping)) {
            $problems[] = Craft::t('waver', 'The order has {amount} of shipping, which invoice mode cannot represent: every Wave invoice line needs a product, and Wave has no order-level charge. Add a “Shipping” product in Wave and a matching Commerce line item, or record this order as a transaction.', [
                'amount' => Money::format($shipping),
            ]);
        }

        $discount = Money::round((float)$order->getTotalDiscount());

        if (!Money::isZero($discount)) {
            // `discounts` is capped at one entry by Wave, so several Commerce discounts collapse
            // into a single fixed-amount line rather than being lost.
            $input['discounts'] = [[
                'name' => Craft::t('waver', 'Discount'),
                'discountType' => 'FIXED',
                'amount' => Money::format(abs($discount)),
            ]];
        }

        return ['input' => $input, 'problems' => array_values(array_unique($problems)), 'customerId' => $customer['id']];
    }

    /**
     * Create the invoice, then approve and pay it if the settings say so.
     *
     * @param array<string, mixed> $input
     * @return array{ok: bool, id: string, number: string, viewUrl: string, pdfUrl: string, message: string}
     */
    public function send(Order $order, array $input): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $created = $plugin->getWave()->createInvoice($input, $order->id);

        if (!$created['ok'] || $created['id'] === '') {
            return $created;
        }

        $notes = [];

        // From here on nothing is fatal: the invoice exists, and reporting it as a failure would
        // have the next retry create a second one.
        if ($settings->approveInvoice) {
            $approved = $plugin->getWave()->approveInvoice($created['id'], $order->id);

            if (!$approved['ok']) {
                $notes[] = Craft::t('waver', 'The invoice was created but not approved: {message}', ['message' => $approved['message']]);
            }
        }

        if ($settings->markInvoicePaid) {
            $paid = $this->recordPayment($order, $created['id']);

            if ($paid !== null) {
                $notes[] = $paid;
            }
        }

        if ($settings->sendInvoice) {
            $email = trim((string)$order->getEmail());

            if ($email === '') {
                $notes[] = Craft::t('waver', 'The invoice could not be emailed: the order has no email address.');
            } else {
                $sent = $plugin->getWave()->sendInvoice($created['id'], [$email], null, null, $order->id);

                if (!$sent['ok']) {
                    $notes[] = Craft::t('waver', 'The invoice was created but not emailed: {message}', ['message' => $sent['message']]);
                }
            }
        }

        $created['message'] = implode(' ', $notes);

        return $created;
    }

    // Private
    // =========================================================================

    /**
     * @return string|null A note if something went wrong, null if the payment was recorded.
     */
    private function recordPayment(Order $order, string $invoiceId): ?string
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $amount = Money::round((float)$order->getTotalPaid());

        if (Money::isZero($amount)) {
            return null;
        }

        $accountId = $settings->getPaymentAccountId($this->gatewayHandle($order));

        if ($accountId === '') {
            return Craft::t('waver', 'The invoice was created but not marked paid: no payment account is mapped.');
        }

        $result = $plugin->getWave()->recordInvoicePayment([
            'invoiceId' => $invoiceId,
            'paymentAccountId' => $accountId,
            'amount' => Money::format($amount),
            'paymentDate' => $this->dateFor($order),
            'paymentMethod' => $this->paymentMethodFor($order),
            // Non-null in the schema, and only consulted when the invoice and payment account are
            // in different currencies. 1 is the honest value for the same-currency case.
            'exchangeRate' => '1',
        ], $order->id);

        return $result['ok']
            ? null
            : Craft::t('waver', 'The invoice was created but not marked paid: {message}', ['message' => $result['message']]);
    }

    /**
     * Wave's `InvoicePaymentMethod`, guessed from the Commerce gateway class when set to `auto`.
     */
    private function paymentMethodFor(Order $order): string
    {
        $configured = Plugin::getInstance()->getSettings()->invoicePaymentMethod;
        $allowed = ['BANK_TRANSFER', 'CASH', 'CHEQUE', 'CREDIT_CARD', 'OTHER', 'PAYPAL', 'UNSPECIFIED'];

        if ($configured !== 'auto' && in_array($configured, $allowed, true)) {
            return $configured;
        }

        $handle = strtolower((string)$this->gatewayHandle($order));

        return match (true) {
            str_contains($handle, 'paypal') => 'PAYPAL',
            str_contains($handle, 'cheque'), str_contains($handle, 'check') => 'CHEQUE',
            str_contains($handle, 'cash'), str_contains($handle, 'manual') => 'CASH',
            str_contains($handle, 'bank'), str_contains($handle, 'transfer') => 'BANK_TRANSFER',
            $handle !== '' => 'CREDIT_CARD',
            default => 'UNSPECIFIED',
        };
    }

    /**
     * The Wave sales taxes mapped to whatever tax adjustments touched this line item.
     *
     * @return string[]
     */
    private function salesTaxIdsFor(Order $order, ?int $lineItemId): array
    {
        $map = Plugin::getInstance()->getSettings()->taxMap;

        if ($map === [] || $lineItemId === null) {
            return [];
        }

        $ids = [];

        foreach ($order->getAdjustments() ?? [] as $adjustment) {
            if ($adjustment->type !== 'tax' || $adjustment->lineItemId !== $lineItemId) {
                continue;
            }

            $mapped = trim((string)($map[$adjustment->name] ?? ''));

            if ($mapped !== '') {
                $ids[] = $mapped;
            }
        }

        return array_values(array_unique($ids));
    }

    private function invoiceNumberFor(Order $order): ?string
    {
        $source = Plugin::getInstance()->getSettings()->invoiceNumberSource;

        // Wave numbers the invoice itself when none is given: "will find the current largest
        // invoice number and add 1".
        if ($source === 'wave') {
            return null;
        }

        $number = match ($source) {
            'number' => (string)$order->number,
            'shortNumber' => $order->getShortNumber(),
            'id' => (string)$order->id,
            default => (string)($order->reference ?? $order->getShortNumber()),
        };

        return $number !== '' ? mb_substr($number, 0, 64) : null;
    }

    private function renderMemo(Order $order): ?string
    {
        $template = trim(Plugin::getInstance()->getSettings()->invoiceMemoTemplate);

        if ($template === '') {
            return null;
        }

        try {
            $rendered = trim(Craft::$app->getView()->renderObjectTemplate($template, $order));
        } catch (\Throwable $e) {
            Craft::warning('Waver could not render the invoice memo template: ' . $e->getMessage(), __METHOD__);

            return null;
        }

        return $rendered !== '' ? $rendered : null;
    }

    private function dateFor(Order $order): string
    {
        $date = $order->dateOrdered ?? $order->dateCreated ?? new DateTime();

        if (is_string($date)) {
            $date = new DateTime($date);
        }

        return (clone $date)->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()))->format('Y-m-d');
    }

    private function gatewayHandle(Order $order): ?string
    {
        try {
            return $order->getGateway()?->handle;
        } catch (\Throwable) {
            return null;
        }
    }
}
