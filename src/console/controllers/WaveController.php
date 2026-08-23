<?php

namespace justinholtweb\waver\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\waver\Plugin;
use yii\console\ExitCode;

/**
 * Looking at the Wave end of the connection from the command line.
 *
 * Run as `craft waver/wave/…`.
 */
class WaveController extends Controller
{
    /**
     * Re-read from Wave rather than using the cached lists.
     */
    public bool $flush = false;

    /**
     * Only show accounts Wave will accept as a transaction anchor.
     */
    public bool $anchors = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return match ($actionID) {
            'accounts' => array_merge(parent::options($actionID), ['flush', 'anchors']),
            'taxes' => array_merge(parent::options($actionID), ['flush']),
            default => parent::options($actionID),
        };
    }

    /**
     * Check the token and show what it reaches.
     */
    public function actionCheck(): int
    {
        $result = Plugin::getInstance()->getApi()->testConnection();

        if (!$result['success']) {
            $this->stderr($result['message'] . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout($result['message'] . "\n\n", Console::FG_GREEN);

        foreach ($result['businesses'] as $business) {
            $this->stdout("  {$business['name']}\n");
            $this->stdout("    {$business['id']}\n", Console::FG_GREY);
        }

        $businessId = Plugin::getInstance()->getSettings()->getBusinessIdForStore(null);

        if ($businessId === '') {
            $this->stdout("\nNo business is selected in the settings yet.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $info = Plugin::getInstance()->getWave()->describeBusiness($businessId);

        if (!$info['ok']) {
            $this->stderr("\n" . $info['message'] . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\nSelected: {$info['name']} ({$info['currency']})\n", Console::FG_CYAN);

        if ($info['isClassicAccounting']) {
            $this->stdout("  ! classic accounting — moneyTransactionCreate will not work here\n", Console::FG_RED);
        }

        if (!$info['emailSendEnabled']) {
            $this->stdout("  ! email sending is off — Wave cannot email invoices for this business\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * List the business's chart of accounts, with the ids the settings need.
     */
    public function actionAccounts(): int
    {
        $businessId = Plugin::getInstance()->getSettings()->getBusinessIdForStore(null);

        if ($businessId === '') {
            $this->stderr("No Wave business is selected.\n", Console::FG_RED);

            return ExitCode::CONFIG;
        }

        $accounts = Plugin::getInstance()->getWave()->getAccounts($businessId, $this->flush);

        if ($accounts === []) {
            $this->stderr("No accounts came back. Check the connection with waver/wave/check.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        foreach ($accounts as $account) {
            if ($this->anchors && !$account->isAnchorCandidate()) {
                continue;
            }

            $this->stdout(sprintf("%-30s %-24s %s\n", mb_substr($account->name, 0, 30), $account->subtype, $account->id));
        }

        return ExitCode::OK;
    }

    /**
     * List the business's sales taxes, for the tax map.
     */
    public function actionTaxes(): int
    {
        $businessId = Plugin::getInstance()->getSettings()->getBusinessIdForStore(null);

        if ($businessId === '') {
            $this->stderr("No Wave business is selected.\n", Console::FG_RED);

            return ExitCode::CONFIG;
        }

        foreach (Plugin::getInstance()->getWave()->getSalesTaxes($businessId, $this->flush) as $tax) {
            $this->stdout(sprintf("%-30s %-8s %-8s %s\n", mb_substr($tax['name'], 0, 30), $tax['abbreviation'], $tax['rate'], $tax['id']));
        }

        return ExitCode::OK;
    }
}
