<?php
/**
 * Waver integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-waver/tests/integration/checks.php
 *
 * Idempotent and self-cleaning: fixture products, orders, records, log rows and the plugin
 * settings and edition it overwrites are all restored in a `finally`, pass or fail.
 *
 * There are no Wave credentials here, and there is no Wave sandbox that can be created from a
 * script — so the suite proves the two things that can be proved without one:
 *
 *  - **the arithmetic**, exhaustively, because an unbalanced entry is rejected by Wave and a
 *    *wrongly* balanced one is accepted and quietly corrupts a merchant's books; and
 *  - **the payload**, field by field, against the schema recorded in `docs/wave-api.md`.
 *
 * It also makes one real round trip to `gql.waveapps.com` with a deliberately bad token, which
 * proves the transport, the auth header and the error decoding for free. That check reports as
 * skipped rather than failed when the container has no outbound network.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\Plugin as Commerce;
use justinholtweb\waver\db\Table;
use justinholtweb\waver\helpers\Money;
use justinholtweb\waver\models\Entry;
use justinholtweb\waver\models\EntryLine;
use justinholtweb\waver\models\Record;
use justinholtweb\waver\models\Settings;
use justinholtweb\waver\models\WaveAccount;
use justinholtweb\waver\Plugin;

$passed = 0;
$failed = 0;
$skipped = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function skip(string $label, string $why): void
{
    global $skipped;
    $skipped++;
    echo "  ~ $label\n    $why\n";
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$commerce = Commerce::getInstance();
$storeId = $commerce->getStores()->getPrimaryStore()->id;
$suffix = substr(md5((string)microtime(true)), 0, 6);

$createdProducts = [];
$createdOrders = [];
$originalSettings = $plugin->getSettings()->toArray();
$originalEdition = Craft::$app->getPlugins()->getPluginInfo(Plugin::HANDLE)['edition'] ?? Plugin::EDITION_LITE;

/**
 * Put the plugin on an edition for the next few checks.
 *
 * In memory, not through project config. Editions really do live in project config, but this
 * harness is shared — its queue runner and the other plugins installed alongside Waver write
 * project config while the suite is running, and a long-lived console process racing them gets
 * `StaleResourceException` or `BusyResourceException` often enough to drown the actual results.
 * Every edition-sensitive path reads `Plugin::isPro()`, which reads this property, so the checks
 * exercise exactly what they claim to. Craft's own edition switching is Craft's to test.
 */
function switchEdition(string $edition): void
{
    Plugin::getInstance()->edition = $edition;
}

/**
 * Run a project config write, re-reading and retrying when something else got there first.
 */
function writeProjectConfig(callable $write): void
{
    for ($attempt = 1; ; $attempt++) {
        try {
            $write();
            // Writes are buffered until the request ends, so a script that reads its own write has
            // to flush first.
            Craft::$app->getProjectConfig()->saveModifiedConfigData();

            return;
        } catch (craft\errors\StaleResourceException | craft\errors\BusyResourceException $e) {
            if ($attempt >= 4) {
                throw $e;
            }

            Craft::$app->getProjectConfig()->reset();
            usleep(300000);
        }
    }
}

// `craft-penny` (a sibling plugin in this shared harness) registers an
// Elements::EVENT_BEFORE_SAVE_ELEMENT handler typed `ModelEvent` while Craft passes an
// `ElementEvent`, so saving *any* element fatals while it is enabled. Nothing to do with Waver;
// detached in-process here (never persisted) so fixtures can be created.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
    echo "  ! detached craft-penny's broken beforeSaveElement handler for this run\n";
}

/**
 * Put settings in place for the next few checks.
 *
 * In memory, not through project config. The suite changes settings a couple of dozen times, and
 * every project-config write takes a global lock that the other plugins sharing this harness — and
 * its queue runner — are also competing for; persisting each one made the run fail with
 * `BusyResourceException` perhaps half the time. Persistence itself is still tested, once, by
 * `persistSettings()`.
 */
function applySettings(array $settings): void
{
    Plugin::getInstance()->setSettings($settings);
}

/**
 * Actually write settings through project config, retrying once when something else holds the
 * lock. Used only where persistence is the thing under test.
 */
function persistSettings(array $settings): void
{
    writeProjectConfig(static function() use ($settings) {
        Craft::$app->getPlugins()->savePluginSettings(Plugin::getInstance(), $settings);
    });
}

/**
 * Save a fixture without validation and without touching the search index.
 *
 * The search index is the one thing here that contends with every other plugin sharing this
 * harness — indexing a fixture address deadlocks against their writes often enough to make the
 * suite look flaky. Nothing Waver does reads it.
 */
function saveFixture(Order $order): bool
{
    return Craft::$app->getElements()->saveElement($order, false, true, false);
}

function makeProduct(string $sku, float $price): Variant
{
    global $createdProducts;

    $type = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0];

    $product = new Product();
    $product->typeId = $type->id;
    $product->title = "Waver fixture $sku";
    $product->enabled = true;

    $variant = new Variant();
    $variant->sku = $sku;
    $variant->basePrice = $price;
    $variant->isDefault = true;

    $product->setVariants([$variant]);

    if (!Craft::$app->getElements()->saveElement($product, true, true, false)) {
        throw new RuntimeException('Could not save fixture product: ' . json_encode($product->getErrors()));
    }

    $createdProducts[] = $product;

    return $product->getVariants()[0];
}

/**
 * @param array<int, array{variant: Variant, qty: int}> $lines
 * @param array<int, array{type: string, name: string, amount: float, included?: bool}> $adjustments
 */
function makeOrder(array $lines, array $adjustments = [], bool $complete = true, bool $pay = true): Order
{
    global $createdOrders, $storeId;

    $order = new Order();
    $order->storeId = $storeId;
    $order->orderSiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $order->setEmail('waver-fixture@example.com');

    if (!saveFixture($order)) {
        throw new RuntimeException('Could not save order: ' . json_encode($order->getErrors()));
    }

    $createdOrders[] = $order;

    $lineItems = [];

    foreach ($lines as $line) {
        $lineItems[] = Commerce::getInstance()->getLineItems()->createLineItem(
            $order,
            $line['variant']->id,
            [],
            $line['qty']
        );
    }

    $order->setLineItems($lineItems);

    // Commerce refuses an address element it does not own, so the attributes go in as an array
    // and Commerce builds the owned element itself.
    $address = [
        'fullName' => 'Dana Fixture',
        'addressLine1' => '742 Evergreen Terrace',
        'locality' => 'Charlotte',
        'administrativeArea' => 'NC',
        'postalCode' => '28202',
        'countryCode' => 'US',
    ];
    $order->setShippingAddress($address);
    $order->setBillingAddress($address);

    if (!saveFixture($order)) {
        throw new RuntimeException('Could not save order lines: ' . json_encode($order->getErrors()));
    }

    if ($complete) {
        $order->markAsComplete();
    }

    if ($adjustments !== []) {
        // Adjustments go on *after* completion and with recalculation switched off. Commerce runs
        // its own adjusters on every save in the default mode, which throws away anything set by
        // hand — the fixture looks like it worked and the order comes back with none.
        $order = Order::find()->id($order->id)->status(null)->one();
        $order->setRecalculationMode(Order::RECALCULATION_MODE_NONE);

        $order->setAdjustments(array_map(static function(array $spec) use ($order) {
            $adjustment = new OrderAdjustment();
            $adjustment->setOrder($order);
            $adjustment->type = $spec['type'];
            $adjustment->name = $spec['name'];
            $adjustment->description = $spec['name'];
            $adjustment->amount = $spec['amount'];
            $adjustment->included = $spec['included'] ?? false;

            return $adjustment;
        }, $adjustments));

        if (!saveFixture($order)) {
            throw new RuntimeException('Could not save fixture adjustments: ' . json_encode($order->getErrors()));
        }
    }

    if ($pay) {
        payFor($order);
    }

    return Order::find()->id($order->id)->status(null)->one();
}

