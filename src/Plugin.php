<?php

namespace justinholtweb\waver;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Order;
use craft\commerce\events\TransactionEvent;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\commerce\services\OrderHistories;
use craft\commerce\services\Transactions;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\waver\models\Settings;
use justinholtweb\waver\queue\SyncOrderJob;
use justinholtweb\waver\queue\SyncRefundJob;
use justinholtweb\waver\services\Api;
use justinholtweb\waver\services\Customers;
use justinholtweb\waver\services\Invoices;
use justinholtweb\waver\services\Ledger;
use justinholtweb\waver\services\Log;
use justinholtweb\waver\services\Products;
use justinholtweb\waver\services\Records;
use justinholtweb\waver\services\Wave;
use justinholtweb\waver\twig\WaverVariable;
use yii\base\Event;

/**
 * Waver — Wave accounting integration for Craft Commerce.
 *
 * @property-read Api $api
 * @property-read Wave $wave
 * @property-read Ledger $ledger
 * @property-read Records $records
 * @property-read Customers $customers
 * @property-read Products $products
 * @property-read Invoices $invoices
 * @property-read Log $log
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const HANDLE = 'waver';

    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public string $schemaVersion = '5.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public static function editions(): array
    {
        return [
            self::EDITION_LITE,
            self::EDITION_PRO,
        ];
    }

    /**
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                'api' => ['class' => Api::class],
                'wave' => ['class' => Wave::class],
                'ledger' => ['class' => Ledger::class],
                'records' => ['class' => Records::class],
                'customers' => ['class' => Customers::class],
                'products' => ['class' => Products::class],
                'invoices' => ['class' => Invoices::class],
                'log' => ['class' => Log::class],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $this->_registerTwigVariable();
        $this->_registerPermissions();
        $this->_registerCpRoutes();

        // The plugin can be installed while Commerce is disabled or mid-upgrade, and everything
        // below touches an order.
        if (!self::commerceIsReady()) {
            return;
        }

        $this->_registerOrderEditPanel();
        $this->_registerSyncTriggers();
    }

    public static function commerceIsReady(): bool
    {
        return class_exists(\craft\commerce\Plugin::class)
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    public function getApi(): Api
    {
        return $this->get('api');
    }

    public function getWave(): Wave
    {
        return $this->get('wave');
    }

    public function getLedger(): Ledger
    {
        return $this->get('ledger');
    }

    public function getRecords(): Records
    {
        return $this->get('records');
    }

    public function getCustomers(): Customers
    {
        return $this->get('customers');
    }

    public function getProducts(): Products
    {
        return $this->get('products');
    }

    public function getInvoices(): Invoices
    {
        return $this->get('invoices');
    }

    public function getLog(): Log
    {
        return $this->get('log');
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        $settings = $this->getSettings();
        $businessId = $settings->getBusinessIdForStore(null);

        return Craft::$app->getView()->renderTemplate('waver/settings', [
            'plugin' => $this,
            'settings' => $settings,
            'isPro' => $this->isPro(),
            // Rendered from cache; the settings screen offers a "Refresh from Wave" action rather
            // than making a fresh round trip every time somebody opens it.
            'accounts' => $businessId !== '' ? $this->getWave()->getAccounts($businessId) : [],
            'salesTaxes' => $businessId !== '' && $this->isPro() ? $this->getWave()->getSalesTaxes($businessId) : [],
            'gatewayRows' => self::mapToRows($settings->gatewayAccountMap, 'gateway', 'account'),
            'businessRows' => self::mapToRows($settings->businessMap, 'store', 'business'),
            'taxRows' => self::mapToRows($settings->taxMap, 'rate', 'tax'),
        ]);
    }

    /**
     * Craft's editable table wants a list of rows; the settings are stored as a map, because every
     * consumer wants to look a value up by key rather than scan a list.
     *
     * @param array<string, string> $map
     * @return array<int, array<string, string>>
     */
    public static function mapToRows(array $map, string $keyColumn, string $valueColumn): array
    {
        $rows = [];

        foreach ($map as $key => $value) {
            $rows[] = [$keyColumn => (string)$key, $valueColumn => (string)$value];
        }

        return $rows;
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('waver', 'Waver');

        $user = Craft::$app->getUser();
        $subNav = [];

        if ($user->checkPermission('waver-viewRecords')) {
            $subNav['records'] = [
                'label' => Craft::t('waver', 'Records'),
                'url' => 'waver/records',
            ];
        }

        if ($this->isPro() && $user->checkPermission('waver-viewLog')) {
            $subNav['log'] = [
                'label' => Craft::t('waver', 'Log'),
                'url' => 'waver/log',
            ];
        }

        if ($user->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $subNav['settings'] = [
                'label' => Craft::t('waver', 'Settings'),
                'url' => 'settings/plugins/waver',
            ];
        }

        if (!$subNav) {
            return null;
        }

        $item['subnav'] = $subNav;

        return $item;
    }

    // Private
    // =========================================================================

    private function _registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('waver', WaverVariable::class);
            }
        );
    }

    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('waver', 'Waver'),
                    'permissions' => [
                        'waver-viewRecords' => [
                            'label' => Craft::t('waver', 'View Wave records'),
                            'nested' => [
                                'waver-syncOrders' => [
                                    'label' => Craft::t('waver', 'Send orders to Wave'),
                                ],
                                // Deliberately its own permission. Forcing a resend can double an
                                // entry in a merchant's books, and marking a record as recorded
                                // asserts something about Wave that Craft cannot verify.
                                'waver-overrideRecords' => [
                                    'label' => Craft::t('waver', 'Force a resend, or mark a record as recorded by hand'),
                                ],
                            ],
                        ],
                        'waver-viewLog' => [
                            'label' => Craft::t('waver', 'View the connection log'),
                        ],
                    ],
                ];
            }
        );
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['waver'] = 'waver/records/index';
                $event->rules['waver/records'] = 'waver/records/index';
                $event->rules['waver/records/<recordId:\d+>'] = 'waver/records/detail';
                $event->rules['waver/log'] = 'waver/log/index';
                $event->rules['waver/log/<entryId:\d+>'] = 'waver/log/detail';
            }
        );
    }

    /**
     * Waver's panel on Commerce's own order edit screen.
     */
    private function _registerOrderEditPanel(): void
    {
        Craft::$app->getView()->hook('cp.commerce.order.edit.details', function(array &$context) {
            $order = $context['order'] ?? null;

            if (!$order instanceof Order || !$order->id) {
                return null;
            }

            if (!Craft::$app->getUser()->checkPermission('waver-viewRecords')) {
                return null;
            }

            $user = Craft::$app->getUser();

            return Craft::$app->getView()->renderTemplate('waver/_order-panel', [
                'order' => $order,
                'records' => $this->getRecords()->getRecordsForOrder($order->id),
                'isPro' => $this->isPro(),
                'canSync' => $user->checkPermission('waver-syncOrders'),
                'canOverride' => $user->checkPermission('waver-overrideRecords'),
            ], View::TEMPLATE_MODE_CP);
        });
    }

    private function _registerSyncTriggers(): void
    {
        $settings = $this->getSettings();

        if (!$settings->autoSync) {
            return;
        }

        if ($settings->syncTrigger === Settings::TRIGGER_COMPLETE) {
            Event::on(
                Order::class,
                Order::EVENT_AFTER_COMPLETE_ORDER,
                static function(Event $event) {
                    /** @var Order $order */
                    $order = $event->sender;
                    self::queueOrder($order->id);
                }
            );
        }

        if ($settings->syncTrigger === Settings::TRIGGER_STATUS && $this->isPro()) {
            Event::on(
                OrderHistories::class,
                OrderHistories::EVENT_ORDER_STATUS_CHANGE,
                static function($event) {
                    $order = $event->order ?? null;
                    $handles = Plugin::getInstance()->getSettings()->syncStatusHandles;

                    if (!$order instanceof Order || $handles === []) {
                        return;
                    }

                    $handle = $order->getOrderStatus()?->handle;

                    if ($handle !== null && in_array($handle, $handles, true)) {
                        self::queueOrder($order->id);
                    }
                }
            );
        }

        if ($settings->syncRefunds && $this->isPro()) {
            Event::on(
                Transactions::class,
                Transactions::EVENT_AFTER_SAVE_TRANSACTION,
                static function(TransactionEvent $event) {
                    $transaction = $event->transaction;

                    if (
                        $transaction->type !== TransactionRecord::TYPE_REFUND
                        || $transaction->status !== TransactionRecord::STATUS_SUCCESS
                        || $transaction->orderId === null
                        || $transaction->id === null
                    ) {
                        return;
                    }

                    Craft::$app->getQueue()->push(new SyncRefundJob([
                        'orderId' => $transaction->orderId,
                        'transactionId' => $transaction->id,
                    ]));
                }
            );
        }
    }

    /**
     * Queue an order, unless it is already recorded.
     *
     * The check is a courtesy that keeps the queue tidy; the unique index on `externalId` is what
     * actually makes a duplicate impossible.
     */
    private static function queueOrder(?int $orderId): void
    {
        if ($orderId === null) {
            return;
        }

        Craft::$app->getQueue()->push(new SyncOrderJob(['orderId' => $orderId]));
    }
}
