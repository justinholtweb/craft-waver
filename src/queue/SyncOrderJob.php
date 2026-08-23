<?php

namespace justinholtweb\waver\queue;

use Craft;
use craft\commerce\elements\Order;
use craft\queue\BaseJob;
use justinholtweb\waver\models\Record;
use justinholtweb\waver\Plugin;

/**
 * Record one order in Wave, off the request.
 *
 * The job never throws. A queue job that fails is retried by Craft, and Waver's whole position on
 * retries is that an unconfirmed money transaction must not be sent again by a machine — Wave
 * cannot be asked whether the first one landed. So a failure is written onto the record, where a
 * person can see it and decide, rather than thrown, where Craft would quietly try again.
 */
class SyncOrderJob extends BaseJob
{
    public int $orderId;

    public bool $force = false;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $this->setProgress($queue, 0);

        $order = Order::find()->id($this->orderId)->status(null)->one();

        if (!$order instanceof Order) {
            Craft::warning("Waver could not load order {$this->orderId} to record it.", __METHOD__);
            return;
        }

        try {
            $record = Plugin::getInstance()->getRecords()->sync($order, $this->force);

            if ($record->status === Record::STATUS_FAILED) {
                Craft::warning("Waver could not record order {$this->orderId}: {$record->message}", __METHOD__);
            }
        } catch (\Throwable $e) {
            // Swallowed on purpose — see the class docblock.
            Craft::error("Waver threw while recording order {$this->orderId}: " . $e->getMessage(), __METHOD__);
        }

        $this->setProgress($queue, 1);
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('waver', 'Recording order {id} in Wave', ['id' => $this->orderId]);
    }
}
