<?php

namespace justinholtweb\waver\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\waver\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The connection log screens (Pro).
 */
class LogController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission('waver-viewLog');

        if (!Plugin::getInstance()->isPro()) {
            throw new ForbiddenHttpException('The connection log is a Waver Pro feature.');
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $criteria = array_filter([
            'level' => $this->request->getParam('level'),
            'action' => $this->request->getParam('action'),
        ]);

        return $this->renderTemplate('waver/log/_index', [
            'entries' => Plugin::getInstance()->getLog()->getEntries($criteria, 200),
            'total' => Plugin::getInstance()->getLog()->count(),
            'currentLevel' => $this->request->getParam('level'),
        ]);
    }

    public function actionDetail(int $entryId): Response
    {
        $entry = Plugin::getInstance()->getLog()->getEntryById($entryId);

        if ($entry === null) {
            throw new NotFoundHttpException('Log entry not found');
        }

        return $this->renderTemplate('waver/log/_detail', ['entry' => $entry]);
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();

        $deleted = Plugin::getInstance()->getLog()->clear();

        $message = Craft::t('waver', '{count} log entries deleted.', ['count' => $deleted]);
        $this->setSuccessFlash($message);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'message' => $message]);
        }

        return $this->redirect(UrlHelper::cpUrl('waver/log'));
    }
}
