<?php

namespace justinholtweb\waver\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\helpers\App;
use DateTime;
use justinholtweb\waver\helpers\Money;
use justinholtweb\waver\models\Entry;
use justinholtweb\waver\models\EntryLine;
use justinholtweb\waver\Plugin;

/**
 * Turns a Commerce order into a balanced Wave entry.
 *
 * **This is the only place an order becomes a Wave payload.** The CP preview, the console command,
 * the queue job and the retry all come through here, so what a merchant previews is byte-identical
 * to what Wave receives. A preview that is merely *representative* is worse than none — it is the
 * thing people trust before signing off on a quarter's books.
 *
 * ## The arithmetic
 *
 * Commerce's totals decompose exactly, and the decomposition is what makes the entry balance
 * without any fudging:
 *
 *     totalPrice = itemSubtotal + Σ adjustments where included = false
 *
 * `itemSubtotal` already contains any tax that is *included* in the price — Commerce says included
 * tax "does not affect total price", which means it is sitting inside the line items rather than
 * beside them. Booking `itemSubtotal` straight to sales would therefore record tax as income. So:
 *
 *     sales        = itemSubtotal − totalTaxIncluded
 *     tax          = totalTaxIncluded + tax adjustments (excluded)
 *     shipping     = shipping adjustments
 *     discount     = discount adjustments (negative, booked as a contra-income debit)
 *     other        = every remaining adjustment, by sign
 *
 * Substituting back gives exactly `totalPrice`, which is what the anchor deposits. The entry
 * balances by construction, and `Entry::drift()` proves it rather than assuming it.
 */
class Ledger extends Component
{
    /**
     * Build the money transaction for a sale.
     */
    public function buildEntry(Order $order): Entry
    {
        $settings = Plugin::getInstance()->getSettings();
        $storeId = $order->storeId ?? null;

        $entry = new Entry([
            'externalId' => $this->externalIdForOrder($order),
            'businessId' => $settings->getBusinessIdForStore($storeId),
            'date' => $this->dateFor($order),
            'description' => $this->describe($order),
            'direction' => Entry::DIRECTION_DEPOSIT,
            'anchorAccountId' => $settings->getPaymentAccountId($this->gatewayHandle($order)),
            'anchorAmount' => Money::round((float)$order->getTotalPrice()),
        ]);

        $customerId = $settings->attachCustomerToLines
            ? Plugin::getInstance()->getCustomers()->getCachedCustomerId($entry->businessId, (string)$order->getEmail())
            : null;

        // Sales: the goods, with any tax that was baked into their price taken back out.
        $includedTax = Money::round((float)$order->getTotalTaxIncluded());
        $sales = Money::round((float)$order->getItemSubtotal() - $includedTax);

        if (!Money::isZero($sales)) {
            $line = EntryLine::make(
                $sales > 0 ? EntryLine::ROLE_SALES : EntryLine::ROLE_DISCOUNT,
                $sales > 0 ? $settings->salesAccountId : $settings->discountAccountId,
                abs($sales),
                Craft::t('waver', 'Items')
            );
            $line->customerId = $customerId;
            $entry->addLine($line);
        }

        $buckets = $this->bucketAdjustments($order);

        // Tax collected on somebody else's behalf, from both sides of the included/excluded split.
        $tax = Money::round($includedTax + $buckets['tax']);

        if (!Money::isZero($tax)) {
            $entry->addLine(EntryLine::make(EntryLine::ROLE_TAX, $settings->taxAccountId, $tax, Craft::t('waver', 'Sales tax')));
        }

        if (!Money::isZero($buckets['shipping'])) {
            // A negative shipping adjustment is a shipping discount, not negative freight income.
            $entry->addLine($buckets['shipping'] > 0
                ? EntryLine::make(EntryLine::ROLE_SHIPPING, $settings->shippingAccountId ?: $settings->salesAccountId, $buckets['shipping'], Craft::t('waver', 'Shipping'))
                : EntryLine::make(EntryLine::ROLE_DISCOUNT, $settings->discountAccountId, abs($buckets['shipping']), Craft::t('waver', 'Shipping discount')));
        }

        if (!Money::isZero($buckets['discount'])) {
            $entry->addLine(EntryLine::make(EntryLine::ROLE_DISCOUNT, $settings->discountAccountId, abs($buckets['discount']), Craft::t('waver', 'Discounts')));
        }

        // Custom adjusters. A positive one is money the merchant took (a surcharge); a negative
        // one is money it gave up out of the same payment (a fee it absorbed).
        if (!Money::isZero($buckets['other'])) {
            $entry->addLine($buckets['other'] > 0
                ? EntryLine::make(EntryLine::ROLE_OTHER_INCOME, $settings->otherIncomeAccountId ?: $settings->salesAccountId, $buckets['other'], Craft::t('waver', 'Other charges'))
                : EntryLine::make(EntryLine::ROLE_FEE, $settings->feeAccountId, abs($buckets['other']), Craft::t('waver', 'Fees')));
        }

        $this->collectProblems($entry, $order, $buckets);
        $this->reconcile($entry);

        return $entry;
    }

