<?php

namespace justinholtweb\waver\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\Transaction;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\waver\db\Table;
use justinholtweb\waver\models\Record;
use justinholtweb\waver\Plugin;
use yii\db\IntegrityException;

/**
 * The only place a Waver record is created, and the only place an order is sent to Wave.
 *
 * ## Why this is the single door
 *
 * **Wave's API cannot read a money transaction back.** There is no `transactions` field on
 * `Business`, and the `Transaction` object exposes exactly one field — `id`. Nothing can be looked
 * up by `externalId`, by date, or by amount. Once `moneyTransactionCreate` returns, the only
 * evidence in the world that Waver posted it is the row in `{{%waver_records}}`.
 *
 * Everything below follows from that:
 *
 * - The row is written **before** the mutation, not after. A process that dies mid-call leaves a
 *   `pending` row with `attempts = 1` — a question — rather than nothing at all.
 * - The unique index on `externalId` is what makes a duplicate impossible rather than unlikely.
 *   Two queue workers racing the same order do not both get to send; one takes an integrity error
 *   and stands down.
 * - A `pending` row that has already been attempted is **never retried automatically**. It might
 *   be a failed call, or it might be a posted transaction whose response was lost, and no amount
 *   of code can tell the two apart. Auto-retrying it would double a merchant's revenue in the one
 *   scenario that cannot be detected afterwards. It is surfaced for a human instead.
 *
 * Invoices are the exception: they *can* be read back by number, so a doubtful invoice record can
 * resolve itself. `resolveDoubtful()` does that, and says plainly when it cannot.
 */
class Records extends Component
{
    /**
     * Record an order in Wave.
     *
     * @param bool $force Send again even though a record already exists. Only ever set by a human
     *                    who has looked at Wave and knows what is there.
     */
    public function sync(Order $order, bool $force = false): Record
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $mode = $settings->getEffectiveMode();
        $kind = $mode === $settings::MODE_INVOICE ? Record::KIND_INVOICE : Record::KIND_TRANSACTION;
        $externalId = $plugin->getLedger()->externalIdForOrder($order);

        $existing = $this->getRecordByExternalId($externalId);

        if ($existing !== null && !$force) {
            if ($existing->isSynced()) {
                return $existing;
            }

            // Already attempted and we never heard back. Nobody but a person who has looked at
            // Wave can safely say what happened.
            if ($existing->status === Record::STATUS_PENDING && $existing->attempts > 0) {
                return $this->update($existing, [
                    'message' => Craft::t('waver', 'A previous attempt did not report back. Waver will not send this again on its own, because Wave cannot be asked whether the transaction landed. Check Wave, then use “Force resend” or “Mark as recorded”.'),
                ]);
            }
        }

        $skipReasons = $plugin->getLedger()->getSkipReasons($order);

        if ($skipReasons !== []) {
            $record = $this->upsert($order, $kind, $externalId, [
                'status' => Record::STATUS_SKIPPED,
                'message' => implode(' ', $skipReasons),
            ]);

            return $record ?? $this->getRecordByExternalId($externalId) ?? new Record(['externalId' => $externalId, 'status' => Record::STATUS_SKIPPED]);
        }

