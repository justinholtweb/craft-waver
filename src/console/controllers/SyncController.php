<?php

namespace justinholtweb\waver\console\controllers;

use craft\commerce\elements\Order;
use craft\console\Controller;
use craft\helpers\Console;
use DateTime;
use justinholtweb\waver\models\Record;
use justinholtweb\waver\Plugin;
use yii\console\ExitCode;

/**
 * Recording orders in Wave from the command line.
 *
 * Run as `craft waver/sync/…`.
 */
class SyncController extends Controller
{
    /**
     * Send again even though Waver already holds a record. Dangerous by design — see `order`.
     */
    public bool $force = false;

    /**
     * Show what would be sent without sending it.
     */
    public bool $dryRun = false;

    /**
     * How many orders to work through.
     */
    public int $limit = 50;

    /**
     * Only consider orders placed on or after this date (`Y-m-d`).
     */
    public ?string $since = null;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return match ($actionID) {
            'order' => array_merge(parent::options($actionID), ['force', 'dryRun']),
            'backfill', 'retry' => array_merge(parent::options($actionID), ['limit', 'since', 'dryRun']),
            default => parent::options($actionID),
        };
    }

    /**
     * Record one order.
     *
     * `--force` sends it again even if Waver already has a record. Wave cannot be asked whether a
     * money transaction landed, so this can genuinely double an entry in the merchant's books.
     * Only use it after looking at Wave.
     */
    public function actionOrder(int $orderId): int
    {
        $order = Order::find()->id($orderId)->status(null)->one();

        if (!$order instanceof Order) {
            $this->stderr("No order with id {$orderId}.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        if ($this->dryRun) {
            return $this->preview($order);
        }

        $record = Plugin::getInstance()->getRecords()->sync($order, $this->force);
        $this->report($order, $record);

        return $record->isSynced() ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Record every completed order Waver has never considered, newest first.
     */
    public function actionBackfill(): int
    {
        try {
            $since = $this->since !== null ? new DateTime($this->since) : null;
        } catch (\Exception) {
            $this->stderr("--since is not a date Waver can read: {$this->since}\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $orderIds = Plugin::getInstance()->getRecords()->getUnrecordedOrderIds($this->limit, $since);

        if ($orderIds === []) {
            $this->stdout("Nothing to backfill.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $this->stdout(count($orderIds) . " order(s) with no Wave record.\n\n");

        $failed = 0;

        foreach ($orderIds as $orderId) {
            $order = Order::find()->id($orderId)->status(null)->one();

            if (!$order instanceof Order) {
                continue;
            }

            if ($this->dryRun) {
                $this->preview($order);
                continue;
            }

            $record = Plugin::getInstance()->getRecords()->sync($order);
            $this->report($order, $record);

            if ($record->status === Record::STATUS_FAILED) {
                $failed++;
            }
        }

        return $failed > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Try the failed records again.
     *
     * Only records that are actually `failed` — a `pending` one that was already attempted is left
     * alone, because Wave cannot be asked whether it landed and re-sending it might double it.
     */
    public function actionRetry(): int
    {
        $records = Plugin::getInstance()->getRecords()->getRecords(['status' => Record::STATUS_FAILED], $this->limit);

        if ($records === []) {
            $this->stdout("No failed records.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $failed = 0;

        foreach ($records as $record) {
            $order = $record->orderId ? Order::find()->id($record->orderId)->status(null)->one() : null;

            if (!$order instanceof Order) {
                continue;
            }

            if ($this->dryRun) {
                $this->preview($order);
                continue;
            }

            // `force` because a failed record already exists; the sync would otherwise stop at it.
            $result = Plugin::getInstance()->getRecords()->sync($order, true);
            $this->report($order, $result);

            if (!$result->isSynced()) {
                $failed++;
            }
        }

        return $failed > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Everything Waver knows about one order.
     */
    public function actionStatus(int $orderId): int
    {
        $records = Plugin::getInstance()->getRecords()->getRecordsForOrder($orderId);

        if ($records === []) {
            $this->stdout("No Waver records for order {$orderId}.\n");

            return ExitCode::OK;
        }

        foreach ($records as $record) {
            $this->stdout(sprintf(
                "%-12s %-9s %-40s %s\n",
                $record->kind,
                $record->status,
                $record->externalId,
                $record->waveId ?? '—'
            ));

            if ($record->message) {
                $this->stdout('             ' . $record->message . "\n", Console::FG_GREY);
            }
        }

        return ExitCode::OK;
    }

    // Private
    // =========================================================================

    private function preview(Order $order): int
    {
        $entry = Plugin::getInstance()->getLedger()->buildEntry($order);
        $reference = $order->reference ?? $order->getShortNumber();

        $this->stdout("Order {$reference} — {$entry->description}\n", Console::FG_CYAN);
        $this->stdout(sprintf("  anchor %s %s %s\n", $entry->direction, number_format($entry->anchorAmount, 2), $entry->anchorAccountId ?: '(unmapped)'));

        foreach ($entry->toRows() as $row) {
            $this->stdout(sprintf(
                "  %-12s %-14s dr %-10s cr %-10s %s\n",
                $row['role'],
                mb_substr($row['account'], 0, 14),
                $row['debit'] ?? '',
                $row['credit'] ?? '',
                $row['description'] ?? ''
            ));
        }

        $skipReasons = Plugin::getInstance()->getLedger()->getSkipReasons($order);

        foreach ($skipReasons as $reason) {
            $this->stdout("  skip: {$reason}\n", Console::FG_YELLOW);
        }

        foreach ($entry->blockers() as $blocker) {
            $this->stdout("  blocked: {$blocker}\n", Console::FG_RED);
        }

        // A balanced entry on an order that would be skipped is not "ready to send", and saying so
        // is how a dry run stops being reassuring about the wrong thing.
        if (!$entry->isSendable()) {
            $this->stdout("  not sendable\n\n", Console::FG_RED);
        } elseif ($skipReasons !== []) {
            $this->stdout("  balances, but this order would be skipped\n\n", Console::FG_YELLOW);
        } elseif ($entry->isSendable()) {
            $this->stdout("  balanced and ready to send\n\n", Console::FG_GREEN);
        } else {
            $this->stdout("  not sendable\n\n", Console::FG_RED);
        }

        return ExitCode::OK;
    }

    private function report(Order $order, Record $record): void
    {
        $colour = match ($record->status) {
            Record::STATUS_SYNCED => Console::FG_GREEN,
            Record::STATUS_FAILED => Console::FG_RED,
            Record::STATUS_SKIPPED => Console::FG_GREY,
            default => Console::FG_YELLOW,
        };

        $this->stdout(sprintf("%-10s order %-10s %s\n", $record->status, $order->id, $record->waveId ?? ''), $colour);

        if ($record->message) {
            $this->stdout('           ' . $record->message . "\n", Console::FG_GREY);
        }
    }
}
