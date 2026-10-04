<?php

namespace justinholtweb\waver\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\waver\Plugin;
use yii\web\Response;

/**
 * The settings screen's live actions: proving the connection works before a single order depends
 * on it.
 */
class SettingsController extends Controller
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
        $this->requireAdmin();

        return true;
    }

    /**
     * Check the token and list the businesses it reaches.
     */
    public function actionTest(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        return $this->asJson(Plugin::getInstance()->getApi()->testConnection());
    }

    /**
     * Everything about the selected business that decides whether Waver can work at all.
     */
    public function actionCheckBusiness(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $businessId = trim((string)$this->request->getBodyParam('businessId'));
        $info = Plugin::getInstance()->getWave()->describeBusiness($businessId);

        $warnings = [];

        if ($info['ok'] && $info['isClassicAccounting']) {
            // Wave states this outright on the mutation: it "Requires `isClassicAccounting` to be
            // `false`". Better said here than as a rejected mutation on the merchant's first sale.
            $warnings[] = Craft::t('waver', 'This business is on Wave’s classic accounting, and moneyTransactionCreate does not work there. Transaction mode will fail until the business is migrated; invoice mode is unaffected.');
        }

        if ($info['ok'] && !$info['emailSendEnabled']) {
            $warnings[] = Craft::t('waver', 'This business cannot send email from Wave, so “Email the invoice” will not work.');
        }

        return $this->asJson([
            'success' => $info['ok'],
            'message' => $info['ok']
                ? Craft::t('waver', '{name} — books kept in {currency}.', ['name' => $info['name'], 'currency' => $info['currency']])
                : $info['message'],
            'warnings' => $warnings,
        ]);
    }

    /**
     * Drop the cached chart of accounts and tax list.
     */
    public function actionRefresh(): Response
    {
        $this->requirePostRequest();

        $settings = Plugin::getInstance()->getSettings();
        $businessId = $settings->getBusinessIdForStore(null);

        $accounts = Plugin::getInstance()->getWave()->getAccounts($businessId, true);
        Plugin::getInstance()->getWave()->getSalesTaxes($businessId, true);

        return $this->done(Craft::t('waver', '{count} accounts loaded from Wave.', ['count' => count($accounts)]));
    }

    /**
     * Forget the customer and product maps.
     */
    public function actionClearMaps(): Response
    {
        $this->requirePostRequest();

        $customers = Plugin::getInstance()->getCustomers()->clearMap();
        $products = Plugin::getInstance()->getProducts()->clearMap();

        return $this->done(Craft::t('waver', '{customers} customer and {products} product mappings cleared. Nothing was deleted from Wave.', [
            'customers' => $customers,
            'products' => $products,
        ]));
    }

    /**
     * The settings screen posts these over XHR. Answering with a redirect would need a hashed
     * `redirect` param, and Craft rejects an unhashed one with a 400 before the action reports.
     */
    private function done(string $message): Response
    {
        $this->setSuccessFlash($message);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'message' => $message]);
        }

        return $this->redirectToPostedUrl();
    }
}
