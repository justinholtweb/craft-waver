<?php

namespace justinholtweb\waver\twig;

use craft\commerce\elements\Order;
use justinholtweb\waver\models\Entry;
use justinholtweb\waver\models\Record;
use justinholtweb\waver\Plugin;
use yii\base\Behavior;

/**
 * `craft.waver` — read-only. Nothing here posts to Wave: a template render is not a place from
 * which a merchant's books should change.
 */
class WaverVariable extends Behavior
{
    /**
     * Every record Waver holds for an order.
     *
     * @return Record[]
     */
    public function recordsForOrder(Order|int|null $order): array
    {
        $orderId = $order instanceof Order ? $order->id : $order;

        return $orderId ? Plugin::getInstance()->getRecords()->getRecordsForOrder((int)$orderId) : [];
    }

    /**
     * The sale record for an order, if there is one.
     */
    public function recordForOrder(Order|int|null $order): ?Record
    {
        foreach ($this->recordsForOrder($order) as $record) {
            if ($record->kind !== Record::KIND_REFUND) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Whether an order has made it into Wave.
     */
    public function isRecorded(Order|int|null $order): bool
    {
        return $this->recordForOrder($order)?->isSynced() ?? false;
    }

    /**
     * The entry an order *would* produce, without sending it — for building your own preview.
     */
    public function previewEntry(Order $order): Entry
    {
        return Plugin::getInstance()->getLedger()->buildEntry($order);
    }

    /**
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        return Plugin::getInstance()->getRecords()->getStatusCounts();
    }
}