        return $mode === $settings::MODE_INVOICE
            ? $this->syncAsInvoice($order, $externalId)
            : $this->syncAsTransaction($order, $externalId);
    }

    /**
     * Record a successful Commerce refund as its own withdrawal.
     */
    public function syncRefund(Order $order, Transaction $transaction, bool $force = false): Record
    {
        $plugin = Plugin::getInstance();
        $externalId = $plugin->getLedger()->externalIdForRefund($order, $transaction);
        $existing = $this->getRecordByExternalId($externalId);

        if ($existing !== null && !$force && ($existing->isSynced() || $existing->attempts > 0)) {
            return $existing;
        }

        $entry = $plugin->getLedger()->buildRefundEntry($order, $transaction);

        $record = $this->upsert($order, Record::KIND_REFUND, $externalId, [
            'status' => Record::STATUS_PENDING,
            'businessId' => $entry->businessId,
            'amount' => $entry->anchorAmount,
            'currency' => $order->currency,
            'sourceTransactionId' => $transaction->id,
            'payload' => Json::encode($entry->toWaveInput()),
            'message' => null,
        ]);

        if ($record === null) {
            return $this->getRecordByExternalId($externalId) ?? new Record(['externalId' => $externalId, 'status' => Record::STATUS_PENDING]);
        }

        if (!$entry->isSendable()) {
            return $this->update($record, [
                'status' => Record::STATUS_FAILED,
                'message' => implode(' ', $entry->blockers()),
            ]);
        }

        $record = $this->update($record, ['attempts' => $record->attempts + 1]);
        $result = $plugin->getWave()->createMoneyTransaction($entry, $order->id);

        return $this->update($record, $result['ok']
            ? ['status' => Record::STATUS_SYNCED, 'waveId' => $result['id'], 'message' => null, 'dateSynced' => Db::prepareDateForDb(new DateTime())]
            : $this->failure($result));
    }

    /**
     * Try to work out what happened to a record that was attempted but never confirmed.
     *
     * Honest about its limits: an invoice can be found again by its number, a money transaction
     * cannot be found at all.
     *
     * @return array{resolved: bool, message: string}
     */
    public function resolveDoubtful(Record $record): array
    {
        if ($record->kind !== Record::KIND_INVOICE) {
            return [
                'resolved' => false,
                'message' => Craft::t('waver', 'Wave has no way to look a money transaction up — there is no transactions query, and the Transaction type exposes only an id. Open Wave, search the date and amount, then mark this record accordingly.'),
            ];
        }

        if ($record->invoiceNumber === null || $record->invoiceNumber === '' || $record->businessId === null) {
            return [
                'resolved' => false,
                'message' => Craft::t('waver', 'This record has no invoice number to search Wave for.'),
            ];
        }

        $result = Plugin::getInstance()->getApi()->query('invoiceLookup', <<<'GQL'
            query($businessId: ID!, $invoiceNumber: String) {
                business(id: $businessId) {
                    invoices(page: 1, pageSize: 10, sort: [CREATED_AT_DESC], invoiceNumber: $invoiceNumber) {
                        edges { node { id invoiceNumber viewUrl pdfUrl status } }
                    }
                }
            }
            GQL, ['businessId' => $record->businessId, 'invoiceNumber' => $record->invoiceNumber]);

        foreach ($result['data']['business']['invoices']['edges'] ?? [] as $edge) {
            $node = $edge['node'] ?? [];

            // Wave's `invoiceNumber` filter is a *contains* match — its own documentation warns
            // that searching `12` finds `112` and `120` too. Only an exact hit counts.
            if ((string)($node['invoiceNumber'] ?? '') !== $record->invoiceNumber) {
                continue;
            }

            $this->update($record, [
                'status' => Record::STATUS_SYNCED,
                'waveId' => (string)($node['id'] ?? ''),
                'viewUrl' => (string)($node['viewUrl'] ?? ''),
                'pdfUrl' => (string)($node['pdfUrl'] ?? ''),
                'message' => Craft::t('waver', 'Found in Wave and reconciled.'),
                'dateSynced' => Db::prepareDateForDb(new DateTime()),
            ]);

            return ['resolved' => true, 'message' => Craft::t('waver', 'The invoice exists in Wave. This record now points at it.')];
        }

        return [
            'resolved' => false,
            'message' => Craft::t('waver', 'No invoice with that number exists in Wave. It is safe to send again.'),
        ];
    }

    /**
     * Accept a merchant's word that a doubtful record did land, without sending anything.
     */
    public function markRecorded(Record $record, ?string $waveId = null): Record
    {
        return $this->update($record, [
            'status' => Record::STATUS_SYNCED,
            'waveId' => $waveId !== null && $waveId !== '' ? $waveId : $record->waveId,
            'message' => Craft::t('waver', 'Marked as recorded by hand.'),
            'dateSynced' => Db::prepareDateForDb(new DateTime()),
        ]);
    }

    // Reading
    // =========================================================================

    /**
     * @return Record[]
     */
    public function getRecordsForOrder(int $orderId): array
    {
        return array_map(
            static fn(array $row) => new Record($row),
            (new Query())->from([Table::RECORDS])->where(['orderId' => $orderId])->orderBy(['id' => SORT_ASC])->all()
        );
    }

    public function getRecordById(int $id): ?Record
    {
        $row = (new Query())->from([Table::RECORDS])->where(['id' => $id])->one();

        return $row ? new Record($row) : null;
    }

    public function getRecordByExternalId(string $externalId): ?Record
    {
        $row = (new Query())->from([Table::RECORDS])->where(['externalId' => $externalId])->one();

        return $row ? new Record($row) : null;
    }

    /**
     * @return Record[]
     */
    public function getRecords(array $criteria = [], int $limit = 100, int $offset = 0): array
    {
        return array_map(
            static fn(array $row) => new Record($row),
            $this->recordQuery($criteria)->limit($limit)->offset($offset)->all()
        );
    }

    public function countRecords(array $criteria = []): int
    {
        return (int)$this->recordQuery($criteria)->count();
    }

    /**
     * How many records sit in each status, for the index screen's tab counts.
     *
     * @return array<string, int>
     */
    public function getStatusCounts(): array
    {
        $counts = array_fill_keys(
            [Record::STATUS_PENDING, Record::STATUS_SYNCED, Record::STATUS_FAILED, Record::STATUS_SKIPPED],
            0
        );

        $rows = (new Query())
            ->select(['status', 'total' => 'COUNT(*)'])
            ->from([Table::RECORDS])
            ->groupBy(['status'])
            ->all();

        foreach ($rows as $row) {
            $counts[$row['status']] = (int)$row['total'];
        }

        return $counts;
    }

    /**
     * Completed orders with no record at all, newest first — what a backfill would pick up.
     *
     * @return int[]
     */
    public function getUnrecordedOrderIds(int $limit = 100, ?DateTime $since = null): array
    {
        $query = Order::find()
            ->isCompleted(true)
            ->orderBy(['commerce_orders.dateOrdered' => SORT_DESC])
            ->limit($limit);

        if ($since !== null) {
            $query->dateOrdered('>= ' . $since->format('Y-m-d'));
        }

        $orderIds = array_map('intval', $query->ids());

        if ($orderIds === []) {
            return [];
        }

        // A record in any state other than "absent" means this order has already been considered.
        $handled = (new Query())
            ->select(['orderId'])
            ->from([Table::RECORDS])
            ->where(['orderId' => $orderIds])
            ->andWhere(['kind' => [Record::KIND_TRANSACTION, Record::KIND_INVOICE]])
            ->column();

        return array_values(array_diff($orderIds, array_map('intval', $handled)));
    }

    public function deleteRecord(Record $record): bool
    {
        if ($record->id === null) {
            return false;
        }

        return (bool)Craft::$app->getDb()->createCommand()->delete(Table::RECORDS, ['id' => $record->id])->execute();
    }

    // Private
    // =========================================================================

    private function syncAsTransaction(Order $order, string $externalId): Record
    {
        $plugin = Plugin::getInstance();
        $entry = $plugin->getLedger()->buildEntry($order);

        $record = $this->upsert($order, Record::KIND_TRANSACTION, $externalId, [
            'status' => Record::STATUS_PENDING,
            'businessId' => $entry->businessId,
            'amount' => $entry->anchorAmount,
            'currency' => $order->currency,
            'payload' => Json::encode($entry->toWaveInput()),
            'message' => null,
        ]);

        if ($record === null) {
            // Another worker claimed this order between the lookup and the insert. Theirs wins.
            return $this->getRecordByExternalId($externalId) ?? new Record(['externalId' => $externalId, 'status' => Record::STATUS_PENDING]);
        }

        if (!$entry->isSendable()) {
            return $this->update($record, [
                'status' => Record::STATUS_FAILED,
                'message' => implode(' ', $entry->blockers()),
            ]);
        }

        // Counted before the call, not after: an attempt that never returns still happened.
        $record = $this->update($record, ['attempts' => $record->attempts + 1]);
        $result = $plugin->getWave()->createMoneyTransaction($entry, $order->id);

        return $this->update($record, $result['ok']
            ? ['status' => Record::STATUS_SYNCED, 'waveId' => $result['id'], 'message' => null, 'dateSynced' => Db::prepareDateForDb(new DateTime())]
            : $this->failure($result));
    }

    private function syncAsInvoice(Order $order, string $externalId): Record
    {
        $plugin = Plugin::getInstance();
        $build = $plugin->getInvoices()->build($order);

        $record = $this->upsert($order, Record::KIND_INVOICE, $externalId, [
            'status' => Record::STATUS_PENDING,
            'businessId' => (string)($build['input']['businessId'] ?? ''),
            'amount' => (float)$order->getTotalPrice(),
            'currency' => $order->currency,
            'invoiceNumber' => $build['input']['invoiceNumber'] ?? null,
            'payload' => Json::encode($build['input']),
            'message' => null,
        ]);

        if ($record === null) {
            return $this->getRecordByExternalId($externalId) ?? new Record(['externalId' => $externalId, 'status' => Record::STATUS_PENDING]);
        }

        if ($build['problems'] !== []) {
            return $this->update($record, [
                'status' => Record::STATUS_FAILED,
                'message' => implode(' ', $build['problems']),
            ]);
        }

        $record = $this->update($record, ['attempts' => $record->attempts + 1]);
        $result = $plugin->getInvoices()->send($order, $build['input']);

        return $this->update($record, $result['ok']
            ? [
                'status' => Record::STATUS_SYNCED,
                'waveId' => $result['id'],
                'invoiceNumber' => $result['number'] !== '' ? $result['number'] : $record->invoiceNumber,
                'viewUrl' => $result['viewUrl'],
                'pdfUrl' => $result['pdfUrl'],
                'message' => $result['message'] !== '' ? $result['message'] : null,
                'dateSynced' => Db::prepareDateForDb(new DateTime()),
            ]
            : [
                ...$this->failure($result),
                // The invoice may exist even though a later step failed. Keeping its id is what
                // lets the merchant find it instead of creating a second one.
                'waveId' => $result['id'] !== '' ? $result['id'] : null,
                'invoiceNumber' => $result['number'] !== '' ? $result['number'] : $record->invoiceNumber,
                'viewUrl' => $result['viewUrl'] !== '' ? $result['viewUrl'] : null,
            ]);
    }

    /**
     * What a failed send leaves on its record.
     *
     * A failure Wave might nonetheless have acted on — a timeout, a dropped connection, a 5xx —
     * stays `pending`. Marking it `failed` would make it eligible to be sent again, and that is
     * the one retry that can double a merchant's books without anybody being able to tell.
     *
     * @param array{message: string, ambiguous?: bool} $result
     * @return array<string, mixed>
     */
    private function failure(array $result): array
    {
        if ($result['ambiguous'] ?? false) {
            return [
                'status' => Record::STATUS_PENDING,
                'message' => Craft::t('waver', 'Waver sent this but never got a clear answer ({message}). It may or may not be in Wave, so it will not be sent again on its own. Check Wave, then use “Force resend” or “Mark as recorded”.', [
                    'message' => $result['message'],
                ]),
            ];
        }

        return ['status' => Record::STATUS_FAILED, 'message' => $result['message']];
    }

    /**
     * Insert or update the row for an external id.
     *
     * @param array<string, mixed> $attributes
     * @return Record|null Null when another process won the race to insert.
     */
    private function upsert(Order $order, string $kind, string $externalId, array $attributes): ?Record
    {
        $existing = $this->getRecordByExternalId($externalId);

        if ($existing !== null) {
            return $this->update($existing, $attributes);
        }

        $now = Db::prepareDateForDb(new DateTime());

        $row = array_merge([
            'orderId' => $order->id,
            'storeId' => $order->storeId ?? null,
            'kind' => $kind,
            'externalId' => $externalId,
            'status' => Record::STATUS_PENDING,
            'attempts' => 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ], $attributes);

        try {
            Craft::$app->getDb()->createCommand()->insert(Table::RECORDS, $row)->execute();
        } catch (IntegrityException) {
            // The unique index on externalId did its job.
            return null;
        }

        return $this->getRecordByExternalId($externalId);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function update(Record $record, array $attributes): Record
    {
        if ($record->id === null) {
            return $record;
        }

        $attributes['dateUpdated'] = Db::prepareDateForDb(new DateTime());

        Craft::$app->getDb()->createCommand()->update(Table::RECORDS, $attributes, ['id' => $record->id])->execute();

        return $this->getRecordById($record->id) ?? $record;
    }

    private function recordQuery(array $criteria): Query
    {
        $query = (new Query())
            ->from([Table::RECORDS])
            ->orderBy(['id' => SORT_DESC]);

        foreach (['status', 'kind', 'orderId'] as $key) {
            if (!empty($criteria[$key])) {
                $query->andWhere([$key => $criteria[$key]]);
            }
        }

        return $query;
    }
}
