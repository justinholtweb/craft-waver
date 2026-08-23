<?php

namespace justinholtweb\waver\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use justinholtweb\waver\models\LogEntry;
use justinholtweb\waver\Plugin;

/**
 * Wave's GraphQL API.
 *
 * One endpoint, one verb, two entirely different ways to fail — and that second one is the whole
 * reason this class exists rather than a bare Guzzle call:
 *
 * 1. **Transport and validation errors** arrive as a top-level `errors[]` with an
 *    `extensions.code`, usually alongside a non-2xx status.
 * 2. **Business-rule errors** arrive as `didSucceed: false` and an `inputErrors[]` — inside a
 *    perfectly ordinary **HTTP 200 with no `errors` key at all**. Code that checks the status
 *    code, or even checks for `errors`, records a success that never happened and leaves the
 *    merchant's books quietly short an order.
 *
 * `mutate()` therefore treats `didSucceed: false` as a failure, and every caller gets one uniform
 * result shape whichever way it went wrong.
 */
class Api extends Component
{
    public const ENDPOINT = 'https://gql.waveapps.com/graphql/public';

    /**
     * Wave publishes no rate limit, so Waver does not assume one exists. A 429 or a 5xx is
     * retried with a widening gap; anything else is final, because retrying a rejected mutation
     * just rejects it again.
     */
    public const MAX_ATTEMPTS = 3;

    /**
     * Run a query. Read-only, so a failure returns an empty result rather than throwing.
     *
     * @param array<string, mixed> $variables
     * @return array{ok: bool, data: array, message: string, code: string|null}
     */
    public function query(string $action, string $query, array $variables = []): array
    {
        return $this->send($action, $query, $variables);
    }

    /**
     * Run a mutation and unwrap Wave's output object.
     *
     * @param array<string, mixed> $input
     * @return array{ok: bool, data: array, message: string, code: string|null}
     */
    public function mutate(string $action, string $mutation, string $field, array $input, ?int $orderId = null): array
    {
        $result = $this->send($action, $mutation, ['input' => $input], $orderId);

        if (!$result['ok']) {
            return $result;
        }

        $payload = $result['data'][$field] ?? null;

        if (!is_array($payload)) {
            return [
                'ok' => false,
                'data' => [],
                'message' => Craft::t('waver', 'Wave returned no {field} result.', ['field' => $field]),
                'code' => 'EMPTY_RESULT',
            ];
        }

        // The trap: HTTP 200, no `errors`, and the mutation did nothing.
        if (($payload['didSucceed'] ?? false) !== true) {
            return [
                'ok' => false,
                'data' => $payload,
                'message' => $this->describeInputErrors($payload['inputErrors'] ?? []),
                'code' => 'INPUT_ERROR',
            ];
        }

        return [
            'ok' => true,
            'data' => $payload,
            'message' => '',
            'code' => null,
        ];
    }

    /**
     * Whether the token works, for the settings screen's "Test connection" button.
     *
     * @return array{success: bool, message: string, businesses: array<int, array{id: string, name: string}>}
     */
    public function testConnection(): array
    {
        if (!Plugin::getInstance()->getSettings()->hasCredentials()) {
            return [
                'success' => false,
                'message' => Craft::t('waver', 'No Wave access token is configured.'),
                'businesses' => [],
            ];
        }

        $result = $this->query('test', <<<'GQL'
            query {
                user { id defaultEmail }
                businesses(page: 1, pageSize: 50) {
                    edges { node { id name isArchived } }
                }
            }
            GQL);

        if (!$result['ok']) {
            return [
                'success' => false,
                'message' => $result['message'],
                'businesses' => [],
            ];
        }

        $businesses = [];

        foreach ($result['data']['businesses']['edges'] ?? [] as $edge) {
            $node = $edge['node'] ?? [];

            if (($node['isArchived'] ?? false) === true) {
                continue;
            }

            $businesses[] = [
                'id' => (string)($node['id'] ?? ''),
                'name' => (string)($node['name'] ?? ''),
            ];
        }

        return [
            'success' => true,
            'message' => Craft::t('waver', 'Connected as {email}. {count} business(es) available.', [
                'email' => $result['data']['user']['defaultEmail'] ?? '?',
                'count' => count($businesses),
            ]),
            'businesses' => $businesses,
        ];
    }

    // Private
    // =========================================================================

    /**
     * @param array<string, mixed> $variables
     * @return array{ok: bool, data: array, message: string, code: string|null}
     */
    private function send(string $action, string $query, array $variables, ?int $orderId = null): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $token = $settings->getParsedAccessToken();

        if ($token === '') {
            return [
                'ok' => false,
                'data' => [],
                'message' => Craft::t('waver', 'No Wave access token is configured.'),
                'code' => 'NO_TOKEN',
            ];
        }

        $body = ['query' => $query, 'variables' => (object)$variables];
        $encoded = Json::encode($body);
        $attempt = 0;

