<?php

namespace justinholtweb\waver\queue;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\queue\BaseJob;
use justinholtweb\waver\Plugin;

/**
 * Record one Commerce refund in Wave, off the request.
 *
 * Same no-throw rule as `SyncOrderJob`, for the same reason.
 */
class SyncRefundJob extends BaseJob
{
    public int $orderId;

    public int $transactionId;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $this->setProgress($queue, 0);

        $order = Order::find()->id($this->orderId)->status(null)->one();
        $transaction = Commerce::getInstance()?->getTransactions()->getTransactionById($this->transactionId);

        if (!$order instanceof Order || $transaction === null) {
            Craft::warning("Waver could not load the refund {$this->transactionId} to record it.", __METHOD__);
            return;
        }

        try {
            Plugin::getInstance()->getRecords()->syncRefund($order, $transaction);
        } catch (\Throwable $e) {
            Craft::error("Waver threw while recording refund {$this->transactionId}: " . $e->getMessage(), __METHOD__);
        }

        $this->setProgress($queue, 1);
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('waver', 'Recording a refund in Wave');
    }
}
