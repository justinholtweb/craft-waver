<?php

namespace justinholtweb\waver\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\waver\models\Record;
use justinholtweb\waver\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Records screens.
 */
class RecordsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('waver-viewRecords');

        return true;
    }

    public function actionIndex(): Response
    {
        $status = $this->request->getParam('status');
        $criteria = $status !== null && $status !== '' ? ['status' => $status] : [];

        $records = Plugin::getInstance()->getRecords()->getRecords($criteria, 200);
        $orderIds = array_values(array_filter(array_map(static fn(Record $r) => $r->orderId, $records)));

        return $this->renderTemplate('waver/records/_index', [
            'records' => $records,
            'orders' => $this->indexOrders($orderIds),
            'counts' => Plugin::getInstance()->getRecords()->getStatusCounts(),
            'currentStatus' => $status,
            'isPro' => Plugin::getInstance()->isPro(),
        ]);
    }

    public function actionDetail(int $recordId): Response
    {
        $record = Plugin::getInstance()->getRecords()->getRecordById($recordId);

        if ($record === null) {
            throw new NotFoundHttpException('Record not found');
        }

        $order = $record->orderId ? Order::find()->id($record->orderId)->status(null)->one() : null;

        return $this->renderTemplate('waver/records/_detail', [
            'record' => $record,
            'order' => $order,
            'isPro' => Plugin::getInstance()->isPro(),
            'canSync' => Craft::$app->getUser()->checkPermission('waver-syncOrders'),
            'canOverride' => Craft::$app->getUser()->checkPermission('waver-overrideRecords'),
        ]);
    }

    /**
     * Build the entry for an order and show it without sending anything.
     *
     * Goes through the same `Ledger::buildEntry()` the real sync uses, so a preview that balances
     * is a sync that balances.
     */
    public function actionPreview(): Response
    {
        $this->requireAcceptsJson();

        $order = $this->orderFromRequest();
        $entry = Plugin::getInstance()->getLedger()->buildEntry($order);

        return $this->asJson([
            'success' => true,
            'sendable' => $entry->isSendable(),
            'blockers' => $entry->blockers(),
            'skipReasons' => Plugin::getInstance()->getLedger()->getSkipReasons($order),
            'rows' => $entry->toRows(),
            'drift' => $entry->drift(),
            'payload' => $entry->toWaveInput(),
        ]);
    }

    public function actionSync(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('waver-syncOrders');

        $order = $this->orderFromRequest();
        $force = (bool)$this->request->getBodyParam('force');

        if ($force) {
            // Sending an order Wave may already have is how books get doubled.
            $this->requirePermission('waver-overrideRecords');
        }

        $record = Plugin::getInstance()->getRecords()->sync($order, $force);

        return $this->respond($record, $record->isSynced()
            ? Craft::t('waver', 'Recorded in Wave.')
            : ($record->message ?: Craft::t('waver', 'Not recorded.')), $record->isSynced());
    }

    /**
     * Ask Wave what happened to a record nobody is sure about.
     */
    public function actionResolve(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('waver-syncOrders');

        $record = $this->recordFromRequest();
        $result = Plugin::getInstance()->getRecords()->resolveDoubtful($record);

        return $this->respond($record, $result['message'], $result['resolved']);
    }

    public function actionMarkRecorded(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('waver-overrideRecords');

        $record = $this->recordFromRequest();
        $waveId = $this->request->getBodyParam('waveId');

        Plugin::getInstance()->getRecords()->markRecorded($record, is_string($waveId) ? trim($waveId) : null);

        return $this->respond($record, Craft::t('waver', 'Marked as recorded.'), true);
    }

    /**
     * Forget a record so the order can be considered again.
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('waver-overrideRecords');

        $record = $this->recordFromRequest();
        Plugin::getInstance()->getRecords()->deleteRecord($record);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true]);
        }

        $this->setSuccessFlash(Craft::t('waver', 'Record deleted. Nothing was removed from Wave.'));

        return $this->redirect(UrlHelper::cpUrl('waver/records'));
    }

    // Private
    // =========================================================================

    private function orderFromRequest(): Order
    {
        $orderId = (int)($this->request->getBodyParam('orderId') ?? $this->request->getQueryParam('orderId'));
        $order = $orderId ? Order::find()->id($orderId)->status(null)->one() : null;

        if (!$order instanceof Order) {
            throw new NotFoundHttpException('Order not found');
        }

        return $order;
    }

    private function recordFromRequest(): Record
    {
        $recordId = (int)$this->request->getBodyParam('recordId');
        $record = $recordId ? Plugin::getInstance()->getRecords()->getRecordById($recordId) : null;

        if ($record === null) {
            throw new NotFoundHttpException('Record not found');
        }

        return $record;
    }

    private function respond(Record $record, string $message, bool $success): Response
    {
        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => $success,
                'message' => $message,
                'status' => $record->status,
                'waveId' => $record->waveId,
                'viewUrl' => $record->viewUrl,
            ]);
        }

        $success ? $this->setSuccessFlash($message) : $this->setFailFlash($message);

        return $this->redirectToPostedUrl();
    }

    /**
     * @param int[] $orderIds
     * @return array<int, Order>
     */
    private function indexOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $orders = [];

        foreach (Order::find()->id($orderIds)->status(null)->limit(null)->all() as $order) {
            $orders[$order->id] = $order;
        }

        return $orders;
    }
}