    /**
     * Build the money transaction for a refund: the same money going back the other way.
     */
    public function buildRefundEntry(Order $order, Transaction $transaction): Entry
    {
        $settings = Plugin::getInstance()->getSettings();

        $entry = new Entry([
            'externalId' => $this->externalIdForRefund($order, $transaction),
            'businessId' => $settings->getBusinessIdForStore($order->storeId ?? null),
            'date' => $this->dateFor($order, $transaction->dateCreated ?? null),
            'description' => Craft::t('waver', 'Refund — {description}', ['description' => $this->describe($order)]),
            'direction' => Entry::DIRECTION_WITHDRAWAL,
            'anchorAccountId' => $settings->getPaymentAccountId($this->gatewayHandle($order)),
            'anchorAmount' => Money::round(abs((float)$transaction->amount)),
        ]);

        // A refund is income handed back: a debit against the income account, which is exactly
        // what `DECREASE` on it means.
        $entry->addLine(EntryLine::make(
            EntryLine::ROLE_REFUND,
            $settings->getRefundAccountId(),
            $entry->anchorAmount,
            Craft::t('waver', 'Refund of {reference}', ['reference' => $order->reference ?? $order->getShortNumber()])
        ));

        if ($settings->getRefundAccountId() === '') {
            $entry->problems[] = Craft::t('waver', 'No refund account is mapped.');
        }

        if ($entry->anchorAccountId === '') {
            $entry->problems[] = Craft::t('waver', 'No payment account is mapped.');
        }

        return $entry;
    }

    /**
     * The identity of an order's sale in Wave.
     *
     * Deterministic on purpose. Wave takes an `externalId` on every money transaction and suggests
     * "generate a UUID" — but a UUID makes a retry indistinguishable from a second sale. Derived
     * from the order, a retry produces the identical id, so the unique index in Waver's own table
     * and the id stored on Wave's side describe the same fact and cannot drift apart.
     *
     * The system uid is in there because two Craft installs pointed at one Wave business would
     * otherwise both claim `order-1`.
     */
    public function externalIdForOrder(Order $order): string
    {
        return sprintf('waver-%s-o%d', $this->installKey(), $order->id);
    }

    public function externalIdForRefund(Order $order, Transaction $transaction): string
    {
        return sprintf('waver-%s-o%d-r%d', $this->installKey(), $order->id, $transaction->id);
    }

    /**
     * Reasons this order should not be recorded at all, separate from reasons the entry will not
     * balance.
     *
     * @return string[]
     */
    public function getSkipReasons(Order $order): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $reasons = [];

        if (!$order->isCompleted) {
            $reasons[] = Craft::t('waver', 'The order is not complete.');
        }

        if (Money::isZero((float)$order->getTotalPrice())) {
            $reasons[] = Craft::t('waver', 'The order total is zero.');
        }

        if ($settings->requireFullyPaid) {
            $captured = $this->amountCaptured($order);
            $shortfall = Money::round((float)$order->getTotalPrice() - $captured);

            if ($shortfall > 0) {
                $reasons[] = Craft::t('waver', 'Only {captured} of {total} has been captured. A money transaction records cash that arrived.', [
                    'captured' => Money::format($captured),
                    'total' => Money::format((float)$order->getTotalPrice()),
                ]);
            }
        }