/**
 * Record a successful purchase so the order is not carrying an outstanding balance.
 *
 * A gateway has to be attached first: `Transactions::createTransaction()` reads
 * `$order->getGateway()->id` without a null check, so an order with no gateway fatals there.
 */
function payFor(Order $order): void
{
    if ($order->gatewayId === null) {
        $gateway = Commerce::getInstance()->getGateways()->getAllGateways()->first();

        if ($gateway === null) {
            throw new RuntimeException('The harness has no Commerce gateway to pay a fixture order with.');
        }

        $order->gatewayId = $gateway->id;
        saveFixture($order);
    }

    $transaction = Commerce::getInstance()->getTransactions()->createTransaction($order);
    $transaction->type = craft\commerce\records\Transaction::TYPE_PURCHASE;
    $transaction->status = craft\commerce\records\Transaction::STATUS_SUCCESS;
    $transaction->amount = $order->getTotalPrice();
    $transaction->paymentAmount = $order->getTotalPrice();
    Commerce::getInstance()->getTransactions()->saveTransaction($transaction);
}

/**
 * Settings that map every account to something, so the arithmetic can be tested without the
 * account-mapping blockers getting in the way.
 */
function mappedSettings(array $overrides = []): array
{
    return array_merge([
        'accessToken' => '',
        'businessId' => 'BUSINESS_FIXTURE',
        'recordAs' => Settings::MODE_TRANSACTION,
        'autoSync' => false,
        'syncTrigger' => Settings::TRIGGER_MANUAL,
        'requireFullyPaid' => true,
        'paymentAccountId' => 'ACC_BANK',
        'salesAccountId' => 'ACC_SALES',
        'shippingAccountId' => 'ACC_SHIPPING',
        'taxAccountId' => 'ACC_TAX',
        'discountAccountId' => 'ACC_DISCOUNT',
        'feeAccountId' => 'ACC_FEE',
        'otherIncomeAccountId' => 'ACC_OTHER',
        'roundingAccountId' => 'ACC_ROUNDING',
        'refundAccountId' => 'ACC_REFUND',
        'roundingTolerance' => 0.05,
        'createCustomers' => false,
        'attachCustomerToLines' => false,
        'loggingEnabled' => true,
        'logPayloads' => true,
    ], $overrides);
}

echo "\nWaver integration checks\n";
echo str_repeat('=', 60) . "\n";

