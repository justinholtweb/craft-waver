<?php

namespace justinholtweb\waver\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use justinholtweb\waver\Plugin;

/**
 * Waver settings.
 *
 * Nothing here is ever marked `required`. Craft validates plugin settings wholesale, so a single
 * required field makes the settings screen unsaveable on a fresh install — before the merchant has
 * had any chance to paste a Wave token in.
 */
class Settings extends Model
{
    public const MODE_TRANSACTION = 'transaction';
    public const MODE_INVOICE = 'invoice';

    public const TRIGGER_COMPLETE = 'complete';
    public const TRIGGER_STATUS = 'status';
    public const TRIGGER_MANUAL = 'manual';

    // Connection
    // -------------------------------------------------------------------------

    /**
     * Wave access token, sent as `Authorization: Bearer …`. Env-parseable, and it should be an
     * env var: a full access token reaches every business on the Wave account, not just this one.
     */
    public string $accessToken = '';

    /**
     * The Wave business orders are recorded against.
     */
    public string $businessId = '';

    /**
     * Commerce store id => Wave business id, for merchants running more than one store out of one
     * Wave account (Pro). Falls back to `businessId`.
     *
     * Backed by a private property because Craft's editable table posts a *list of rows*, not a
     * map, and every consumer here wants to look a value up by key.
     *
     * @var array<string, string>
     */
    private array $_businessMap = [];

    /**
     * Seconds to wait on Wave before giving up.
     */
    public int $timeout = 20;

    // What gets recorded
    // -------------------------------------------------------------------------

    /**
     * `transaction` posts one balanced money transaction per order. `invoice` creates a Wave
     * invoice document instead (Pro).
     */
    public string $recordAs = self::MODE_TRANSACTION;

    public bool $autoSync = true;

    /**
     * `complete` records the order the moment Commerce completes it; `status` waits for it to
     * reach one of `syncStatusHandles` (Pro); `manual` only ever records on request.
     */
    public string $syncTrigger = self::TRIGGER_COMPLETE;

    /**
     * @var string[]
     */
    public array $syncStatusHandles = [];

    /**
     * Refuse to post an order that has not actually been paid in full. On by default: a money
     * transaction says cash arrived, and an order with an outstanding balance is an order where
     * it did not.
     */
    public bool $requireFullyPaid = true;

    /**
     * Object template rendered against the order for the transaction description.
     */
    public string $descriptionTemplate = 'Order {{ object.reference ?? object.shortNumber }}';

    /**
     * The largest residual Waver will absorb onto the rounding account. Beyond this, the entry is
     * not rounding-off, it is wrong, and it is refused instead.
     */
    public float $roundingTolerance = 0.05;

    // Chart of accounts
    // -------------------------------------------------------------------------

    /**
     * The anchor: the bank, clearing or credit-card account the money landed in.
     */
    public string $paymentAccountId = '';

    public string $salesAccountId = '';
    public string $shippingAccountId = '';
    public string $taxAccountId = '';
    public string $discountAccountId = '';
    public string $feeAccountId = '';
    public string $otherIncomeAccountId = '';

    /**
     * Where a sub-cent residual goes. Defaults to the sales account when blank.
     */
    public string $roundingAccountId = '';

    /**
     * Which income account a refund is taken back out of. Defaults to the sales account.
     */
    public string $refundAccountId = '';

    /**
     * Gateway-specific payment accounts, keyed by Commerce gateway handle (Pro). A Stripe order
     * and a cheque should not land in the same place.
     *
     * @var array<string, string>
     */
    private array $_gatewayAccountMap = [];

    // Customers
    // -------------------------------------------------------------------------

    /**
     * Create a Wave customer for an order that has an email Wave does not know.
     */
    public bool $createCustomers = true;

    /**
     * Attach the customer to the transaction's income line, so Wave's customer reports see it.
     */
    public bool $attachCustomerToLines = true;

    // Invoice mode (Pro)
    // -------------------------------------------------------------------------

    /**
     * `SAVED` or `DRAFT`. A draft is invisible in Wave's reports until it is approved.
     */
    public string $invoiceStatus = 'SAVED';

    public bool $approveInvoice = true;

    /**
     * Record the payment against the invoice so it does not sit in Wave as unpaid.
     */
    public bool $markInvoicePaid = true;

    /**
     * `auto` maps from the Commerce gateway; otherwise a fixed `InvoicePaymentMethod`.
     */
    public string $invoicePaymentMethod = 'auto';

    public bool $sendInvoice = false;

    /**
     * `reference`, `number`, `shortNumber`, `id`, or `wave` to let Wave number it.
     */
    public string $invoiceNumberSource = 'reference';

    public string $invoiceTitle = '';
    public string $invoiceMemoTemplate = '';
    public string $invoiceFooter = '';

    /**
     * Create a Wave product for a purchasable Wave has never seen. Wave has no free-text invoice
     * line — `productId` is required on every one — so with this off, an unmapped SKU is a
     * blocker rather than a line.
     */
    public bool $createProducts = true;

    /**
     * Income account assigned to products Waver creates. Falls back to the sales account.
     */
    public string $productIncomeAccountId = '';

    /**
     * Commerce tax rate name => Wave sales tax id (Pro).
     *
     * @var array<string, string>
     */
    private array $_taxMap = [];

    // Refunds (Pro)
    // -------------------------------------------------------------------------

    /**
     * Record a successful Commerce refund as its own withdrawal in Wave.
     */
    public bool $syncRefunds = true;

    // Logging
    // -------------------------------------------------------------------------

    public bool $loggingEnabled = true;

    /**
     * Keep request and response bodies on log rows (Pro).
     */
    public bool $logPayloads = true;

