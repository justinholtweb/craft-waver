<?php

namespace justinholtweb\waver\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\waver\db\Table;
use justinholtweb\waver\models\LogEntry;
use justinholtweb\waver\Plugin;

/**
 * The connection log.
 *
 * An accounting integration that silently records nothing is otherwise impossible to diagnose:
 * Wave shows an empty ledger, Craft shows a completed order, and neither says why. Every request
 * Waver sends lands here with its payload and Wave's answer.
 */
class Log extends Component
{
    /**
     * Payload bodies over this are truncated. Nobody reads past the first screen of a rejected
     * mutation, and a products page can run to megabytes.
     */
    public const MAX_PAYLOAD = 65535;

    /**
     * @param array{
     *     level?: string,
     *     statusCode?: int|null,
     *     durationMs?: int|null,
     *     orderId?: int|null,
     *     summary?: string|null,
     *     message?: string|null,
     *     request?: string|null,
     *     response?: string|null,
     * } $data
     */
    public function write(string $action, array $data = []): void
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null || !$plugin->getSettings()->loggingEnabled) {
            return;
        }

        $keepPayloads = $plugin->getSettings()->logPayloads && $plugin->isPro();

        try {
            Craft::$app->getDb()->createCommand()->insert(Table::LOG, [
                'action' => $action,
                'level' => $data['level'] ?? LogEntry::LEVEL_INFO,
                'statusCode' => $data['statusCode'] ?? null,
                'durationMs' => $data['durationMs'] ?? null,
                'orderId' => $data['orderId'] ?? null,
                'summary' => isset($data['summary']) ? mb_substr((string)$data['summary'], 0, 255) : null,
                'message' => $data['message'] ?? null,
                'request' => $keepPayloads ? $this->truncate($data['request'] ?? null) : null,
                'response' => $keepPayloads ? $this->truncate($data['response'] ?? null) : null,
                'dateCreated' => Db::prepareDateForDb(new DateTime()),
                'dateUpdated' => Db::prepareDateForDb(new DateTime()),
                'uid' => StringHelper::UUID(),
            ])->execute();
        } catch (\Throwable $e) {
            // The log is diagnostics, never the point. Failing to write it must not take down the
            // sync it was describing.
            Craft::warning('Waver could not write a log entry: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * @return LogEntry[]
     */
    public function getEntries(array $criteria = [], int $limit = 100): array
    {
        $query = (new Query())
            ->select(['id', 'action', 'level', 'statusCode', 'durationMs', 'orderId', 'summary', 'message', 'dateCreated', 'uid'])
            ->from([Table::LOG])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit);

        if (!empty($criteria['action'])) {
            $query->andWhere(['action' => $criteria['action']]);
        }

        if (!empty($criteria['level'])) {
            $query->andWhere(['level' => $criteria['level']]);
        }

        if (!empty($criteria['orderId'])) {
            $query->andWhere(['orderId' => $criteria['orderId']]);
        }

        return array_map(static fn(array $row) => new LogEntry($row), $query->all());
    }

    public function getEntryById(int $id): ?LogEntry
    {
        $row = (new Query())->from([Table::LOG])->where(['id' => $id])->one();

        return $row ? new LogEntry($row) : null;
    }

    /**
     * Drop entries older than the configured retention. Returns the number deleted.
     */
    public function prune(?int $days = null): int
    {
        $days ??= Plugin::getInstance()->getSettings()->getEffectiveLogRetentionDays();

        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new DateTime())->modify("-{$days} days");

        return (int)Craft::$app->getDb()->createCommand()->delete(Table::LOG, [
            '<', 'dateCreated', Db::prepareDateForDb($cutoff),
        ])->execute();
    }

    public function clear(): int
    {
        return (int)Craft::$app->getDb()->createCommand()->delete(Table::LOG)->execute();
    }

    public function count(): int
    {
        return (int)(new Query())->from([Table::LOG])->count();
    }

    private function truncate(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        if (strlen($payload) <= self::MAX_PAYLOAD) {
            return $payload;
        }

        return substr($payload, 0, self::MAX_PAYLOAD) . "\n…[truncated]";
    }
}
