<?php

namespace justinholtweb\waver\models;

use craft\base\Model;
use craft\helpers\Json;
use DateTime;

/**
 * What Waver knows about one thing it has recorded, or tried to record, in Wave.
 */
class Record extends Model
{
    public const KIND_TRANSACTION = 'transaction';
    public const KIND_INVOICE = 'invoice';
    public const KIND_REFUND = 'refund';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SYNCED = 'synced';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public ?int $id = null;
    public ?int $orderId = null;
    public ?int $storeId = null;
    public string $kind = self::KIND_TRANSACTION;
    public string $externalId = '';
    public string $status = self::STATUS_PENDING;
    public ?string $waveId = null;
    public ?string $businessId = null;
    public ?float $amount = null;
    public ?string $currency = null;
    public ?int $sourceTransactionId = null;
    public ?string $invoiceNumber = null;
    public ?string $viewUrl = null;
    public ?string $pdfUrl = null;
    public ?string $payload = null;
    public ?string $message = null;
    public int $attempts = 0;
    public DateTime|string|null $dateSynced = null;
    public DateTime|string|null $dateCreated = null;
    // Present because rows are hydrated straight from the table: a column with no matching
    // property makes Yii throw `Setting unknown property` the moment a record is read back.
    public DateTime|string|null $dateUpdated = null;
    public ?string $uid = null;

    public function isSynced(): bool
    {
        return $this->status === self::STATUS_SYNCED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDecodedPayload(): ?array
    {
        if ($this->payload === null || $this->payload === '') {
            return null;
        }

        $decoded = Json::decodeIfJson($this->payload);

        return is_array($decoded) ? $decoded : null;
    }

    public function getPrettyPayload(): string
    {
        $decoded = $this->getDecodedPayload();

        if ($decoded === null) {
            return (string)$this->payload;
        }

        return Json::encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The badge colour Craft's CP uses for this status.
     */
    public function getStatusColor(): string
    {
        return match ($this->status) {
            self::STATUS_SYNCED => 'green',
            self::STATUS_FAILED => 'red',
            self::STATUS_SKIPPED => 'gray',
            default => 'orange',
        };
    }
}