        while (true) {
            $attempt++;
            $started = microtime(true);

            try {
                $response = $this->client($token, $settings->timeout)->post('', ['body' => $encoded]);
                $raw = (string)$response->getBody();
                $decoded = Json::decodeIfJson($raw);

                if (!is_array($decoded)) {
                    $this->log($action, LogEntry::LEVEL_ERROR, $response->getStatusCode(), $started, 'Wave returned a non-JSON body', $encoded, $raw, $orderId);

                    return ['ok' => false, 'data' => [], 'message' => Craft::t('waver', 'Wave returned a response that was not JSON.'), 'code' => 'BAD_RESPONSE'];
                }

                if (!empty($decoded['errors'])) {
                    [$message, $code] = $this->describeErrors($decoded['errors']);
                    $this->log($action, LogEntry::LEVEL_ERROR, $response->getStatusCode(), $started, $message, $encoded, $raw, $orderId);

                    return ['ok' => false, 'data' => $decoded['data'] ?? [], 'message' => $message, 'code' => $code];
                }

                $this->log($action, LogEntry::LEVEL_INFO, $response->getStatusCode(), $started, Craft::t('waver', '{action} succeeded', ['action' => $action]), $encoded, $raw, $orderId);

                return ['ok' => true, 'data' => $decoded['data'] ?? [], 'message' => '', 'code' => null];
            } catch (\Throwable $e) {
                $status = $this->statusOf($e);
                $retryable = $status === null || $status === 429 || $status >= 500;

                if ($retryable && $attempt < self::MAX_ATTEMPTS) {
                    $this->log($action, LogEntry::LEVEL_WARNING, $status, $started, Craft::t('waver', 'Attempt {n} failed, retrying: {message}', ['n' => $attempt, 'message' => $e->getMessage()]), $encoded, $this->bodyOf($e), $orderId);
                    usleep((int)(250000 * (2 ** ($attempt - 1))));
                    continue;
                }

                $message = $this->describe($e);
                $this->log($action, LogEntry::LEVEL_ERROR, $status, $started, $message, $encoded, $this->bodyOf($e), $orderId);

                return ['ok' => false, 'data' => [], 'message' => $message, 'code' => $status === 401 ? 'UNAUTHENTICATED' : 'TRANSPORT'];
            }
        }
    }

    private function client(string $token, int $timeout): Client
    {
        return Craft::createGuzzleClient([
            'base_uri' => self::ENDPOINT,
            'timeout' => $timeout,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $errors
     * @return array{0: string, 1: string|null}
     */
    private function describeErrors(array $errors): array
    {
        $messages = [];
        $code = null;

        foreach ($errors as $error) {
            $code ??= $error['extensions']['code'] ?? null;
            $message = (string)($error['message'] ?? '');

            if ($message !== '') {
                $messages[] = $message;
            }
        }

        $message = implode(' ', $messages) ?: Craft::t('waver', 'Wave rejected the request.');

        // `UNAUTHENTICATED` reads as a Wave outage to anyone who has not met it before. It is
        // almost always a revoked or mistyped token.
        if ($code === 'UNAUTHENTICATED') {
            $message = Craft::t('waver', 'Wave rejected the access token. Check that it has not been revoked in Manage Applications.');
        }

        return [$message, $code !== null ? (string)$code : null];
    }

    /**
     * @param array<int, array<string, mixed>> $inputErrors
     */
    private function describeInputErrors(array $inputErrors): string
    {
        $messages = [];

        foreach ($inputErrors as $error) {
            $path = $error['path'] ?? null;
            $path = is_array($path) ? implode('.', $path) : (string)$path;
            $text = (string)($error['message'] ?? '');

            if ($text === '') {
                continue;
            }

            $messages[] = $path !== '' ? "{$path}: {$text}" : $text;
        }

        return implode(' ', $messages) ?: Craft::t('waver', 'Wave declined the change without saying why.');
    }

    private function statusOf(\Throwable $e): ?int
    {
        if ($e instanceof RequestException && $e->getResponse() !== null) {
            return $e->getResponse()->getStatusCode();
        }

        return null;
    }

    private function bodyOf(\Throwable $e): ?string
    {
        if ($e instanceof RequestException && $e->getResponse() !== null) {
            return (string)$e->getResponse()->getBody();
        }

        return null;
    }

    /**
     * Wave's own error text beats Guzzle's "Client error: `POST …` resulted in a `400`".
     */
    private function describe(\Throwable $e): string
    {
        $body = $this->bodyOf($e);

        if ($body !== null && $body !== '') {
            $decoded = Json::decodeIfJson($body);

            if (is_array($decoded) && !empty($decoded['errors'])) {
                return $this->describeErrors($decoded['errors'])[0];
            }
        }

        return $e->getMessage();
    }

    private function log(string $action, string $level, ?int $status, float $started, string $summary, ?string $request, ?string $response, ?int $orderId): void
    {
        Plugin::getInstance()->getLog()->write($action, [
            'level' => $level,
            'statusCode' => $status,
            'durationMs' => (int)round((microtime(true) - $started) * 1000),
            'orderId' => $orderId,
            'summary' => $summary,
            'request' => $request,
            'response' => $response,
        ]);
    }
}