    /**
     * Days of log history to keep. 0 keeps everything.
     */
    public int $logRetentionDays = 30;

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['timeout'], 'integer', 'min' => 1, 'max' => 120],
            [['logRetentionDays'], 'integer', 'min' => 0],
            [['roundingTolerance'], 'number', 'min' => 0, 'max' => 5],
            [['recordAs'], 'in', 'range' => [self::MODE_TRANSACTION, self::MODE_INVOICE]],
            [['syncTrigger'], 'in', 'range' => [self::TRIGGER_COMPLETE, self::TRIGGER_STATUS, self::TRIGGER_MANUAL]],
            [['invoiceStatus'], 'in', 'range' => ['SAVED', 'DRAFT']],
            [['invoiceNumberSource'], 'in', 'range' => ['reference', 'number', 'shortNumber', 'id', 'wave']],
            [['accessToken', 'businessId'], 'string'],
            [
                [
                    'businessMap', 'syncStatusHandles', 'gatewayAccountMap', 'taxMap',
                    'descriptionTemplate', 'invoiceTitle', 'invoiceMemoTemplate', 'invoiceFooter',
                ],
                'safe',
            ],
        ];
    }

    public function setBusinessMap(mixed $value): void
    {
        $this->_businessMap = self::normalizeMap($value, 'store', 'business');
    }

    /**
     * @return array<string, string>
     */
    public function getBusinessMap(): array
    {
        return $this->_businessMap;
    }

    public function setGatewayAccountMap(mixed $value): void
    {
        $this->_gatewayAccountMap = self::normalizeMap($value, 'gateway', 'account');
    }

    /**
     * @return array<string, string>
     */
    public function getGatewayAccountMap(): array
    {
        return $this->_gatewayAccountMap;
    }

    public function setTaxMap(mixed $value): void
    {
        $this->_taxMap = self::normalizeMap($value, 'rate', 'tax');
    }

    /**
     * @return array<string, string>
     */
    public function getTaxMap(): array
    {
        return $this->_taxMap;
    }

    /**
     * Accept either an already-keyed map (what project config holds) or Craft's editable-table
     * row format, `[['gateway' => 'stripe', 'account' => 'x'], …]` (what the settings screen
     * posts). Normalising here keeps every consumer reading one shape.
     *
     * @return array<string, string>
     */
    private static function normalizeMap(mixed $value, string $keyColumn, string $valueColumn): array
    {
        if (!is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $row) {
            if (is_array($row)) {
                $rowKey = trim((string)($row[$keyColumn] ?? ''));
                $rowValue = trim((string)($row[$valueColumn] ?? ''));
            } else {
                $rowKey = trim((string)$key);
                $rowValue = trim((string)$row);
            }

            if ($rowKey === '' || $rowValue === '') {
                continue;
            }

            $map[$rowKey] = $rowValue;
        }

        return $map;
    }

    public function getParsedAccessToken(): string
    {
        return trim((string)App::parseEnv($this->accessToken));
    }

    public function hasCredentials(): bool
    {
        return $this->getParsedAccessToken() !== '';
    }

    /**
     * The Wave business a given Commerce store records into.
     */
    public function getBusinessIdForStore(?int $storeId): string
    {
        if ($storeId !== null && Plugin::getInstance()?->isPro()) {
            $mapped = trim((string)($this->businessMap[$storeId] ?? ''));

            if ($mapped !== '') {
                return $mapped;
            }
        }

        return trim((string)App::parseEnv($this->businessId));
    }

    /**
     * The anchor account for an order paid through a given gateway.
     */
    public function getPaymentAccountId(?string $gatewayHandle = null): string
    {
        if ($gatewayHandle !== null && Plugin::getInstance()?->isPro()) {
            $mapped = trim((string)($this->gatewayAccountMap[$gatewayHandle] ?? ''));

            if ($mapped !== '') {
                return $mapped;
            }
        }

        return trim($this->paymentAccountId);
    }

    /**
     * Rounding falls back to the sales account: a cent of residual has to land somewhere, and
     * silently dropping it would unbalance the entry Wave then rejects.
     */
    public function getRoundingAccountId(): string
    {
        return trim($this->roundingAccountId) ?: trim($this->salesAccountId);
    }

    public function getRefundAccountId(): string
    {
        return trim($this->refundAccountId) ?: trim($this->salesAccountId);
    }

    public function getProductIncomeAccountId(): string
    {
        return trim($this->productIncomeAccountId) ?: trim($this->salesAccountId);
    }

    /**
     * Invoice mode is Pro. An unlicensed install falls back to transactions rather than silently
     * recording nothing.
     */
    public function getEffectiveMode(): string
    {
        if ($this->recordAs === self::MODE_INVOICE && !Plugin::getInstance()?->isPro()) {
            return self::MODE_TRANSACTION;
        }

        return $this->recordAs;
    }

    public function getEffectiveLogRetentionDays(): int
    {
        if (!Plugin::getInstance()?->isPro()) {
            return 7;
        }

        return $this->logRetentionDays;
    }

    /**
     * @inheritdoc
     *
     * The three maps are backed by private properties, so Yii does not see them as attributes at
     * all — and Craft persists plugin settings by iterating attributes. Without this they save as
     * empty on every write, silently.
     */
    public function attributes(): array
    {
        return array_merge(parent::attributes(), ['businessMap', 'gatewayAccountMap', 'taxMap']);
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            'accessToken' => Craft::t('waver', 'Access token'),
            'businessId' => Craft::t('waver', 'Business'),
            'paymentAccountId' => Craft::t('waver', 'Payment account'),
            'salesAccountId' => Craft::t('waver', 'Sales account'),
            'taxAccountId' => Craft::t('waver', 'Sales tax account'),
            'roundingTolerance' => Craft::t('waver', 'Rounding tolerance'),
        ];
    }
}
