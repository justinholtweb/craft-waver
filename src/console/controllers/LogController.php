<?php

namespace justinholtweb\waver\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\waver\Plugin;
use yii\console\ExitCode;

/**
 * The connection log from the command line. Run as `craft waver/log/…`.
 */
class LogController extends Controller
{
    /**
     * Days to keep. Defaults to the configured retention.
     */
    public ?int $days = null;

    /**
     * Only show entries at this level (`info`, `warning`, `error`).
     */
    public ?string $level = null;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return match ($actionID) {
            'prune' => array_merge(parent::options($actionID), ['days']),
            'tail' => array_merge(parent::options($actionID), ['level']),
            default => parent::options($actionID),
        };
    }

    /**
     * Delete entries older than the retention window.
     */
    public function actionPrune(): int
    {
        $deleted = Plugin::getInstance()->getLog()->prune($this->days);

        $this->stdout("{$deleted} log entries deleted.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * The most recent entries.
     */
    public function actionTail(int $limit = 20): int
    {
        $criteria = $this->level !== null ? ['level' => $this->level] : [];

        foreach (array_reverse(Plugin::getInstance()->getLog()->getEntries($criteria, $limit)) as $entry) {
            $date = $entry->dateCreated instanceof \DateTime
                ? $entry->dateCreated->format('Y-m-d H:i:s')
                : (string)$entry->dateCreated;

            $this->stdout(sprintf(
                "%s  %-8s %-24s %s\n",
                $date,
                $entry->level,
                $entry->action,
                (string)$entry->summary
            ), match ($entry->level) {
                'error' => Console::FG_RED,
                'warning' => Console::FG_YELLOW,
                default => Console::FG_GREY,
            });
        }

        return ExitCode::OK;
    }

    /**
     * Empty the log.
     */
    public function actionClear(): int
    {
        $deleted = Plugin::getInstance()->getLog()->clear();

        $this->stdout("{$deleted} log entries deleted.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
