<?php

namespace justinholtweb\waver\models;

use craft\base\Model;
use craft\helpers\Json;
use DateTime;

/**
 * One row of the connection log.
 */
class LogEntry extends Model
{
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';

    public ?int $id = null;
    public string $action = '';
    public string $level = self::LEVEL_INFO;
    public ?int $statusCode = null;
    public ?int $durationMs = null;
    public ?int $orderId = null;
    public ?string $summary = null;
    public ?string $message = null;
    public ?string $request = null;
    public ?string $response = null;
    public DateTime|string|null $dateCreated = null;
    // Present because rows are hydrated straight from the table: a column with no matching
    // property makes Yii throw `Setting unknown property` the moment an entry is read back.
    public DateTime|string|null $dateUpdated = null;
    public ?string $uid = null;

    public function getLevelColor(): string
    {
        return match ($this->level) {
            self::LEVEL_ERROR => 'red',
            self::LEVEL_WARNING => 'orange',
            default => 'green',
        };
    }

    public function getPrettyRequest(): ?string
    {
        return $this->pretty($this->request);
    }

    public function getPrettyResponse(): ?string
    {
        return $this->pretty($this->response);
    }

    private function pretty(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return null;
        }

        $decoded = Json::decodeIfJson($body);

        if (!is_array($decoded)) {
            return $body;
        }

        return Json::encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