        return $reasons;
    }

    /**
     * What this order actually took in, ignoring anything given back.
     *
     * Deliberately not `Order::getOutstandingBalance()`. That is built on `getTotalPaid()`, which
     * *nets off refunds* — so a fully refunded order reads as entirely unpaid, and the sale it
     * once made would be skipped for ever. The sale and the refund are two facts, and Waver
     * records them as two entries.
     */
    public function amountCaptured(Order $order): float
    {
        if ($order->id === null) {
            return 0.0;
        }

        $captured = 0.0;

        foreach (Commerce::getInstance()?->getTransactions()->getAllTransactionsByOrderId($order->id) ?? [] as $transaction) {
            if (
                $transaction->status === TransactionRecord::STATUS_SUCCESS
                && in_array($transaction->type, [TransactionRecord::TYPE_PURCHASE, TransactionRecord::TYPE_CAPTURE], true)
            ) {
                $captured += (float)$transaction->amount;
            }
        }

        return Money::round($captured);
    }

    /**
     * The rendered transaction description.
     */
    public function describe(Order $order): string
    {
        $template = trim(Plugin::getInstance()->getSettings()->descriptionTemplate);

        if ($template === '') {
            return Craft::t('waver', 'Order {reference}', ['reference' => $order->reference ?? $order->getShortNumber()]);
        }

        try {
            $rendered = trim(Craft::$app->getView()->renderObjectTemplate($template, $order));
        } catch (\Throwable $e) {
            Craft::warning('Waver could not render the description template: ' . $e->getMessage(), __METHOD__);
            $rendered = '';
        }

        // `description` is non-null in Wave's schema, so an empty render is a rejected mutation.
        return $rendered !== '' ? $rendered : (string)($order->reference ?? $order->getShortNumber());
    }

    // Private
    // =========================================================================

    /**
     * Sum the adjustments that move the order total, bucketed by type.
     *
     * Only `included = false` adjustments are counted: an included one is already inside
     * `itemSubtotal`, and adding it here would double it.
     *
     * @return array{tax: float, shipping: float, discount: float, other: float}
     */
    private function bucketAdjustments(Order $order): array
    {
        $buckets = ['tax' => 0.0, 'shipping' => 0.0, 'discount' => 0.0, 'other' => 0.0];

        foreach ($order->getAdjustments() ?? [] as $adjustment) {
            if ($adjustment->included) {
                continue;
            }

            $bucket = match ($adjustment->type) {
                'tax' => 'tax',
                'shipping' => 'shipping',
                'discount' => 'discount',
                default => 'other',
            };

            $buckets[$bucket] += (float)$adjustment->amount;
        }

        foreach ($buckets as $key => $value) {
            $buckets[$key] = Money::round($value);
        }

        return $buckets;
    }

    /**
     * Balance the entry, or explain why it cannot be.
     */
    private function reconcile(Entry $entry): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($entry->isBalanced()) {
            return;
        }

        // An entry whose accounts are not mapped has already lost lines, so of course it does not
        // balance. Reporting the drift as well buries the fault the merchant can actually fix.
        if ($entry->problems !== []) {
            return;
        }

        $absorbed = $entry->absorbResidual($settings->getRoundingAccountId(), (float)$settings->roundingTolerance);

        if (!$absorbed) {
            $entry->problems[] = Craft::t('waver', 'The line items are {drift} away from the order total, which is more than the {tolerance} rounding tolerance. Nothing has been sent to Wave.', [
                'drift' => Money::format($entry->drift()),
                'tolerance' => Money::format((float)$settings->roundingTolerance),
            ]);
        }
    }

    /**
     * @param array{tax: float, shipping: float, discount: float, other: float} $buckets
     */
    private function collectProblems(Entry $entry, Order $order, array $buckets): void
    {
        $settings = Plugin::getInstance()->getSettings();

        // Every account that this particular order actually needs. Complaining about a shipping
        // account on an order with no shipping would just teach merchants to ignore the warning.
        $needed = [];

        if (!Money::isZero((float)$order->getItemSubtotal())) {
            $needed[Craft::t('waver', 'Sales')] = $settings->salesAccountId;
        }

        if (!Money::isZero($buckets['tax'] + (float)$order->getTotalTaxIncluded())) {
            $needed[Craft::t('waver', 'Sales tax')] = $settings->taxAccountId;
        }

        if ($buckets['shipping'] > 0) {
            $needed[Craft::t('waver', 'Shipping')] = $settings->shippingAccountId ?: $settings->salesAccountId;
        }

        if (!Money::isZero($buckets['discount']) || $buckets['shipping'] < 0) {
            $needed[Craft::t('waver', 'Discounts')] = $settings->discountAccountId;
        }

        if ($buckets['other'] < 0) {
            $needed[Craft::t('waver', 'Fees')] = $settings->feeAccountId;
        }

        foreach ($needed as $label => $accountId) {
            if (trim((string)$accountId) === '') {
                $entry->problems[] = Craft::t('waver', 'No Wave account is mapped for {label}.', ['label' => $label]);
            }
        }
    }

    private function dateFor(Order $order, DateTime|string|null $override = null): string
    {
        $date = $override ?? $order->dateOrdered ?? $order->dateCreated ?? new DateTime();

        if (is_string($date)) {
            $date = new DateTime($date);
        }

        // Wave's `Date` scalar has no time and no zone. The merchant's own timezone is the one
        // their books are kept in, so a late-evening order does not land on tomorrow's ledger.
        $local = (clone $date)->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()));

        return $local->format('Y-m-d');
    }

    private function gatewayHandle(Order $order): ?string
    {
        try {
            return $order->getGateway()?->handle;
        } catch (\Throwable) {
            // A gateway that has since been deleted throws rather than returning null.
            return null;
        }
    }

    /**
     * A short, stable key for this Craft install.
     */
    private function installKey(): string
    {
        return substr(md5((string)(Craft::$app->getSystemUid() ?? App::env('CRAFT_APP_ID') ?? 'craft')), 0, 8);
    }
}