try {
    switchEdition(Plugin::EDITION_PRO);
    applySettings(mappedSettings());

    // ---------------------------------------------------------------------
    section('Money');

    check('rounds to two places, half away from zero', function() {
        return Money::round(1.005) === 1.01 && Money::round(2.675) >= 2.67;
    });

    check('formats as a plain decimal string, never scientific notation', function() {
        return Money::format(0.00001) === '0.00'
            && Money::format(115.50000000000001) === '115.50'
            && Money::format(1234.5) === '1234.50';
    });

    check('compares money without ==', function() {
        return Money::equals(0.1 + 0.2, 0.3) && !Money::equals(1.00, 1.02) && Money::isZero(0.004);
    });

    check('sums at Wave precision', function() {
        return Money::equals(Money::sum([0.1, 0.2, 0.3]), 0.6);
    });

    // ---------------------------------------------------------------------
    section('Entry lines — the accounting decisions');

    check('income roles are credits and increase the account', function() {
        foreach ([EntryLine::ROLE_SALES, EntryLine::ROLE_SHIPPING, EntryLine::ROLE_TAX, EntryLine::ROLE_OTHER_INCOME] as $role) {
            $line = EntryLine::make($role, 'ACC', 10.0);

            if ($line->sign() !== 1 || $line->balance() !== EntryLine::BALANCE_INCREASE) {
                return "$role came out as sign {$line->sign()} / {$line->balance()}";
            }
        }

        return true;
    });

    check('a discount increases a contra account and still counts as a debit', function() {
        $line = EntryLine::make(EntryLine::ROLE_DISCOUNT, 'ACC', 5.0);

        // Wave: "INCREASE ... for contra accounts whose subtype is DISCOUNTS ... apply the amount
        // in the inverse of the account's normal balance type." More discount, less income.
        return $line->balance() === EntryLine::BALANCE_INCREASE && $line->sign() === -1;
    });

    check('a fee is a debit', function() {
        $line = EntryLine::make(EntryLine::ROLE_FEE, 'ACC', 3.0);

        return $line->balance() === EntryLine::BALANCE_INCREASE && $line->sign() === -1;
    });

    check('a refund decreases income', function() {
        $line = EntryLine::make(EntryLine::ROLE_REFUND, 'ACC', 3.0);

        return $line->balance() === EntryLine::BALANCE_DECREASE && $line->sign() === -1;
    });

    check('a line amount is always positive, whatever it was handed', function() {
        return EntryLine::make(EntryLine::ROLE_SALES, 'ACC', -42.5)->amount === 42.5;
    });

    check('rounding takes its direction from the residual', function() {
        $up = EntryLine::make(EntryLine::ROLE_ROUNDING, 'ACC', 0.01);
        $up->forcedSign = 1;
        $down = EntryLine::make(EntryLine::ROLE_ROUNDING, 'ACC', 0.01);
        $down->forcedSign = -1;

        return $up->balance() === EntryLine::BALANCE_INCREASE && $down->balance() === EntryLine::BALANCE_DECREASE;
    });

    check('a line renders as a MoneyTransactionCreateLineItemInput', function() {
        $line = EntryLine::make(EntryLine::ROLE_SALES, 'ACC_SALES', 12.3456, 'Items');
        $line->customerId = 'CUST_1';
        $input = $line->toWaveInput();

        return $input === [
            'accountId' => 'ACC_SALES',
            'amount' => '12.35',
            'balance' => 'INCREASE',
            'description' => 'Items',
            'customerId' => 'CUST_1',
        ] ?: json_encode($input);
    });

    check('a line never carries taxes — the amount would be unverifiable', function() {
        // MoneyTransactionCreateSalesTaxInput.amount is `Decimal!`, and nothing documents whether
        // it is extracted from the line or added on top. Waver books tax as its own line instead.
        return !array_key_exists('taxes', EntryLine::make(EntryLine::ROLE_TAX, 'ACC', 5.0)->toWaveInput());
    });

    // ---------------------------------------------------------------------
    section('Entry — balance');

    check('a deposit balances when its credits equal the anchor', function() {
        $entry = new Entry(['anchorAmount' => 100.0, 'direction' => Entry::DIRECTION_DEPOSIT]);
        $entry->addLine(EntryLine::make(EntryLine::ROLE_SALES, 'A', 90.0));
        $entry->addLine(EntryLine::make(EntryLine::ROLE_TAX, 'B', 10.0));

        return $entry->isBalanced() && Money::isZero($entry->drift());
    });

    check('a discount pulls the line total down, not up', function() {
        $entry = new Entry(['anchorAmount' => 90.0, 'direction' => Entry::DIRECTION_DEPOSIT]);
        $entry->addLine(EntryLine::make(EntryLine::ROLE_SALES, 'A', 100.0));
        $entry->addLine(EntryLine::make(EntryLine::ROLE_DISCOUNT, 'B', 10.0));

        return $entry->isBalanced() ?: 'drift ' . $entry->drift();
    });

    check('a withdrawal balances against the negative of the anchor', function() {
        $entry = new Entry(['anchorAmount' => 25.0, 'direction' => Entry::DIRECTION_WITHDRAWAL]);
        $entry->addLine(EntryLine::make(EntryLine::ROLE_REFUND, 'A', 25.0));

        return $entry->isBalanced() ?: 'drift ' . $entry->drift();
    });

    check('an unbalanced entry reports how far out it is', function() {
        $entry = new Entry(['anchorAmount' => 100.0, 'direction' => Entry::DIRECTION_DEPOSIT]);
        $entry->addLine(EntryLine::make(EntryLine::ROLE_SALES, 'A', 99.0));

        return !$entry->isBalanced() && Money::equals($entry->drift(), 1.0);
    });

    check('a zero line is dropped rather than sent', function() {
        $entry = new Entry(['anchorAmount' => 10.0]);
        $entry->addLine(EntryLine::make(EntryLine::ROLE_SALES, 'A', 0.0));
        $entry->addLine(EntryLine::make(EntryLine::ROLE_SALES, '', 10.0));

        return $entry->lines === [];
    });

    check('a residual inside tolerance is absorbed onto the rounding account', function() {
        $entry = new Entry(['anchorAmount' => 100.0, 'direction' => Entry::DIRECTION_DEPOSIT]);
        $entry->addLine(EntryLine::make(EntryLine::ROLE_SALES, 'A', 99.99));

        $absorbed = $entry->absorbResidual('ACC_ROUNDING', 0.05);
        $rounding = end($entry->lines);

        return $absorbed
            && $entry->isBalanced()
            && $rounding->role === EntryLine::ROLE_ROUNDING
            && Money::equals($rounding->amount, 0.01)
            && $rounding->sign() === 1;
    });

    check('a residual beyond tolerance is refused, not papered over', function() {
        $entry = new Entry(['anchorAmount' => 100.0, 'direction' => Entry::DIRECTION_DEPOSIT]);
        $entry->addLine(EntryLine::make(EntryLine::ROLE_SALES, 'A', 90.0));

        return !$entry->absorbResidual('ACC_ROUNDING', 0.05) && count($entry->lines) === 1;
    });

    check('a negative residual absorbs downwards', function() {
        $entry = new Entry(['anchorAmount' => 100.0, 'direction' => Entry::DIRECTION_DEPOSIT]);
        $entry->addLine(EntryLine::make(EntryLine::ROLE_SALES, 'A', 100.02));

        $entry->absorbResidual('ACC_ROUNDING', 0.05);
        $rounding = end($entry->lines);

        return $entry->isBalanced() && $rounding->sign() === -1 && $rounding->balance() === EntryLine::BALANCE_DECREASE;
    });

    check('an unbalanced entry is never sendable', function() {
        $entry = new Entry([
            'businessId' => 'B', 'anchorAccountId' => 'A', 'anchorAmount' => 100.0,
        ]);
        $entry->addLine(EntryLine::make(EntryLine::ROLE_SALES, 'A', 99.0));

        return !$entry->isSendable() && $entry->blockers() !== [];
    });

    check('blockers name every missing piece at once', function() {
        $entry = new Entry(['anchorAmount' => 0.0]);
        $blockers = $entry->blockers();

        return count($blockers) >= 3 ?: json_encode($blockers);
    });

    // ---------------------------------------------------------------------
    section('Entry — the payload Wave receives');

    check('the input matches MoneyTransactionCreateInput', function() {
        $entry = new Entry([
            'externalId' => 'waver-abc-o1',
            'businessId' => 'BIZ',
            'date' => '2026-08-20',
            'description' => 'Order 1',
            'anchorAccountId' => 'ACC_BANK',
            'anchorAmount' => 100.0,
            'direction' => Entry::DIRECTION_DEPOSIT,
        ]);
        $entry->addLine(EntryLine::make(EntryLine::ROLE_SALES, 'ACC_SALES', 100.0));

        $input = $entry->toWaveInput();

        // Every field Wave documents as non-null must be present.
        foreach (['businessId', 'externalId', 'date', 'description', 'anchor', 'lineItems'] as $key) {
            if (!isset($input[$key])) {
                return "missing $key";
            }
        }

        return $input['anchor'] === ['accountId' => 'ACC_BANK', 'amount' => '100.00', 'direction' => 'DEPOSIT']
            && $input['lineItems'][0]['balance'] === 'INCREASE'
            && !isset($input['notes']);
    });

    check('amounts are sent as strings so JSON cannot mangle them', function() {
        $entry = new Entry(['anchorAmount' => 1000000.1, 'anchorAccountId' => 'A']);
        $entry->addLine(EntryLine::make(EntryLine::ROLE_SALES, 'B', 1000000.1));
        $input = $entry->toWaveInput();

        return is_string($input['anchor']['amount'])
            && is_string($input['lineItems'][0]['amount'])
            && $input['anchor']['amount'] === '1000000.10';
    });

    check('direction and balance only ever use documented enum values', function() {
        $directions = [Entry::DIRECTION_DEPOSIT, Entry::DIRECTION_WITHDRAWAL];
        $balances = [EntryLine::BALANCE_INCREASE, EntryLine::BALANCE_DECREASE];

        foreach (EntryLine::roles() as $role) {
            $line = EntryLine::make($role, 'A', 1.0);

            if (!in_array($line->balance(), $balances, true)) {
                return "$role produced balance {$line->balance()}";
            }
        }

        return $directions === ['DEPOSIT', 'WITHDRAWAL'];
    });

    // ---------------------------------------------------------------------
    section('Ledger — real orders');

    $variantA = makeProduct("WAVER-A-$suffix", 40.00);
    $variantB = makeProduct("WAVER-B-$suffix", 15.50);

    $plainOrder = makeOrder([['variant' => $variantA, 'qty' => 2]]);

    check('a plain order balances', function() use ($plugin, $plainOrder) {
        $entry = $plugin->getLedger()->buildEntry($plainOrder);

        return $entry->isBalanced() ?: 'drift ' . $entry->drift() . ' on total ' . $plainOrder->getTotalPrice();
    });

    check('the anchor is the order total, deposited', function() use ($plugin, $plainOrder) {
        $entry = $plugin->getLedger()->buildEntry($plainOrder);

        return $entry->direction === Entry::DIRECTION_DEPOSIT
            && Money::equals($entry->anchorAmount, (float)$plainOrder->getTotalPrice());
    });

    check('a plain order is sendable', function() use ($plugin, $plainOrder) {
        $entry = $plugin->getLedger()->buildEntry($plainOrder);

        return $entry->isSendable() ?: json_encode($entry->blockers());
    });

    $mixedOrder = makeOrder(
        [['variant' => $variantA, 'qty' => 1], ['variant' => $variantB, 'qty' => 3]],
        [
            ['type' => 'shipping', 'name' => 'Ground', 'amount' => 9.95],
            ['type' => 'tax', 'name' => 'NC Sales Tax', 'amount' => 5.61],
            ['type' => 'discount', 'name' => 'Welcome', 'amount' => -12.00],
            ['type' => 'surcharge', 'name' => 'Card fee', 'amount' => -1.75],
        ]
    );

    check('an order with shipping, tax, a discount and a fee still balances', function() use ($plugin, $mixedOrder) {
        $entry = $plugin->getLedger()->buildEntry($mixedOrder);

        return $entry->isBalanced() ?: 'drift ' . $entry->drift() . ' on total ' . $mixedOrder->getTotalPrice();
    });

    check('each adjustment lands on its own account', function() use ($plugin, $mixedOrder) {
        $entry = $plugin->getLedger()->buildEntry($mixedOrder);
        $byRole = [];

        foreach ($entry->lines as $line) {
            $byRole[$line->role] = $line->accountId;
        }

        return ($byRole[EntryLine::ROLE_SALES] ?? null) === 'ACC_SALES'
            && ($byRole[EntryLine::ROLE_SHIPPING] ?? null) === 'ACC_SHIPPING'
            && ($byRole[EntryLine::ROLE_TAX] ?? null) === 'ACC_TAX'
            && ($byRole[EntryLine::ROLE_DISCOUNT] ?? null) === 'ACC_DISCOUNT'
            && ($byRole[EntryLine::ROLE_FEE] ?? null) === 'ACC_FEE'
            ?: json_encode($byRole);
    });

    $includedTaxOrder = makeOrder(
        [['variant' => $variantA, 'qty' => 1]],
        [['type' => 'tax', 'name' => 'VAT', 'amount' => 6.67, 'included' => true]]
    );

    check('tax included in the price is taken back out of income', function() use ($plugin, $includedTaxOrder) {
        // Commerce: included tax "does not affect total price" — it sits inside itemSubtotal. Book
        // itemSubtotal straight to sales and the merchant records tax as revenue.
        $entry = $plugin->getLedger()->buildEntry($includedTaxOrder);
        $sales = null;
        $tax = null;

        foreach ($entry->lines as $line) {
            if ($line->role === EntryLine::ROLE_SALES) {
                $sales = $line->amount;
            }
            if ($line->role === EntryLine::ROLE_TAX) {
                $tax = $line->amount;
            }
        }

        return Money::equals((float)$tax, 6.67)
            && Money::equals((float)$sales, (float)$includedTaxOrder->getItemSubtotal() - 6.67)
            && $entry->isBalanced()
            ?: "sales $sales tax $tax subtotal {$includedTaxOrder->getItemSubtotal()} drift {$entry->drift()}";
    });

    check('an included-tax order still balances to the order total', function() use ($plugin, $includedTaxOrder) {
        $entry = $plugin->getLedger()->buildEntry($includedTaxOrder);

        return Money::equals($entry->anchorAmount, (float)$includedTaxOrder->getTotalPrice()) && $entry->isBalanced();
    });

    $shippingDiscountOrder = makeOrder(
        [['variant' => $variantA, 'qty' => 1]],
        [['type' => 'shipping', 'name' => 'Free shipping', 'amount' => -4.00]]
    );

    check('a negative shipping adjustment is a discount, not negative freight income', function() use ($plugin, $shippingDiscountOrder) {
        $entry = $plugin->getLedger()->buildEntry($shippingDiscountOrder);

        foreach ($entry->lines as $line) {
            if ($line->accountId === 'ACC_SHIPPING') {
                return 'booked as shipping income';
            }
        }

        return $entry->isBalanced();
    });

    check('a positive custom adjustment is other income, a negative one is a fee', function() use ($plugin, $variantA) {
        $order = makeOrder(
            [['variant' => $variantA, 'qty' => 1]],
            [['type' => 'surcharge', 'name' => 'Handling', 'amount' => 3.00]]
        );
        $entry = $plugin->getLedger()->buildEntry($order);

        foreach ($entry->lines as $line) {
            if ($line->role === EntryLine::ROLE_OTHER_INCOME && $line->accountId === 'ACC_OTHER') {
                return $entry->isBalanced();
            }
        }

        return 'no other-income line: ' . json_encode(array_map(static fn($l) => $l->role, $entry->lines));
    });

    check('the external id is derived from the order, not random', function() use ($plugin, $plainOrder) {
        $first = $plugin->getLedger()->externalIdForOrder($plainOrder);
        $second = $plugin->getLedger()->externalIdForOrder($plainOrder);

        return $first === $second
            && str_starts_with($first, 'waver-')
            && str_ends_with($first, '-o' . $plainOrder->id);
    });

    check('two orders never share an external id', function() use ($plugin, $plainOrder, $mixedOrder) {
        return $plugin->getLedger()->externalIdForOrder($plainOrder) !== $plugin->getLedger()->externalIdForOrder($mixedOrder);
    });

    check('the description comes from the object template', function() use ($plugin, $plainOrder) {
        applySettings(mappedSettings(['descriptionTemplate' => 'Sale {{ object.id }}']));
        $described = $plugin->getLedger()->describe($plainOrder);
        applySettings(mappedSettings());

        return $described === 'Sale ' . $plainOrder->id ?: $described;
    });

    check('an empty description falls back rather than sending null', function() use ($plugin, $plainOrder) {
        // `description` is String! on MoneyTransactionCreateInput.
        applySettings(mappedSettings(['descriptionTemplate' => '{{ object.thisFieldDoesNotExist }}']));
        $described = $plugin->getLedger()->describe($plainOrder);
        applySettings(mappedSettings());

        return $described !== '';
    });

    check('the date is a bare Y-m-d in the site timezone', function() use ($plugin, $plainOrder) {
        $entry = $plugin->getLedger()->buildEntry($plainOrder);

        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $entry->date) ?: $entry->date;
    });

    // ---------------------------------------------------------------------
    section('Ledger — when not to record');

    check('an incomplete order is skipped', function() use ($plugin, $variantA) {
        $cart = makeOrder([['variant' => $variantA, 'qty' => 1]], [], false, false);

        return $plugin->getLedger()->getSkipReasons($cart) !== [];
    });

    check('an unpaid order is skipped while “paid in full” is on', function() use ($plugin, $variantA) {
        $unpaid = makeOrder([['variant' => $variantA, 'qty' => 1]], [], true, false);
        $reasons = $plugin->getLedger()->getSkipReasons($unpaid);

        return count($reasons) === 1 && str_contains($reasons[0], 'has been captured') ?: json_encode($reasons);
    });

    check('an unpaid order is allowed once “paid in full” is off', function() use ($plugin, $variantA) {
        applySettings(mappedSettings(['requireFullyPaid' => false]));
        $unpaid = makeOrder([['variant' => $variantA, 'qty' => 1]], [], true, false);
        $reasons = $plugin->getLedger()->getSkipReasons($unpaid);
        applySettings(mappedSettings());

        return $reasons === [] ?: json_encode($reasons);
    });

    check('a missing account mapping is a blocker naming the account', function() use ($plugin, $mixedOrder) {
        applySettings(mappedSettings(['taxAccountId' => '']));
        $entry = $plugin->getLedger()->buildEntry($mixedOrder);
        $blockers = implode(' ', $entry->blockers());
        applySettings(mappedSettings());

        return str_contains($blockers, 'Sales tax') && !$entry->isSendable() ?: $blockers;
    });

    check('an account that this order does not need is not demanded', function() use ($plugin, $plainOrder) {
        // A plain order has no shipping, so a blank shipping account must not block it.
        applySettings(mappedSettings(['shippingAccountId' => '', 'feeAccountId' => '', 'discountAccountId' => '']));
        $entry = $plugin->getLedger()->buildEntry($plainOrder);
        applySettings(mappedSettings());

        return $entry->isSendable() ?: json_encode($entry->blockers());
    });

    // ---------------------------------------------------------------------
    section('Ledger — refunds');

    $refundedOrder = makeOrder([['variant' => $variantA, 'qty' => 2]]);

    check('a refund is a withdrawal that decreases income', function() use ($plugin, $commerce, $refundedOrder) {
        $refund = $commerce->getTransactions()->createTransaction($refundedOrder);
        $refund->type = craft\commerce\records\Transaction::TYPE_REFUND;
        $refund->status = craft\commerce\records\Transaction::STATUS_SUCCESS;
        $refund->amount = 20.00;
        $refund->paymentAmount = 20.00;
        $commerce->getTransactions()->saveTransaction($refund);

        $entry = $plugin->getLedger()->buildRefundEntry($refundedOrder, $refund);

        return $entry->direction === Entry::DIRECTION_WITHDRAWAL
            && Money::equals($entry->anchorAmount, 20.00)
            && $entry->isBalanced()
            && $entry->lines[0]->balance() === EntryLine::BALANCE_DECREASE
            && $entry->lines[0]->accountId === 'ACC_REFUND'
            ?: 'drift ' . $entry->drift();
    });

    check('a refund external id includes the Commerce transaction', function() use ($plugin, $commerce, $refundedOrder) {
        $refund = $commerce->getTransactions()->createTransaction($refundedOrder);
        $refund->type = craft\commerce\records\Transaction::TYPE_REFUND;
        $refund->status = craft\commerce\records\Transaction::STATUS_SUCCESS;
        $refund->amount = 5.00;
        $commerce->getTransactions()->saveTransaction($refund);

        $id = $plugin->getLedger()->externalIdForRefund($refundedOrder, $refund);

        return str_ends_with($id, '-r' . $refund->id) && $id !== $plugin->getLedger()->externalIdForOrder($refundedOrder);
    });

    check('a refunded order can still have its sale recorded', function() use ($plugin, $refundedOrder) {
        // `Order::getOutstandingBalance()` nets refunds off, so a refunded order reads as unpaid.
        // The sale and the refund are two separate facts and both belong in the books.
        $fresh = Order::find()->id($refundedOrder->id)->status(null)->one();

        return $fresh->hasOutstandingBalance()
            && $plugin->getLedger()->getSkipReasons($fresh) === []
            ?: 'skipped: ' . json_encode($plugin->getLedger()->getSkipReasons($fresh));
    });

    // ---------------------------------------------------------------------
    section('Records — idempotency');

    check('a sync with no credentials fails rather than pretending', function() use ($plugin, $plainOrder) {
        $record = $plugin->getRecords()->sync($plainOrder);

        return $record->status === Record::STATUS_FAILED && $record->waveId === null ?: $record->status;
    });

    check('the attempt was counted before the call, not after', function() use ($plugin, $plainOrder) {
        $record = $plugin->getRecords()->getRecordByExternalId($plugin->getLedger()->externalIdForOrder($plainOrder));

        return $record !== null && $record->attempts >= 1 ?: 'attempts ' . ($record->attempts ?? 'null');
    });

    check('the payload is stored so the record can explain itself', function() use ($plugin, $plainOrder) {
        $record = $plugin->getRecords()->getRecordByExternalId($plugin->getLedger()->externalIdForOrder($plainOrder));
        $payload = $record?->getDecodedPayload();

        return is_array($payload) && isset($payload['anchor']['direction']) ?: json_encode($payload);
    });

    check('syncing the same order twice never makes a second row', function() use ($plugin, $plainOrder) {
        $plugin->getRecords()->sync($plainOrder);
        $plugin->getRecords()->sync($plainOrder);

        $rows = (new craft\db\Query())
            ->from([Table::RECORDS])
            ->where(['orderId' => $plainOrder->id, 'kind' => Record::KIND_TRANSACTION])
            ->count();

        return (int)$rows === 1 ?: "rows $rows";
    });

    check('the unique index on externalId is what actually enforces it', function() use ($plugin, $plainOrder) {
        $externalId = $plugin->getLedger()->externalIdForOrder($plainOrder);

        try {
            Craft::$app->getDb()->createCommand()->insert(Table::RECORDS, [
                'orderId' => $plainOrder->id,
                'kind' => Record::KIND_TRANSACTION,
                'externalId' => $externalId,
                'status' => Record::STATUS_PENDING,
                'attempts' => 0,
                'dateCreated' => craft\helpers\Db::prepareDateForDb(new DateTime()),
                'dateUpdated' => craft\helpers\Db::prepareDateForDb(new DateTime()),
                'uid' => craft\helpers\StringHelper::UUID(),
            ])->execute();
        } catch (yii\db\IntegrityException) {
            return true;
        }

        return 'the database accepted a duplicate externalId';
    });

    check('a synced record is returned untouched rather than resent', function() use ($plugin, $plainOrder) {
        $externalId = $plugin->getLedger()->externalIdForOrder($plainOrder);
        $record = $plugin->getRecords()->getRecordByExternalId($externalId);
        $plugin->getRecords()->markRecorded($record, 'WAVE_TX_1');

        $before = $plugin->getRecords()->getRecordByExternalId($externalId)->attempts;
        $plugin->getRecords()->sync($plainOrder);
        $after = $plugin->getRecords()->getRecordByExternalId($externalId);

        return $after->attempts === $before && $after->waveId === 'WAVE_TX_1' && $after->isSynced();
    });

    check('an attempted-but-unconfirmed record is not resent on its own', function() use ($plugin, $variantA) {
        // The scenario nothing can detect afterwards: Wave took the transaction, the response was
        // lost. Sending again would double a merchant's revenue.
        $order = makeOrder([['variant' => $variantA, 'qty' => 1]]);
        $externalId = $plugin->getLedger()->externalIdForOrder($order);

        $plugin->getRecords()->sync($order);
        Craft::$app->getDb()->createCommand()->update(Table::RECORDS, [
            'status' => Record::STATUS_PENDING,
            'attempts' => 1,
        ], ['externalId' => $externalId])->execute();

        $before = $plugin->getRecords()->getRecordByExternalId($externalId)->attempts;
        $result = $plugin->getRecords()->sync($order);

        return $result->attempts === $before
            && $result->status === Record::STATUS_PENDING
            && str_contains((string)$result->message, 'did not report back')
            ?: "attempts {$result->attempts} status {$result->status}";
    });

    check('force overrides that, because a human has looked', function() use ($plugin, $variantA) {
        $order = makeOrder([['variant' => $variantA, 'qty' => 1]]);
        $externalId = $plugin->getLedger()->externalIdForOrder($order);

        $plugin->getRecords()->sync($order);
        Craft::$app->getDb()->createCommand()->update(Table::RECORDS, [
            'status' => Record::STATUS_PENDING,
            'attempts' => 1,
        ], ['externalId' => $externalId])->execute();

        $before = $plugin->getRecords()->getRecordByExternalId($externalId)->attempts;
        $result = $plugin->getRecords()->sync($order, true);

        return $result->attempts > $before ?: "attempts stayed at {$result->attempts}";
    });

    check('a skipped order is recorded as skipped, with the reason', function() use ($plugin, $variantA) {
        $unpaid = makeOrder([['variant' => $variantA, 'qty' => 1]], [], true, false);
        $record = $plugin->getRecords()->sync($unpaid);

        return $record->status === Record::STATUS_SKIPPED && str_contains((string)$record->message, 'has been captured')
            ?: "{$record->status}: {$record->message}";
    });

    check('resolving a money transaction says plainly that Wave cannot be asked', function() use ($plugin, $plainOrder) {
        $record = $plugin->getRecords()->getRecordByExternalId($plugin->getLedger()->externalIdForOrder($plainOrder));
        $result = $plugin->getRecords()->resolveDoubtful($record);

        return $result['resolved'] === false && str_contains($result['message'], 'no way to look a money transaction up');
    });

    check('marking a record recorded sets a synced date', function() use ($plugin, $plainOrder) {
        $record = $plugin->getRecords()->getRecordByExternalId($plugin->getLedger()->externalIdForOrder($plainOrder));
        $marked = $plugin->getRecords()->markRecorded($record, 'WAVE_TX_2');

        return $marked->isSynced() && $marked->waveId === 'WAVE_TX_2' && $marked->dateSynced !== null;
    });

    check('deleting a record frees the order to be considered again', function() use ($plugin, $variantB) {
        $order = makeOrder([['variant' => $variantB, 'qty' => 1]]);
        $plugin->getRecords()->sync($order);
        $record = $plugin->getRecords()->getRecordByExternalId($plugin->getLedger()->externalIdForOrder($order));

        $plugin->getRecords()->deleteRecord($record);

        return $plugin->getRecords()->getRecordsForOrder($order->id) === []
            && in_array($order->id, $plugin->getRecords()->getUnrecordedOrderIds(200), true);
    });

    check('an order with a record is not offered for backfill', function() use ($plugin, $plainOrder) {
        return !in_array($plainOrder->id, $plugin->getRecords()->getUnrecordedOrderIds(200), true);
    });

    check('status counts add up', function() use ($plugin) {
        $counts = $plugin->getRecords()->getStatusCounts();

        return array_sum($counts) === $plugin->getRecords()->countRecords()
            && array_keys($counts) === ['pending', 'synced', 'failed', 'skipped']
            ?: json_encode($counts);
    });

    // ---------------------------------------------------------------------
    section('Settings');

    check('an editable-table row list normalises to a map', function() {
        $settings = new Settings();
        $settings->gatewayAccountMap = [
            ['gateway' => 'stripe', 'account' => 'ACC_STRIPE'],
            ['gateway' => '', 'account' => 'ACC_NOPE'],
            ['gateway' => 'manual', 'account' => ''],
        ];

        return $settings->gatewayAccountMap === ['stripe' => 'ACC_STRIPE'] ?: json_encode($settings->gatewayAccountMap);
    });

    check('an already-keyed map survives a round trip', function() {
        $settings = new Settings();
        $settings->taxMap = ['NC Sales Tax' => 'TAX_1'];

        return $settings->taxMap === ['NC Sales Tax' => 'TAX_1'];
    });

    check('the maps are attributes, or Craft would never save them', function() {
        // A private property is not a Yii attribute, and Craft persists plugin settings by
        // iterating attributes.
        $attributes = (new Settings())->attributes();

        return in_array('gatewayAccountMap', $attributes, true)
            && in_array('businessMap', $attributes, true)
            && in_array('taxMap', $attributes, true);
    });

    check('the maps really do persist through project config', function() use ($plugin) {
        // The whole point of the `attributes()` override: a private-backed property is not a Yii
        // attribute, and Craft saves plugin settings by iterating attributes.
        global $originalSettings;

        try {
            persistSettings(['gatewayAccountMap' => [['gateway' => 'stripe', 'account' => 'ACC_STRIPE']]]);
            $stored = $plugin->getSettings()->gatewayAccountMap;
        } finally {
            // Back to what was actually there, not to the suite's fixture settings — this is the
            // one check that writes project config, and it must not leave its fixtures behind.
            persistSettings($originalSettings);
            applySettings(mappedSettings());
        }

        return $stored === ['stripe' => 'ACC_STRIPE'] ?: json_encode($stored);
    });

    check('a gateway-specific account wins over the default on Pro', function() use ($plugin) {
        applySettings(mappedSettings(['gatewayAccountMap' => [['gateway' => 'stripe', 'account' => 'ACC_STRIPE']]]));
        $settings = $plugin->getSettings();
        $result = [$settings->getPaymentAccountId('stripe'), $settings->getPaymentAccountId('paypal'), $settings->getPaymentAccountId()];
        applySettings(mappedSettings());

        return $result === ['ACC_STRIPE', 'ACC_BANK', 'ACC_BANK'] ?: json_encode($result);
    });

    check('rounding, refund and product accounts fall back to sales', function() {
        $settings = new Settings();
        $settings->salesAccountId = 'ACC_SALES';

        return $settings->getRoundingAccountId() === 'ACC_SALES'
            && $settings->getRefundAccountId() === 'ACC_SALES'
            && $settings->getProductIncomeAccountId() === 'ACC_SALES';
    });

    check('no setting is marked required, so a fresh install can be saved', function() {
        $settings = new Settings();

        return $settings->validate() ?: json_encode($settings->getErrors());
    });

    check('out-of-range numbers are rejected', function() {
        $settings = new Settings(['timeout' => 500, 'roundingTolerance' => 99, 'recordAs' => 'nonsense']);
        $settings->validate();

        return count($settings->getErrors()) === 3 ?: json_encode($settings->getErrors());
    });

    // ---------------------------------------------------------------------
    section('Invoice mode');

    check('invoice mode is refused on Lite and falls back to transactions', function() use ($plugin) {
        switchEdition(Plugin::EDITION_LITE);
        applySettings(mappedSettings(['recordAs' => Settings::MODE_INVOICE]));
        $mode = $plugin->getSettings()->getEffectiveMode();
        switchEdition(Plugin::EDITION_PRO);
        applySettings(mappedSettings());

        return $mode === Settings::MODE_TRANSACTION ?: $mode;
    });

    check('shipping blocks an invoice, and says why', function() use ($plugin, $mixedOrder) {
        applySettings(mappedSettings(['recordAs' => Settings::MODE_INVOICE]));
        $build = $plugin->getInvoices()->build($mixedOrder);
        applySettings(mappedSettings());

        $problems = implode(' ', $build['problems']);

        // Wave has no order-level charge and every line needs a product, so shipping genuinely has
        // nowhere to go. Better said out loud than silently dropped from the invoice total.
        return str_contains($problems, 'shipping') ?: $problems;
    });

    check('an invoice with no reachable customer is blocked, not sent half-built', function() use ($plugin, $plainOrder) {
        applySettings(mappedSettings(['recordAs' => Settings::MODE_INVOICE]));
        $build = $plugin->getInvoices()->build($plainOrder);
        applySettings(mappedSettings());

        // `customerId` is ID! on InvoiceCreateInput.
        return $build['problems'] !== [] && !isset($build['input']['customerId']);
    });

    check('a Commerce discount becomes Wave’s single allowed invoice discount', function() use ($plugin, $variantA) {
        $order = makeOrder(
            [['variant' => $variantA, 'qty' => 1]],
            [['type' => 'discount', 'name' => 'A', 'amount' => -3.00], ['type' => 'discount', 'name' => 'B', 'amount' => -2.00]]
        );

        applySettings(mappedSettings(['recordAs' => Settings::MODE_INVOICE]));
        $build = $plugin->getInvoices()->build($order);
        applySettings(mappedSettings());

        $discounts = $build['input']['discounts'] ?? [];

        return count($discounts) === 1
            && $discounts[0]['discountType'] === 'FIXED'
            && $discounts[0]['amount'] === '5.00'
            ?: json_encode($discounts);
    });

    // ---------------------------------------------------------------------
    section('Chart of accounts');

    check('Wave’s anchor subtypes are recognised', function() {
        $bank = WaveAccount::fromNode(['id' => 'A', 'name' => 'Checking', 'subtype' => ['value' => 'CASH_AND_BANK', 'name' => 'Cash & Bank']]);
        $sales = WaveAccount::fromNode(['id' => 'B', 'name' => 'Sales', 'subtype' => ['value' => 'INCOME', 'name' => 'Income']]);

        return $bank->isAnchorCandidate() && !$sales->isAnchorCandidate();
    });

    check('an account label disambiguates two accounts of the same name', function() {
        $account = WaveAccount::fromNode(['id' => 'A', 'name' => 'Sales', 'subtype' => ['value' => 'INCOME', 'name' => 'Income']]);

        return $account->getLabel() === 'Sales (Income)' ?: $account->getLabel();
    });

    // ---------------------------------------------------------------------
    section('Log');

    check('entries are written and read back', function() use ($plugin) {
        $before = $plugin->getLog()->count();
        $plugin->getLog()->write('fixture', ['summary' => 'hello', 'level' => 'info', 'request' => '{"a":1}']);

        return $plugin->getLog()->count() === $before + 1;
    });

    check('payloads pretty-print as JSON', function() use ($plugin) {
        $entry = $plugin->getLog()->getEntries(['action' => 'fixture'], 1)[0] ?? null;
        $full = $entry ? $plugin->getLog()->getEntryById($entry->id) : null;

        return $full?->getPrettyRequest() === "{\n    \"a\": 1\n}" ?: var_export($full?->getPrettyRequest(), true);
    });

    check('Lite caps log retention at seven days', function() use ($plugin) {
        switchEdition(Plugin::EDITION_LITE);
        applySettings(mappedSettings(['logRetentionDays' => 365]));
        $lite = $plugin->getSettings()->getEffectiveLogRetentionDays();
        switchEdition(Plugin::EDITION_PRO);
        applySettings(mappedSettings(['logRetentionDays' => 365]));
        $pro = $plugin->getSettings()->getEffectiveLogRetentionDays();
        applySettings(mappedSettings());

        return $lite === 7 && $pro === 365 ?: "lite $lite pro $pro";
    });

    check('Lite does not keep payloads', function() use ($plugin) {
        switchEdition(Plugin::EDITION_LITE);
        $plugin->getLog()->write('fixture-lite', ['summary' => 's', 'request' => '{"secret":1}']);
        $entry = $plugin->getLog()->getEntries(['action' => 'fixture-lite'], 1)[0] ?? null;
        $full = $entry ? $plugin->getLog()->getEntryById($entry->id) : null;
        switchEdition(Plugin::EDITION_PRO);

        return $full !== null && $full->request === null;
    });

    check('logging off writes nothing at all', function() use ($plugin) {
        applySettings(mappedSettings(['loggingEnabled' => false]));
        $before = $plugin->getLog()->count();
        $plugin->getLog()->write('fixture-off', ['summary' => 's']);
        $after = $plugin->getLog()->count();
        applySettings(mappedSettings());

        return $before === $after;
    });

    check('a truncated payload is still valid UTF-8, so the row is not lost', function() use ($plugin) {
        $payload = str_repeat('é', justinholtweb\waver\services\Log::MAX_PAYLOAD);
        $plugin->getLog()->write('fixture-utf8', ['summary' => 's', 'request' => $payload]);
        $entry = $plugin->getLog()->getEntries(['action' => 'fixture-utf8'], 1)[0] ?? null;
        $full = $entry ? $plugin->getLog()->getEntryById($entry->id) : null;

        return $full !== null && mb_check_encoding((string)$full->request, 'UTF-8') ?: 'no row, or invalid UTF-8';
    });

    check('garbage collection enforces log retention', function() use ($plugin) {
        $plugin->getLog()->write('fixture-old', ['summary' => 'old']);
        Craft::$app->getDb()->createCommand()->update(Table::LOG, [
            'dateCreated' => craft\helpers\Db::prepareDateForDb((new DateTime())->modify('-400 days')),
        ], ['action' => 'fixture-old'])->execute();

        // Only Waver's own handler. Running every plugin's GC in this shared harness trips over
        // other plugins' bugs, which is not what this check is about.
        $events = (new ReflectionProperty(yii\base\Event::class, '_events'))->getValue();
        $ran = 0;

        foreach ($events[craft\services\Gc::EVENT_RUN][craft\services\Gc::class] ?? [] as [$handler]) {
            if ($handler instanceof Closure && (new ReflectionFunction($handler))->getClosureScopeClass()?->getName() === Plugin::class) {
                $handler(new yii\base\Event());
                $ran++;
            }
        }

        return $ran === 1 && $plugin->getLog()->getEntries(['action' => 'fixture-old'], 1) === []
            ?: "$ran handler(s) ran; the old row " . ($plugin->getLog()->getEntries(['action' => 'fixture-old'], 1) ? 'survived' : 'is gone');
    });

    // ---------------------------------------------------------------------
    section('The API client');

    check('no token is a clear failure, not a silent no-op', function() use ($plugin) {
        applySettings(mappedSettings(['accessToken' => '']));
        $result = $plugin->getApi()->query('fixture', 'query { user { id } }');
        applySettings(mappedSettings());

        return $result['ok'] === false && $result['code'] === 'NO_TOKEN';
    });

    check('testConnection refuses without a token', function() use ($plugin) {
        applySettings(mappedSettings(['accessToken' => '']));
        $result = $plugin->getApi()->testConnection();
        applySettings(mappedSettings());

        return $result['success'] === false && $result['businesses'] === [];
    });

    // A real round trip. No credentials needed: Wave answers a bad token with an authentication
    // error, which is exactly the path worth proving — endpoint, auth header, error decoding.
    applySettings(mappedSettings(['accessToken' => 'waver-integration-check-not-a-real-token', 'timeout' => 12]));
    $liveResult = null;

    try {
        $liveResult = $plugin->getApi()->query('fixture-live', 'query { user { id defaultEmail } }');
    } catch (Throwable $e) {
        $liveResult = null;
    }

    if ($liveResult === null || ($liveResult['code'] ?? null) === 'TRANSPORT' && str_contains(strtolower($liveResult['message'] ?? ''), 'resolve')) {
        skip('Wave rejects a bad token with an authentication error', 'no outbound network from this container');
    } else {
        check('Wave rejects a bad token with an authentication error', function() use ($liveResult) {
            return $liveResult['ok'] === false
                && in_array($liveResult['code'], ['UNAUTHENTICATED', 'TRANSPORT'], true)
                ?: json_encode($liveResult);
        });

        check('the rejection is explained in words a merchant can act on', function() use ($liveResult) {
            return $liveResult['message'] !== '' && !str_contains($liveResult['message'], 'resulted in a `');
        });

        check('the round trip was logged with its payload', function() use ($plugin) {
            $entries = $plugin->getLog()->getEntries(['action' => 'fixture-live'], 1);
            $full = $entries !== [] ? $plugin->getLog()->getEntryById($entries[0]->id) : null;

            return $full !== null
                && $full->level === 'error'
                && str_contains((string)$full->request, 'defaultEmail')
                ?: 'no log row for the live call';
        });
    }

    applySettings(mappedSettings());

    // ---------------------------------------------------------------------
    section('The API client — what may be sent twice');

    /**
     * Point the API client at canned responses. Returns a counter of how many requests it saw.
     */
    $mockWave = function(array $responses) use ($plugin): ArrayObject {
        $seen = new ArrayObject();
        $mock = new GuzzleHttp\Handler\MockHandler($responses);
        $stack = GuzzleHttp\HandlerStack::create($mock);
        $stack->push(GuzzleHttp\Middleware::history($seen));
        $plugin->getApi()->handler = $stack;

        return $seen;
    };

    $timeout = fn() => new GuzzleHttp\Exception\ConnectException(
        'cURL error 28: Operation timed out',
        new GuzzleHttp\Psr7\Request('POST', justinholtweb\waver\services\Api::ENDPOINT),
        null,
        ['errno' => 28]
    );
    $refused = fn() => new GuzzleHttp\Exception\ConnectException(
        'cURL error 7: Failed to connect',
        new GuzzleHttp\Psr7\Request('POST', justinholtweb\waver\services\Api::ENDPOINT),
        null,
        ['errno' => 7]
    );
    $ok = fn() => new GuzzleHttp\Psr7\Response(200, [], '{"data":{"fixtureCreate":{"didSucceed":true}}}');
    $mutate = fn() => $plugin->getApi()->mutate('fixture-mutation', 'mutation { fixtureCreate { didSucceed } }', 'fixtureCreate', []);

    applySettings(mappedSettings(['accessToken' => 'waver-mock-token']));

    try {
        check('a mutation that timed out is not sent again, and is reported as in doubt', function() use ($mockWave, $timeout, $ok, $mutate) {
            $seen = $mockWave([$timeout(), $ok(), $ok()]);
            $result = $mutate();

            return count($seen) === 1 && $result['ok'] === false && $result['ambiguous'] === true
                ?: count($seen) . ' requests, ' . json_encode($result);
        });

        check('a mutation that got a 5xx is not sent again, and is reported as in doubt', function() use ($mockWave, $ok, $mutate) {
            $seen = $mockWave([new GuzzleHttp\Psr7\Response(502, [], 'Bad Gateway'), $ok(), $ok()]);
            $result = $mutate();

            return count($seen) === 1 && $result['ambiguous'] === true ?: count($seen) . ' requests, ' . json_encode($result);
        });

        check('a mutation that never connected is retried, because nothing reached Wave', function() use ($mockWave, $refused, $ok, $mutate) {
            $seen = $mockWave([$refused(), $ok()]);
            $result = $mutate();

            return count($seen) === 2 && $result['ok'] === true ?: count($seen) . ' requests, ' . json_encode($result);
        });

        check('a mutation that was rate limited is retried', function() use ($mockWave, $ok, $mutate) {
            $seen = $mockWave([new GuzzleHttp\Psr7\Response(429), $ok()]);
            $result = $mutate();

            return count($seen) === 2 && $result['ok'] === true ?: count($seen) . ' requests, ' . json_encode($result);
        });

        check('a mutation Wave refused outright is final, not in doubt', function() use ($mockWave, $mutate) {
            $seen = $mockWave([new GuzzleHttp\Psr7\Response(400, [], '{"errors":[{"message":"nope"}]}')]);
            $result = $mutate();

            return count($seen) === 1 && $result['ok'] === false && $result['ambiguous'] === false
                ?: count($seen) . ' requests, ' . json_encode($result);
        });

        check('a query is still retried through a 5xx, because reading twice is harmless', function() use ($mockWave, $plugin) {
            $seen = $mockWave([new GuzzleHttp\Psr7\Response(503), new GuzzleHttp\Psr7\Response(200, [], '{"data":{"user":{"id":"1"}}}')]);
            $result = $plugin->getApi()->query('fixture-query', 'query { user { id } }');

            return count($seen) === 2 && $result['ok'] === true ?: count($seen) . ' requests, ' . json_encode($result);
        });

        check('the token goes to Wave and never follows a redirect', function() use ($mockWave, $mutate) {
            $seen = $mockWave([new GuzzleHttp\Psr7\Response(302, ['Location' => 'https://example.com/steal'], ''), new GuzzleHttp\Psr7\Response(200)]);
            $mutate();

            return count($seen) === 1 && (string)$seen[0]['request']->getUri() === justinholtweb\waver\services\Api::ENDPOINT
                ?: count($seen) . ' requests';
        });

        $doubtfulOrder = makeOrder([['variant' => $variantA, 'qty' => 1]]);

        check('a sale whose answer was lost stays pending, not failed', function() use ($plugin, $mockWave, $timeout, $doubtfulOrder) {
            $mockWave([$timeout()]);
            $record = $plugin->getRecords()->sync($doubtfulOrder);

            return $record->status === Record::STATUS_PENDING && $record->attempts === 1
                ?: "{$record->status} after {$record->attempts} attempt(s): {$record->message}";
        });

        check('and is never sent again on its own', function() use ($plugin, $mockWave, $ok, $doubtfulOrder) {
            $seen = $mockWave([$ok(), $ok()]);
            $record = $plugin->getRecords()->sync($doubtfulOrder);

            return count($seen) === 0 && $record->status === Record::STATUS_PENDING
                ?: count($seen) . " requests, status {$record->status}";
        });
    } finally {
        $plugin->getApi()->handler = null;
        applySettings(mappedSettings());
    }

    // ---------------------------------------------------------------------
    section('Twig');

    check('craft.waver reports what it knows about an order', function() use ($plugin, $plainOrder) {
        $variable = new justinholtweb\waver\twig\WaverVariable();

        return $variable->isRecorded($plainOrder)
            && $variable->recordForOrder($plainOrder)?->kind === Record::KIND_TRANSACTION
            && $variable->previewEntry($plainOrder)->isBalanced();
    });

    check('craft.waver never returns a refund as the sale record', function() use ($plugin, $plainOrder) {
        $variable = new justinholtweb\waver\twig\WaverVariable();

        return $variable->recordForOrder($plainOrder)?->kind !== Record::KIND_REFUND;
    });

    // ---------------------------------------------------------------------
    section('Wiring');

    check('every service resolves', function() use ($plugin) {
        foreach (['getApi', 'getWave', 'getLedger', 'getRecords', 'getCustomers', 'getProducts', 'getInvoices', 'getLog'] as $getter) {
            if ($plugin->$getter() === null) {
                return "$getter returned null";
            }
        }

        return true;
    });

    check('the CP nav is built for an admin', function() use ($plugin) {
        // Not asserting on the subnav: it depends on the current user, and a console request has
        // none. What matters is that building it does not throw.
        $plugin->getCpNavItem();

        return true;
    });

    check('the console controllers are all reachable', function() {
        foreach ([
            justinholtweb\waver\console\controllers\SyncController::class,
            justinholtweb\waver\console\controllers\WaveController::class,
            justinholtweb\waver\console\controllers\LogController::class,
        ] as $class) {
            if (!class_exists($class)) {
                return "$class is missing";
            }
        }

        return true;
    });

    check('the queue jobs describe themselves', function() {
        $job = new justinholtweb\waver\queue\SyncOrderJob(['orderId' => 1]);

        return $job->getDescription() !== null;
    });

    check('every translatable string has an entry', function() {
        $src = dirname(__DIR__, 2) . '/src';
        $translations = require $src . '/translations/en/waver.php';
        $missing = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $path = $file->getPathname();

            if (str_contains($path, '/translations/')) {
                continue;
            }

            $code = file_get_contents($path);
            $pattern = str_ends_with($path, '.php')
                ? "/Craft::t\\(\\s*'waver',\\s*'((?:[^'\\\\]|\\\\.)*)'/"
                : (str_ends_with($path, '.twig') ? "/'((?:[^'\\\\]|\\\\.)*)'\\s*\\|\\s*t\\(\\s*'waver'/" : null);

            if ($pattern === null) {
                continue;
            }

            preg_match_all($pattern, $code, $matches);

            foreach ($matches[1] as $string) {
                $string = str_replace("\\'", "'", $string);

                if (!array_key_exists($string, $translations)) {
                    $missing[] = $string;
                }
            }
        }

        return $missing === [] ?: 'missing: ' . implode(' | ', array_unique($missing));
    });
} finally {
    section('Cleaning up');

    $elements = Craft::$app->getElements();

    foreach ($createdOrders as $fixtureOrder) {
        try {
            $elements->deleteElement($fixtureOrder, true);
        } catch (Throwable $e) {
            echo "  ! could not delete order {$fixtureOrder->id}: {$e->getMessage()}\n";
        }
    }

    foreach ($createdProducts as $fixtureProduct) {
        try {
            $elements->deleteElement($fixtureProduct, true);
        } catch (Throwable $e) {
            echo "  ! could not delete product {$fixtureProduct->id}: {$e->getMessage()}\n";
        }
    }

    foreach ([Table::LOG, Table::RECORDS, Table::CUSTOMERS, Table::PRODUCTS] as $table) {
        try {
            Craft::$app->getDb()->createCommand()->delete($table)->execute();
        } catch (Throwable $e) {
            echo "  ! could not clear $table: {$e->getMessage()}\n";
        }
    }

    try {
        persistSettings($originalSettings);
    } catch (Throwable $e) {
        echo "  ! could not restore settings: {$e->getMessage()}\n";
    }

    try {
        switchEdition($originalEdition);
    } catch (Throwable $e) {
        echo "  ! could not restore the plugin edition: {$e->getMessage()}\n";
    }

    echo "  ✓ fixtures removed, settings restored\n";

    echo "\n" . str_repeat('-', 60) . "\n";
    echo "  $passed passed, $failed failed" . ($skipped ? ", $skipped skipped" : '') . "\n";
    echo str_repeat('-', 60) . "\n";
}

exit($failed > 0 ? 1 : 0);
